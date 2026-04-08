<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit\Lifecycle;

use Perfbase\Yii3\Lifecycle\ConsoleCommandLifecycle;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;

class ConsoleCommandLifecycleTest extends TestCase
{
    public function test_console_command_profiles_and_sets_exit_code(): void
    {
        $client = new RecordingPerfbaseClient();
        $lifecycle = new ConsoleCommandLifecycle(
            'app:sync',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $this->config(),
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();
        $lifecycle->setExitCode(2);
        $lifecycle->setException(new \RuntimeException('failed'));
        $lifecycle->stopProfiling();

        self::assertSame(['console.app:sync'], $client->startedSpans);
        self::assertSame('console', $client->attributes['source']);
        self::assertSame('app:sync', $client->attributes['action']);
        self::assertSame('2', $client->attributes['exit_code']);
        self::assertSame('failed', $client->attributes['exception']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_excluded_console_command_is_not_profiled(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['exclude']['console'] = ['app:*'];

        $lifecycle = new ConsoleCommandLifecycle(
            'app:sync',
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

    public function test_disabled_console_profiling_is_not_started(): void
    {
        $client = new RecordingPerfbaseClient();
        $config = $this->config();
        $config['enabled'] = false;

        $lifecycle = new ConsoleCommandLifecycle(
            'app:sync',
            $this->makeProvider($client),
            new PerfbaseErrorHandler(false, false),
            $config,
            'test',
            '1.2.3'
        );

        $lifecycle->startProfiling();

        self::assertSame([], $client->startedSpans);
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
