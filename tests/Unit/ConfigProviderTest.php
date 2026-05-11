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

class ConfigProviderTest extends TestCase
{
    public function test_defaults_are_exposed(): void
    {
        $defaults = ConfigProvider::defaults();

        self::assertFalse($defaults['enabled']);
        self::assertSame(0.1, $defaults['sample_rate']);
        self::assertSame([...range(200, 299), ...range(500, 599)], $defaults['profile_http_status_codes']);
        self::assertSame(['*'], $defaults['include']['http']);
        self::assertSame([], $defaults['exclude']['console']);
    }

    public function test_config_provider_returns_params_and_definitions(): void
    {
        $config = (new ConfigProvider())();

        self::assertArrayHasKey('params', $config);
        self::assertArrayHasKey('definitions', $config);
        self::assertArrayHasKey('perfbase', $config['params']);
    }

    public function test_definitions_resolve_services(): void
    {
        $config = (new ConfigProvider())();
        $container = new ArrayContainer([
            'params' => [
                'perfbase' => [
                    'enabled' => true,
                    'api_key' => 'test-key',
                ],
            ],
            PerfbaseErrorHandler::class => $config['definitions'][PerfbaseErrorHandler::class],
            PerfbaseClientProvider::class => $config['definitions'][PerfbaseClientProvider::class],
            PerfbaseHttpMiddleware::class => $config['definitions'][PerfbaseHttpMiddleware::class],
            ConsoleProfilerSubscriber::class => $config['definitions'][ConsoleProfilerSubscriber::class],
        ]);

        self::assertInstanceOf(PerfbaseErrorHandler::class, $container->get(PerfbaseErrorHandler::class));
        self::assertInstanceOf(PerfbaseClientProvider::class, $container->get(PerfbaseClientProvider::class));
        self::assertInstanceOf(PerfbaseHttpMiddleware::class, $container->get(PerfbaseHttpMiddleware::class));
        self::assertInstanceOf(ConsoleProfilerSubscriber::class, $container->get(ConsoleProfilerSubscriber::class));
    }
}
