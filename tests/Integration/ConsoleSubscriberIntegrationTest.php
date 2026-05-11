<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Integration;

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
use Symfony\Component\EventDispatcher\EventDispatcher;

class ConsoleSubscriberIntegrationTest extends TestCase
{
    public function test_command_start_terminate_submit_flow(): void
    {
        $client = new RecordingPerfbaseClient();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->makeSubscriber($client));

        $command = new Command('app:sync');
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, 0), ConsoleEvents::TERMINATE);

        self::assertSame(['artisan'], $client->startedSpans);
        self::assertSame('0', $client->attributes['exit_code']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_error_terminate_flow_captures_exception(): void
    {
        $client = new RecordingPerfbaseClient();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->makeSubscriber($client));

        $command = new Command('app:sync');
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);
        $dispatcher->dispatch(new ConsoleErrorEvent($input, $output, new \RuntimeException('command failed'), $command), ConsoleEvents::ERROR);
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, 1), ConsoleEvents::TERMINATE);

        self::assertSame('command failed', $client->attributes['exception']);
        self::assertSame('1', $client->attributes['exit_code']);
        self::assertSame(1, $client->submitCalls);
    }

    public function test_multiple_commands_do_not_leak_state(): void
    {
        $client = new RecordingPerfbaseClient();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->makeSubscriber($client));

        $firstCommand = new Command('app:first');
        $secondCommand = new Command('app:second');
        $firstInput = new ArrayInput([]);
        $firstOutput = new BufferedOutput();
        $secondInput = new ArrayInput([]);
        $secondOutput = new BufferedOutput();

        $dispatcher->dispatch(new ConsoleCommandEvent($firstCommand, $firstInput, $firstOutput), ConsoleEvents::COMMAND);
        $dispatcher->dispatch(new ConsoleTerminateEvent($firstCommand, $firstInput, $firstOutput, 0), ConsoleEvents::TERMINATE);
        $dispatcher->dispatch(new ConsoleCommandEvent($secondCommand, $secondInput, $secondOutput), ConsoleEvents::COMMAND);
        $dispatcher->dispatch(new ConsoleTerminateEvent($secondCommand, $secondInput, $secondOutput, 0), ConsoleEvents::TERMINATE);

        self::assertCount(2, $client->startedSpans);
        self::assertSame(2, $client->submitCalls);
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
