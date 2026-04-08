<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Middleware;

use Perfbase\Yii3\Lifecycle\HttpRequestLifecycle;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PerfbaseHttpMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private PerfbaseClientProvider $clientProvider,
        private PerfbaseErrorHandler $errorHandler,
        private string $environment = 'production',
        private string $appVersion = ''
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $lifecycle = new HttpRequestLifecycle(
            $request,
            $this->resolveRouteTemplate($request),
            $this->resolveRouteName($request),
            $this->resolveController($request),
            $this->clientProvider,
            $this->errorHandler,
            $this->config,
            $this->environment,
            $this->appVersion
        );

        $lifecycle->startProfiling();

        try {
            $response = $handler->handle($request);
            $lifecycle->setResponseStatusCode($response->getStatusCode());

            return $response;
        } catch (\Throwable $throwable) {
            $lifecycle->setException($throwable);
            throw $throwable;
        } finally {
            $lifecycle->stopProfiling();
        }
    }

    private function resolveRouteTemplate(ServerRequestInterface $request): ?string
    {
        foreach (['routePattern', 'route', '_route'] as $attribute) {
            $value = $request->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return str_starts_with($value, '/') ? $value : '/' . ltrim($value, '/');
            }
        }

        return null;
    }

    private function resolveRouteName(ServerRequestInterface $request): ?string
    {
        foreach (['routeName', 'route_name'] as $attribute) {
            $value = $request->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveController(ServerRequestInterface $request): ?string
    {
        foreach (['controller', 'action'] as $attribute) {
            $value = $request->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_object($value)) {
                return get_class($value);
            }
        }

        return null;
    }
}
