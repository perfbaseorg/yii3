<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Integration;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Perfbase\SDK\Exception\PerfbaseException;
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
        self::assertSame('https://example.com/articles/42?token=REDACTED', $client->attributes['http_url']);
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

    public function test_http_url_keeps_query_string_and_redacts_credentials(): void
    {
        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client);
        $request = new ServerRequest('GET', 'https://example.com/ajax?method=example&token=secret');

        $middleware->process($request, $this->respondWith(200));

        self::assertSame('https://example.com/ajax?method=example&token=REDACTED', $client->attributes['http_url']);
        self::assertSame('GET /ajax', $client->attributes['action']);
    }

    public function test_submit_exception_keeps_the_application_response(): void
    {
        $client = new RecordingPerfbaseClient();
        $client->submitException = new PerfbaseException('Extension returned empty trace data');
        $middleware = $this->makeMiddleware($client);

        $response = $middleware->process(new ServerRequest('GET', 'https://example.com/ok'), $this->respondWith(201));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $client->submitCalls);
    }

    public function test_submit_exception_does_not_replace_the_application_exception(): void
    {
        $client = new RecordingPerfbaseClient();
        $client->submitException = new PerfbaseException('Extension returned empty trace data');
        $middleware = $this->makeMiddleware($client);

        try {
            $middleware->process(new ServerRequest('GET', 'https://example.com/fail'), new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    throw new \DomainException('application failure');
                }
            });
            self::fail('The application exception must be rethrown.');
        } catch (\DomainException $exception) {
            self::assertSame('application failure', $exception->getMessage());
        }

        self::assertSame(1, $client->submitCalls);
    }

    public function test_matched_route_pattern_from_current_route_is_used_as_action(): void
    {
        $client = new RecordingPerfbaseClient();
        $currentRoute = new class {
            public ?string $pattern = null;

            public function getPattern(): ?string
            {
                return $this->pattern;
            }
        };
        $middleware = $this->makeMiddleware($client, [], $currentRoute);

        // The router populates CurrentRoute while the inner handler runs.
        $middleware->process(
            new ServerRequest('GET', 'https://example.com/articles/42'),
            new class (static function () use ($currentRoute): void {
                $currentRoute->pattern = '/articles/{id}';
            }) implements RequestHandlerInterface {
                public function __construct(private \Closure $routeMatched)
                {
                }

                public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    ($this->routeMatched)();

                    return new Response(200);
                }
            }
        );

        self::assertSame('GET /articles/{id}', $client->attributes['action']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_user_agent_and_ip_fall_back_to_the_psr7_request(): void
    {
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR']);
        $client = new RecordingPerfbaseClient();
        $middleware = $this->makeMiddleware($client);
        $request = (new ServerRequest('GET', 'https://example.com/', ['User-Agent' => 'Worker/1.0'], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']));

        $middleware->process($request, $this->respondWith(200));

        self::assertSame('Worker/1.0', $client->attributes['user_agent']);
        self::assertSame('203.0.113.7', $client->attributes['user_ip']);
    }

    public function test_disabled_install_without_api_key_never_builds_the_sdk_client(): void
    {
        $factoryCalls = 0;
        // Debug mode rethrows: building the SDK config without an API key would throw here.
        $errorHandler = new PerfbaseErrorHandler(true, true);
        $middleware = new PerfbaseHttpMiddleware(
            ['enabled' => false, 'sample_rate' => 1.0],
            new PerfbaseClientProvider(
                ['api_key' => ''],
                $errorHandler,
                static function () use (&$factoryCalls) {
                    $factoryCalls++;

                    return new RecordingPerfbaseClient();
                }
            ),
            $errorHandler
        );

        $response = $middleware->process(new ServerRequest('GET', 'https://example.com/'), $this->respondWith(200));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $factoryCalls);
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

    private function respondWith(int $status): RequestHandlerInterface
    {
        return new class ($status) implements RequestHandlerInterface {
            public function __construct(private int $status)
            {
            }

            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response($this->status);
            }
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function makeMiddleware(RecordingPerfbaseClient $client, array $config = [], ?object $currentRoute = null): PerfbaseHttpMiddleware
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
            '1.2.3',
            $currentRoute
        );
    }
}
