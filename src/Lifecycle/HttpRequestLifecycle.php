<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Lifecycle;

use Perfbase\SDK\Utils\EnvironmentUtils;
use Perfbase\SDK\Utils\UrlSanitizer;
use Perfbase\Yii3\Profiling\AbstractProfiler;
use Perfbase\Yii3\Support\ConfigValue;
use Perfbase\Yii3\Support\FilterMatcher;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Support\SpanNaming;
use Psr\Http\Message\ServerRequestInterface;

class HttpRequestLifecycle extends AbstractProfiler
{
    private ?int $responseStatusCode = null;

    public function __construct(
        private ServerRequestInterface $request,
        private ?string $routeTemplate,
        private ?string $routeName,
        private ?string $controller,
        PerfbaseClientProvider $clientProvider,
        PerfbaseErrorHandler $errorHandler,
        array $config,
        string $environment,
        string $appVersion
    ) {
        parent::__construct(
            SpanNaming::forHttp($request, $routeTemplate, $routeName),
            $clientProvider,
            $errorHandler,
            $config,
            $environment,
            $appVersion
        );
    }

    public function setResponseStatusCode(int $statusCode): void
    {
        $this->responseStatusCode = $statusCode;
        $this->setAttribute('http_status_code', (string) $statusCode);
    }

    protected function shouldProfile(): bool
    {
        if (!ConfigValue::bool($this->config['enabled'] ?? null, false)) {
            return false;
        }

        return FilterMatcher::passesFilters(
            $this->getRequestComponents(),
            $this->normalizeFilters($this->config['include']['http'] ?? ['*']),
            $this->normalizeFilters($this->config['exclude']['http'] ?? [])
        );
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $routeTarget = $this->routeTemplate ?: ($this->routeName ?: $this->getRequestPath());

        $this->setAttributes([
            'source' => 'http',
            'action' => sprintf('%s %s', $this->request->getMethod(), $routeTarget),
            'http_method' => $this->request->getMethod(),
            'http_url' => $this->buildHttpUrl(),
            'user_ip' => $this->resolveUserIp(),
            'user_agent' => $this->resolveUserAgent(),
        ]);

        $userIdentifier = $this->resolveUserIdentifier();
        if ($userIdentifier !== null && $userIdentifier !== '') {
            $this->setAttribute('user_id', $userIdentifier);
        }
    }

    protected function shouldSubmitTrace(): bool
    {
        if ($this->responseStatusCode === null) {
            return true;
        }

        return in_array($this->responseStatusCode, $this->profileHttpStatusCodes(), true);
    }

    /**
     * @return array<int, string>
     */
    private function getRequestComponents(): array
    {
        $path = $this->getRequestPath();
        $components = [
            $path,
            sprintf('%s %s', $this->request->getMethod(), $path),
        ];

        if ($this->routeTemplate !== null && $this->routeTemplate !== '') {
            $components[] = $this->routeTemplate;
            $components[] = sprintf('%s %s', $this->request->getMethod(), $this->routeTemplate);
        }

        if ($this->routeName !== null && $this->routeName !== '') {
            $components[] = $this->routeName;
        }

        if ($this->controller !== null && $this->controller !== '') {
            $components[] = $this->controller;
        }

        return array_values(array_unique($components));
    }

    private function getRequestPath(): string
    {
        $path = $this->request->getUri()->getPath();

        return $path === '' ? '/' : $path;
    }

    /**
     * Updates the grouping action once the router has matched (the route is
     * usually only known after the inner handler ran).
     */
    public function setRouteTemplate(string $routeTemplate): void
    {
        if ($routeTemplate === '') {
            return;
        }

        $this->routeTemplate = str_starts_with($routeTemplate, '/') ? $routeTemplate : '/' . $routeTemplate;
        $this->setAttribute('action', sprintf('%s %s', $this->request->getMethod(), $this->routeTemplate));
    }

    /**
     * Full request URL including the query string. Credential-looking query
     * values are redacted by the SDK sanitizer; `action` stays query-free.
     */
    private function buildHttpUrl(): string
    {
        $uri = $this->request->getUri();
        $authority = $uri->getAuthority();
        $scheme = $uri->getScheme();

        $url = $authority === '' ? '' : $scheme . '://' . $authority;
        $url .= $uri->getPath() === '' ? '/' : $uri->getPath();

        $query = $uri->getQuery();
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return UrlSanitizer::sanitize($url);
    }

    /**
     * Prefers $_SERVER (which honours proxy headers), falling back to the PSR-7
     * server params for workers (RoadRunner, Swoole) where $_SERVER is not per request.
     */
    private function resolveUserIp(): string
    {
        $ip = EnvironmentUtils::getUserIp();
        if (is_string($ip) && $ip !== '') {
            return $ip;
        }

        $remoteAddress = $this->request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remoteAddress) ? $remoteAddress : '';
    }

    private function resolveUserAgent(): string
    {
        $userAgent = $this->request->getHeaderLine('User-Agent');
        if ($userAgent !== '') {
            return $userAgent;
        }

        return (string) (EnvironmentUtils::getUserUserAgent() ?? '');
    }

    private function resolveUserIdentifier(): ?string
    {
        foreach (['userId', 'user_id'] as $attribute) {
            $value = $this->request->getAttribute($attribute);
            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        foreach (['identity', 'user'] as $attribute) {
            $value = $this->request->getAttribute($attribute);
            if (is_object($value)) {
                foreach (['getId', 'getUserIdentifier'] as $method) {
                    if (method_exists($value, $method)) {
                        $identifier = $value->$method();
                        if (is_scalar($identifier) && $identifier !== '') {
                            return (string) $identifier;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $filters
     * @return array<int, string>
     */
    private function normalizeFilters($filters): array
    {
        if (!is_array($filters)) {
            return [];
        }

        return array_values(array_filter($filters, static function ($filter): bool {
            return is_string($filter) && $filter !== '';
        }));
    }

    /**
     * @return array<int, int>
     */
    private function profileHttpStatusCodes(): array
    {
        $statusCodes = $this->config['profile_http_status_codes'] ?? [...range(200, 299), ...range(500, 599)];
        if (!is_array($statusCodes)) {
            return [...range(200, 299), ...range(500, 599)];
        }

        $normalized = [];

        foreach ($statusCodes as $statusCode) {
            if (is_int($statusCode)) {
                $normalized[] = $statusCode;
                continue;
            }

            if (is_string($statusCode) && ctype_digit($statusCode)) {
                $normalized[] = (int) $statusCode;
            }
        }

        return array_values(array_unique($normalized));
    }
}
