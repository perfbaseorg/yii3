<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Lifecycle;

use Perfbase\SDK\Utils\EnvironmentUtils;
use Perfbase\Yii3\Profiling\AbstractProfiler;
use Perfbase\Yii3\Support\FilterMatcher;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Support\SpanNaming;
use Psr\Http\Message\ServerRequestInterface;

class HttpRequestLifecycle extends AbstractProfiler
{
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
        $this->setAttribute('http_status_code', (string) $statusCode);
    }

    protected function shouldProfile(): bool
    {
        if (!(bool) ($this->config['enabled'] ?? false)) {
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
            'http_url' => $this->buildUrlWithoutQuery(),
            'user_ip' => (string) (EnvironmentUtils::getUserIp() ?? ''),
            'user_agent' => (string) (EnvironmentUtils::getUserUserAgent() ?? ''),
        ]);

        $userIdentifier = $this->resolveUserIdentifier();
        if ($userIdentifier !== null && $userIdentifier !== '') {
            $this->setAttribute('user_id', $userIdentifier);
        }
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

    private function buildUrlWithoutQuery(): string
    {
        $uri = $this->request->getUri();
        $authority = $uri->getAuthority();
        $scheme = $uri->getScheme();

        $base = $authority === '' ? '' : $scheme . '://' . $authority;

        return $base . ($uri->getPath() === '' ? '/' : $uri->getPath());
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
}
