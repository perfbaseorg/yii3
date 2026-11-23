<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit;

use Perfbase\Yii3\ConfigProvider;
use Perfbase\Yii3\EventSubscriber\ConsoleProfilerSubscriber;
use Perfbase\Yii3\Middleware\PerfbaseHttpMiddleware;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Tests\Fixtures\ArrayContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;

class ConfigFilesTest extends TestCase
{
    public function test_params_config_exposes_perfbase_defaults(): void
    {
        $config = $this->loadConfig('params.php');

        self::assertSame(ConfigProvider::params(), $config['perfbase'] ?? null);
    }

    public function test_params_config_ships_no_list_values_that_yii_config_would_append_to(): void
    {
        $config = $this->loadConfig('params.php');
        $perfbase = $config['perfbase'] ?? null;
        self::assertIsArray($perfbase);

        array_walk_recursive($perfbase, static function ($value, $key): void {
            self::assertIsString($key, 'List values in package params cannot be narrowed by the application.');
        });
        self::assertArrayNotHasKey('include', $perfbase);
        self::assertArrayNotHasKey('exclude', $perfbase);
        self::assertArrayNotHasKey('profile_http_status_codes', $perfbase);
    }

    public function test_common_di_config_resolves_perfbase_services(): void
    {
        $definitions = $this->loadConfig('di.php', [
            'perfbase' => [
                'enabled' => true,
                'api_key' => 'common-di-key',
                'sample_rate' => 1.0,
                'app_version' => 'common-di-app',
            ],
        ]);
        $container = new ArrayContainer($definitions);

        $errorHandler = $container->get(PerfbaseErrorHandler::class);
        $clientProvider = $container->get(PerfbaseClientProvider::class);
        $subscriber = $container->get(ConsoleProfilerSubscriber::class);

        self::assertInstanceOf(PerfbaseErrorHandler::class, $errorHandler);
        self::assertInstanceOf(PerfbaseClientProvider::class, $clientProvider);
        self::assertInstanceOf(ConsoleProfilerSubscriber::class, $subscriber);
        self::assertSame('common-di-key', $this->readClientProviderConfig($clientProvider)['api_key']);
        self::assertSame(1.0, $this->readClientProviderConfig($clientProvider)['sample_rate']);
        self::assertSame('common-di-app', $this->readClientProviderConfig($clientProvider)['app_version']);
    }

    public function test_web_di_config_resolves_http_middleware(): void
    {
        $params = [
            'perfbase' => [
                'enabled' => true,
                'api_key' => 'web-di-key',
                'sample_rate' => 1.0,
                'app_version' => 'web-di-app',
            ],
        ];
        $definitions = array_merge(
            $this->loadConfig('di.php', $params),
            $this->loadConfig('di-web.php', $params)
        );
        $container = new ArrayContainer($definitions);

        self::assertInstanceOf(PerfbaseHttpMiddleware::class, $container->get(PerfbaseHttpMiddleware::class));
    }

    public function test_web_di_config_passes_router_current_route_when_available(): void
    {
        $params = ['perfbase' => ['enabled' => true, 'api_key' => 'web-di-key']];
        $currentRoute = new class {
            public function getPattern(): string
            {
                return '/articles/{id}';
            }
        };
        $definitions = array_merge(
            $this->loadConfig('di.php', $params),
            $this->loadConfig('di-web.php', $params),
            ['Yiisoft\\Router\\CurrentRoute' => $currentRoute]
        );
        $middleware = (new ArrayContainer($definitions))->get(PerfbaseHttpMiddleware::class);

        self::assertInstanceOf(PerfbaseHttpMiddleware::class, $middleware);
        self::assertSame($currentRoute, (new \ReflectionProperty($middleware, 'currentRoute'))->getValue($middleware));
    }

    public function test_common_di_config_normalises_string_booleans(): void
    {
        $definitions = $this->loadConfig('di.php', [
            'perfbase' => ['debug' => 'false', 'log_errors' => '0'],
        ]);
        $errorHandler = (new ArrayContainer($definitions))->get(PerfbaseErrorHandler::class);

        self::assertInstanceOf(PerfbaseErrorHandler::class, $errorHandler);
        self::assertFalse((new \ReflectionProperty($errorHandler, 'debug'))->getValue($errorHandler));
        self::assertFalse((new \ReflectionProperty($errorHandler, 'logErrors'))->getValue($errorHandler));
    }

    public function test_console_events_config_maps_to_subscriber_methods(): void
    {
        $events = $this->loadConfig('events-console.php');

        self::assertSame(
            [[ConsoleProfilerSubscriber::class, 'onCommand']],
            $events[ConsoleCommandEvent::class] ?? null
        );
        self::assertSame(
            [[ConsoleProfilerSubscriber::class, 'onError']],
            $events[ConsoleErrorEvent::class] ?? null
        );
        self::assertSame(
            [[ConsoleProfilerSubscriber::class, 'onTerminate']],
            $events[ConsoleTerminateEvent::class] ?? null
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function loadConfig(string $file, array $params = []): array
    {
        $config = (static function (string $path, array $params): mixed {
            $params = array_replace([], $params);

            return require $path;
        })(dirname(__DIR__, 2) . '/config/' . $file, $params);

        self::assertIsArray($config);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function readClientProviderConfig(PerfbaseClientProvider $provider): array
    {
        $property = new \ReflectionProperty($provider, 'config');
        $value = $property->getValue($provider);

        self::assertIsArray($value);

        return $value;
    }
}
