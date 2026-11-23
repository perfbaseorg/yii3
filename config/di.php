<?php

declare(strict_types=1);

use Perfbase\Yii3\EventSubscriber\ConsoleProfilerSubscriber;
use Perfbase\Yii3\Support\ConfigValue;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Psr\Container\ContainerInterface;

/** @var array $params */

$perfbase = $params['perfbase'] ?? [];
$environment = $_ENV['YII_ENV'] ?? $_SERVER['YII_ENV'] ?? getenv('YII_ENV') ?: 'production';
$environment = is_string($environment) && $environment !== '' ? $environment : 'production';
$appVersion = ConfigValue::string($perfbase['app_version'] ?? null);

return [
    PerfbaseErrorHandler::class => static fn(): PerfbaseErrorHandler => new PerfbaseErrorHandler(
        ConfigValue::bool($perfbase['debug'] ?? null, false),
        ConfigValue::bool($perfbase['log_errors'] ?? null, true)
    ),
    PerfbaseClientProvider::class => static fn(ContainerInterface $container): PerfbaseClientProvider => new PerfbaseClientProvider(
        $perfbase,
        $container->get(PerfbaseErrorHandler::class)
    ),
    ConsoleProfilerSubscriber::class => static fn(ContainerInterface $container): ConsoleProfilerSubscriber => new ConsoleProfilerSubscriber(
        $perfbase,
        $environment,
        $appVersion,
        $container->get(PerfbaseClientProvider::class),
        $container->get(PerfbaseErrorHandler::class)
    ),
];
