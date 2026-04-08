<?php

declare(strict_types=1);

namespace Perfbase\Yii3;

use Perfbase\SDK\FeatureFlags;
use Perfbase\Yii3\EventSubscriber\ConsoleProfilerSubscriber;
use Perfbase\Yii3\Middleware\PerfbaseHttpMiddleware;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Psr\Container\ContainerInterface;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'params' => [
                'perfbase' => self::defaults(),
            ],
            'definitions' => [
                PerfbaseErrorHandler::class => static function (ContainerInterface $container): PerfbaseErrorHandler {
                    $config = self::readConfig($container);

                    return new PerfbaseErrorHandler(
                        (bool) ($config['debug'] ?? false),
                        (bool) ($config['log_errors'] ?? true)
                    );
                },
                PerfbaseClientProvider::class => static function (ContainerInterface $container): PerfbaseClientProvider {
                    return new PerfbaseClientProvider(
                        self::readConfig($container),
                        $container->get(PerfbaseErrorHandler::class)
                    );
                },
                PerfbaseHttpMiddleware::class => static function (ContainerInterface $container): PerfbaseHttpMiddleware {
                    $config = self::readConfig($container);

                    return new PerfbaseHttpMiddleware(
                        $config,
                        $container->get(PerfbaseClientProvider::class),
                        $container->get(PerfbaseErrorHandler::class),
                        self::environment(),
                        (string) ($config['app_version'] ?? '')
                    );
                },
                ConsoleProfilerSubscriber::class => static function (ContainerInterface $container): ConsoleProfilerSubscriber {
                    $config = self::readConfig($container);

                    return new ConsoleProfilerSubscriber(
                        $config,
                        self::environment(),
                        (string) ($config['app_version'] ?? ''),
                        $container->get(PerfbaseClientProvider::class),
                        $container->get(PerfbaseErrorHandler::class)
                    );
                },
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'debug' => false,
            'log_errors' => true,
            'api_key' => '',
            'api_url' => 'https://ingress.perfbase.cloud',
            'sample_rate' => 0.1,
            'timeout' => 10,
            'proxy' => null,
            'flags' => FeatureFlags::DefaultFlags,
            'app_version' => '',
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

    /**
     * @return array<string, mixed>
     */
    private static function readConfig(ContainerInterface $container): array
    {
        $params = $container->has('params') ? $container->get('params') : [];
        if (!is_array($params)) {
            return self::defaults();
        }

        $config = $params['perfbase'] ?? [];
        if (!is_array($config)) {
            return self::defaults();
        }

        return array_replace_recursive(self::defaults(), $config);
    }

    private static function environment(): string
    {
        $value = $_ENV['YII_ENV'] ?? $_SERVER['YII_ENV'] ?? getenv('YII_ENV');

        return is_string($value) && $value !== '' ? $value : 'production';
    }
}
