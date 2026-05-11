<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Integration;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Perfbase\Yii3\Middleware\PerfbaseHttpMiddleware;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HttpMiddlewareTest extends TestCase
{
    public function test_success_path_profiles_request(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client);
        $request = (new ServerRequest('GET', 'https://example.com/articles/42?token=secret'))
            ->withAttribute('routePattern', '/articles/{id}')
            ->withAttribute('routeName', 'app_article')
            ->withAttribute('controller', 'App\\Controller\\ArticleController')
            ->withAttribute('userId', 'user-123');

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(202);
            }
        });

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('GET /articles/{id}', $client->attributes['action']);
        self::assertSame('https://example.com/articles/42', $client->attributes['http_url']);
        self::assertSame('202', $client->attributes['http_status_code']);
        self::assertSame('user-123', $client->attributes['user_id']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_disallowed_status_code_is_not_submitted_by_default(): void
    {
        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client);
        $request = (new ServerRequest('GET', 'https://example.com/missing'))
            ->withAttribute('routePattern', '/missing');

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(404);
            }
        });

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('404', $client->attributes['http_status_code']);
        self::assertSame(0, $client->submitCalls);
        self::assertSame(1, $client->resetCalls);
    }

    public function test_server_error_status_code_is_submitted_by_default(): void
    {
        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client);
        $request = (new ServerRequest('GET', 'https://example.com/error'))
            ->withAttribute('routePattern', '/error');

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(503);
            }
        });

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('503', $client->attributes['http_status_code']);
        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
    }

    public function test_custom_allowed_status_code_is_submitted(): void
    {
        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client, [
            'profile_http_status_codes' => [200, 404],
        ]);
        $request = (new ServerRequest('GET', 'https://example.com/missing'))
            ->withAttribute('routePattern', '/missing');

        $response = $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(404);
            }
        });

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
    }

    public function test_exception_path_still_cleans_up(): void
    {
        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client);
        $request = (new ServerRequest('GET', 'https://example.com/articles/42'))
            ->withAttribute('routePattern', '/articles/{id}');

        $this->expectException(\RuntimeException::class);

        try {
            $middleware->process($request, new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    throw new \RuntimeException('boom');
                }
            });
        } finally {
            self::assertSame('boom', $client->attributes['exception']);
            self::assertSame(1, $client->submitCalls);
        }
    }

    public function test_disabled_config_results_in_no_profiling(): void
    {
        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client, [
            'enabled' => false,
        ]);

        $middleware->process(new ServerRequest('GET', 'https://example.com/health'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(200);
            }
        });

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeMiddleware(RecordingPerfbaseClient $client, array $config = []): PerfbaseHttpMiddleware
    {
        return new PerfbaseHttpMiddleware(
            array_replace_recursive([
                'enabled' => true,
                'sample_rate' => 1.0,
                'profile_http_status_codes' => [...range(200, 299), ...range(500, 599)],
                'include' => ['http' => ['*']],
                'exclude' => ['http' => []],
                'app_version' => '1.2.3',
            ], $config),
            new PerfbaseClientProvider(
                [
                    'api_key' => 'test-key',
                    'api_url' => 'https://receiver.perfbase.local',
                    'flags' => 0,
                    'timeout' => 10,
                    'proxy' => null,
                ],
                new PerfbaseErrorHandler(false, false),
                static function () use ($client) {
                    return $client;
                }
            ),
            new PerfbaseErrorHandler(false, false),
            'test',
            '1.2.3'
        );
    }
}
