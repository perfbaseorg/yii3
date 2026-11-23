<?php

declare(strict_types=1);

use Perfbase\Yii3\Middleware\PerfbaseHttpMiddleware;
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
    PerfbaseHttpMiddleware::class => static fn(ContainerInterface $container): PerfbaseHttpMiddleware => new PerfbaseHttpMiddleware(
        $perfbase,
        $container->get(PerfbaseClientProvider::class),
        $container->get(PerfbaseErrorHandler::class),
        $environment,
        $appVersion,
        // Optional: yiisoft/router fills CurrentRoute with the matched pattern used as the trace action.
        $container->has('Yiisoft\\Router\\CurrentRoute') ? $container->get('Yiisoft\\Router\\CurrentRoute') : null
    ),
];
