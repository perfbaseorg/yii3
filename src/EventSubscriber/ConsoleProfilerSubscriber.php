<?php

declare(strict_types=1);

namespace Perfbase\Yii3\EventSubscriber;

use Perfbase\Yii3\Lifecycle\ConsoleCommandLifecycle;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConsoleProfilerSubscriber implements EventSubscriberInterface
{
    /** @var array<int, ConsoleCommandLifecycle> */
    private array $lifecycles = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private string $environment,
        private string $appVersion,
        private PerfbaseClientProvider $clientProvider,
        private PerfbaseErrorHandler $errorHandler
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::ERROR => 'onError',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if ($command === null) {
            return;
        }

        try {
            $lifecycle = new ConsoleCommandLifecycle(
                $command->getName() ?? 'unknown',
                $this->clientProvider,
                $this->errorHandler,
                $this->config,
                $this->environment,
                $this->appVersion
            );

            $key = spl_object_id($event->getInput());
            $this->lifecycles[$key] = $lifecycle;
            $lifecycle->startProfiling();
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'console_command');
        }
    }

    public function onError(ConsoleErrorEvent $event): void
    {
        try {
            $lifecycle = $this->getLifecycle($event->getInput());
            if ($lifecycle === null) {
                return;
            }

            $lifecycle->setException($event->getError());
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'console_error');
        }
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $key = spl_object_id($event->getInput());

        try {
            $lifecycle = $this->lifecycles[$key] ?? null;
            if ($lifecycle === null) {
                return;
            }

            $lifecycle->setExitCode($event->getExitCode());
            $lifecycle->stopProfiling();
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'console_terminate');
        } finally {
            unset($this->lifecycles[$key]);
        }
    }

    private function getLifecycle(InputInterface $input): ?ConsoleCommandLifecycle
    {
        return $this->lifecycles[spl_object_id($input)] ?? null;
    }
}
