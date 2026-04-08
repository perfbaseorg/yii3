<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit\Support;

use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;

class PerfbaseClientProviderTest extends TestCase
{
    public function test_valid_config_returns_cached_client(): void
    {
        $created = 0;
        $expectedClient = new RecordingPerfbaseClient();

        $provider = new PerfbaseClientProvider(
            [
                'api_key' => 'test-key',
                'api_url' => 'https://receiver.perfbase.local',
                'flags' => 0,
                'timeout' => 10,
                'proxy' => null,
            ],
            new PerfbaseErrorHandler(false, false),
            static function () use (&$created, $expectedClient) {
                $created++;
                return $expectedClient;
            }
        );

        self::assertSame($expectedClient, $provider->getClient());
        self::assertSame($expectedClient, $provider->getClient());
        self::assertSame(1, $created);
    }

    public function test_proxy_is_forwarded_when_present(): void
    {
        $capturedProxy = null;

        $provider = new PerfbaseClientProvider(
            [
                'api_key' => 'test-key',
                'api_url' => 'https://receiver.perfbase.local',
                'flags' => 0,
                'timeout' => 10,
                'proxy' => 'http://proxy.local:8080',
            ],
            new PerfbaseErrorHandler(false, false),
            static function (Config $config) use (&$capturedProxy) {
                $capturedProxy = $config->proxy;
                return new RecordingPerfbaseClient();
            }
        );

        $provider->getClient();

        self::assertSame('http://proxy.local:8080', $capturedProxy);
    }

    public function test_invalid_config_degrades_to_null(): void
    {
        $provider = new PerfbaseClientProvider(
            [
                'api_key' => '',
                'api_url' => 'https://receiver.perfbase.local',
            ],
            new PerfbaseErrorHandler(false, false)
        );

        self::assertNull($provider->getClient());
    }

    public function test_factory_must_return_perfbase_instance(): void
    {
        $provider = new PerfbaseClientProvider(
            [
                'api_key' => 'test-key',
                'api_url' => 'https://receiver.perfbase.local',
                'flags' => 0,
                'timeout' => 10,
                'proxy' => '',
            ],
            new PerfbaseErrorHandler(false, false),
            static function () {
                return new \stdClass();
            }
        );

        self::assertNull($provider->getClient());
    }

    public function test_default_sdk_config_values_are_applied(): void
    {
        $capturedConfig = null;

        $provider = new PerfbaseClientProvider(
            [
                'api_key' => 'test-key',
            ],
            new PerfbaseErrorHandler(false, false),
            static function (Config $config) use (&$capturedConfig) {
                $capturedConfig = $config;
                return new RecordingPerfbaseClient();
            }
        );

        $provider->getClient();

        self::assertInstanceOf(Config::class, $capturedConfig);
        self::assertSame('https://receiver.perfbase.com', $capturedConfig->api_url);
        self::assertSame(0, $capturedConfig->flags);
        self::assertSame(10, $capturedConfig->timeout);
        self::assertNull($capturedConfig->proxy);
    }

    public function test_default_factory_can_boot_real_sdk_client(): void
    {
        $provider = new PerfbaseClientProvider(
            [
                'api_key' => 'test-key',
                'api_url' => 'https://receiver.perfbase.local',
            ],
            new PerfbaseErrorHandler(false, false)
        );

        self::assertInstanceOf(Perfbase::class, $provider->getClient());
    }

    public function test_factory_failure_degrades_to_null(): void
    {
        $provider = new PerfbaseClientProvider(
            [
                'api_key' => 'test-key',
                'api_url' => 'https://receiver.perfbase.local',
                'flags' => 0,
                'timeout' => 10,
                'proxy' => null,
            ],
            new PerfbaseErrorHandler(false, false),
            static function (): void {
                throw new \RuntimeException('extension unavailable');
            }
        );

        self::assertNull($provider->getClient());
    }
}
