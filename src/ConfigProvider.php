<?php

declare(strict_types=1);

namespace Perfbase\Yii3;

use Perfbase\SDK\FeatureFlags;

class ConfigProvider
{
    /**
     * `serve` (yiisoft/yii-console) and the yiisoft/queue workers.
     */
    public const LONG_RUNNING_COMMANDS = [
        'serve',
        'queue:listen',
        'queue:listen-all',
        'queue:run',
        'queue:consume',
    ];

    /**
     * Defaults shipped through the `params` config-plugin group.
     *
     * yiisoft/config merges integer-keyed (list) arrays by appending, so list
     * defaults here could never be narrowed by the application: overriding
     * `include.http` with `['/api/*']` would yield `['*', '/api/*']`. List-valued
     * options are therefore left out and resolved in code when absent
     * (see defaults() for their effective values).
     *
     * @return array<string, mixed>
     */
    public static function params(): array
    {
        $params = self::defaults();
        unset($params['include'], $params['exclude'], $params['profile_http_status_codes']);

        return $params;
    }

    /**
     * Effective defaults, including the list-valued options applied in code.
     *
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
            'profile_http_status_codes' => [...range(200, 299), ...range(500, 599)],
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
                // Long-running commands: a trace started for the server or queue
                // worker process would only end when the process exits.
                'console' => self::LONG_RUNNING_COMMANDS,
            ],
        ];
    }
}
