<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit\Lifecycle;

use Nyholm\Psr7\ServerRequest;
use Perfbase\Yii3\Lifecycle\HttpRequestLifecycle;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;

class HttpRequestLifecycleTest extends TestCase
{
    public function test_start_and_stop_profile_http_request(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

        $client = new RecordingPerfbaseClient();
        $request = (new ServerRequest('GET', 'https://example.com/articles/42?token=secret'))
            ->withAttribute('userId', 'user-123');

        $lifecycle = new HttpRequestLifecycle(
            $request,
            '/articles/{id}',
            'app_article',
            'App\\Controller\\ArticleController',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setResponseStatusCode(201);
        $lifecycle->stopProfiling();

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame(['http'], $client->stoppedSpans);
        self::assertSame(1, $client->submitCalls);
        self::assertSame('GET /articles/{id}', $client->attributes['action']);
        self::assertSame('https://example.com/articles/42', $client->attributes['http_url']);
        self::assertSame('201', $client->attributes['http_status_code']);
        self::assertSame('user-123', $client->attributes['user_id']);
        self::assertSame('http', $client->attributes['source']);
    }

    public function test_disallowed_http_status_code_is_not_submitted_by_default(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', 'https://example.com/articles/42'),
            '/articles/{id}',
            'app_article',
            'App\\Controller\\ArticleController',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setResponseStatusCode(404);
        $lifecycle->stopProfiling();

        self::assertSame(0, $client->submitCalls);
        self::assertSame(1, $client->resetCalls);
        self::assertSame('404', $client->attributes['http_status_code']);
    }

    public function test_server_error_status_code_is_submitted_by_default(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', 'https://example.com/articles/42'),
            '/articles/{id}',
            'app_article',
            'App\\Controller\\ArticleController',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setResponseStatusCode(503);
        $lifecycle->stopProfiling();

        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
        self::assertSame('503', $client->attributes['http_status_code']);
    }

    public function test_custom_allowed_http_status_code_is_submitted(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['profile_http_status_codes'] = [200, 404];

        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', 'https://example.com/articles/42'),
            '/articles/{id}',
            'app_article',
            'App\\Controller\\ArticleController',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setResponseStatusCode(404);
        $lifecycle->stopProfiling();

        self::assertSame(1, $client->submitCalls);
        self::assertSame(0, $client->resetCalls);
    }

    public function test_excluded_http_request_is_not_profiled(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['exclude']['http'] = ['/admin*'];

        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', 'https://example.com/admin/users'),
            null,
            null,
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_disabled_http_profiling_is_not_started(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['enabled'] = false;

        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', 'https://example.com/articles/42'),
            '/articles/{id}',
            'app_article',
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();

        self::assertFalse($lifecycle->hasStarted());
        self::assertSame([], $client->startedSpans);
    }

    public function test_exception_attribute_is_submitted(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', 'https://example.com/articles/42'),
            '/articles/{id}',
            'app_article',
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setException(new \RuntimeException('boom'));
        $lifecycle->stopProfiling();

        self::assertSame('boom', $client->attributes['exception']);
    }

    public function test_route_name_and_identity_object_are_used_when_route_template_is_missing(): void
    {
        $client = new RecordingPerfbaseClient();
        $request = (new ServerRequest('GET', 'https://example.com/dashboard?token=secret'))
            ->withAttribute('identity', new class {
                public function getUserIdentifier(): string
                {
                    return 'user-456';
                }
            });

        $config = $this->config();
        $config['include']['http'] = ['dashboard_route'];

        $lifecycle = new HttpRequestLifecycle(
            $request,
            null,
            'dashboard_route',
            'App\\Controller\\DashboardController',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('GET dashboard_route', $client->attributes['action']);
        self::assertSame('user-456', $client->attributes['user_id']);
    }

    public function test_empty_uri_path_defaults_to_root(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', 'https://example.com'),
            null,
            null,
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('GET /', $client->attributes['action']);
        self::assertSame('https://example.com/', $client->attributes['http_url']);
    }

    public function test_path_only_uri_is_preserved_without_authority(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new HttpRequestLifecycle(
            new ServerRequest('GET', '/health?token=secret'),
            null,
            null,
            null,
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        self::assertSame(['http'], $client->startedSpans);
        self::assertSame('/health', $client->attributes['http_url']);
    }

    private function makeProvider(RecordingPerfbaseClient $client): PerfbaseClientProvider
    {
        return new PerfbaseClientProvider(
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
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'enabled' => true,
            'sample_rate' => 1.0,
            'profile_http_status_codes' => [...range(200, 299), ...range(500, 599)],
            'include' => [
                'http' => ['*'],
                'console' => ['*'],
            ],
            'exclude' => [
                'http' => [],
                'console' => [],
            ],
        ];
    }
}
