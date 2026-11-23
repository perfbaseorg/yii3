<?php

declare(strict_types=1);

use Perfbase\Yii3\EventSubscriber\ConsoleProfilerSubscriber;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;

return [
    ConsoleCommandEvent::class => [
        [ConsoleProfilerSubscriber::class, 'onCommand'],
    ],
    ConsoleErrorEvent::class => [
        [ConsoleProfilerSubscriber::class, 'onError'],
    ],
    ConsoleTerminateEvent::class => [
        [ConsoleProfilerSubscriber::class, 'onTerminate'],
    ],
];
