<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit\EventSubscriber;

use Perfbase\Yii3\EventSubscriber\ConsoleProfilerSubscriber;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Tests\Fixtures\RecordingPerfbaseClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleProfilerSubscriberTest extends TestCase
{
    public function test_subscribed_events_are_declared(): void
    {
        self::assertSame([
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::ERROR => 'onError',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ], ConsoleProfilerSubscriber::getSubscribedEvents());
    }

    public function test_null_command_noops_cleanly(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $subscriber->onCommand(new ConsoleCommandEvent(null, $input, $output));

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_error_and_terminate_without_lifecycle_noop_cleanly(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $command = new Command('app:sync');
        $output = new BufferedOutput();

        $subscriber->onError(new ConsoleErrorEvent(new ArrayInput([]), $output, new \RuntimeException('boom'), $command));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, new ArrayInput([]), $output, 0));

        self::assertSame([], $client->startedSpans);
        self::assertSame(0, $client->submitCalls);
    }

    public function test_command_without_name_falls_back_to_unknown(): void
    {
        $client = new RecordingPerfbaseClient();
        $subscriber = $this->makeSubscriber($client);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $command = new Command();

        $subscriber->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $subscriber->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertSame(['artisan'], $client->startedSpans);
        self::assertSame(1, $client->submitCalls);
    }

    private function makeSubscriber(RecordingPerfbaseClient $client): ConsoleProfilerSubscriber
    {
        return new ConsoleProfilerSubscriber(
            [
                'enabled' => true,
                'sample_rate' => 1.0,
                'include' => ['console' => ['*']],
                'exclude' => ['console' => []],
            ],
            'test',
            '1.2.3',
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
            new PerfbaseErrorHandler(false, false)
        );
    }
}
