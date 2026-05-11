<p align="center">
  <a href="https://perfbase.com">
    <img src="https://cdn.perfbase.com/img/logo-full.svg" alt="Perfbase" width="300">
  </a>
</p>

<h3 align="center">Perfbase for Yii 3</h3>
<p align="center">
  Yii 3 integration for <a href="https://perfbase.com">Perfbase</a>.
</p>

<p align="center">
  <a href="https://packagist.org/packages/perfbase/yii3"><img src="https://img.shields.io/packagist/v/perfbase/yii3" alt="Packagist Version"></a>
  <a href="https://github.com/perfbaseorg/yii3/blob/main/LICENSE.txt"><img src="https://img.shields.io/packagist/l/perfbase/yii3" alt="License"></a>
  <a href="https://github.com/perfbaseorg/yii3/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/perfbaseorg/yii3/ci.yml?branch=main" alt="CI"></a>
  <img src="https://img.shields.io/badge/php-8.1%2B-blue" alt="PHP Version">
  <img src="https://img.shields.io/badge/yii-3.x-blue" alt="Yii Version">
</p>

This package is a thin adapter over [`perfbase/php-sdk`](https://packagist.org/packages/perfbase/php-sdk). It keeps the framework layer thin and pushes transport, extension access, and submission behavior into the shared SDK.

## Scope

v1 supports:

- HTTP profiling through PSR-15 middleware
- Console command profiling through Symfony Console events

v1 does not support:

- Queue or worker profiling
- Scheduler-specific profiling
- Custom buffering or retries
- Yii-specific profiler UI

## Requirements

- PHP `>=8.1 <8.5`
- `yiisoft/yii-http` `^1.1`
- `yiisoft/yii-console` `^2.4`
- `perfbase/php-sdk` `^1.0`
- The Perfbase PHP extension installed for the target PHP runtime

## Installation

```bash
composer require perfbase/yii3
```

Add the config provider:

```php
return [
    \Perfbase\Yii3\ConfigProvider::class,
];
```

Then override params as needed:

```php
return [
    'perfbase' => [
        'enabled' => true,
        'api_key' => $_ENV['PERFBASE_API_KEY'] ?? '',
        'sample_rate' => 0.1,
        'app_version' => '1.0.0',
    ],
];
```

## Configuration

The config provider exposes this default config under `params['perfbase']`:

```php
[
    'enabled' => false,
    'debug' => false,
    'log_errors' => true,
    'api_key' => '',
    'api_url' => 'https://ingress.perfbase.cloud',
    'sample_rate' => 0.1,
    'profile_http_status_codes' => [...range(200, 299), ...range(500, 599)],
    'timeout' => 10,
    'proxy' => null,
    'flags' => \Perfbase\SDK\FeatureFlags::DefaultFlags,
    'app_version' => '',
    'include' => [
        'http' => ['*'],
        'console' => ['*'],
    ],
    'exclude' => [
        'http' => [],
        'console' => [],
    ],
]
```

Notes:

- `sample_rate` must be numeric between `0.0` and `1.0`
- `profile_http_status_codes` defaults to `[...range(200, 299), ...range(500, 599)]`. Add codes such as `404` if you want to keep them, or set it to `[]` to disable HTTP trace submission entirely.
- `environment` comes from `YII_ENV`, otherwise `production`
- `app_version` is application-defined

## HTTP Integration

HTTP profiling is middleware-based through [`PerfbaseHttpMiddleware.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-yii3/src/Middleware/PerfbaseHttpMiddleware.php).

Register it in your HTTP middleware pipeline:

```php
use Perfbase\Yii3\Middleware\PerfbaseHttpMiddleware;

return [
    PerfbaseHttpMiddleware::class,
    // other middleware...
];
```

Behavior:

- starts profiling before delegating to the next handler
- captures response status on success
- captures exception context on failure
- HTTP traces are only submitted when the response status code is in `profile_http_status_codes`
- always stops profiling in `finally`

HTTP attributes include:

- `source=http`
- `action`
- `http_method`
- `http_url`
- `http_status_code`
- `user_ip`
- `user_agent`
- `hostname`
- `environment`
- `app_version`
- `php_version`

Route metadata behavior:

- route template is resolved from request attributes such as `routePattern`, `route`, or `_route`
- route name is resolved from `routeName` or `route_name`
- controller metadata is resolved from `controller` or `action`
- `http_url` excludes query strings

## Console Integration

Console profiling is wired through [`ConsoleProfilerSubscriber.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-yii3/src/EventSubscriber/ConsoleProfilerSubscriber.php), which subscribes to Symfony Console events used by Yii3 console packages.

Register the subscriber with your event dispatcher:

```php
use Perfbase\Yii3\EventSubscriber\ConsoleProfilerSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dispatcher = $container->get(EventDispatcher::class);
$dispatcher->addSubscriber($container->get(ConsoleProfilerSubscriber::class));
```

Subscribed events:

- `ConsoleEvents::COMMAND`
- `ConsoleEvents::ERROR`
- `ConsoleEvents::TERMINATE`

Behavior:

- lifecycles are keyed by the input object identity
- exceptions are attached on `ERROR`
- exit codes are attached on `TERMINATE`
- lifecycle state is cleaned up even when command execution fails

## Filters

Supported contexts:

- `http`
- `console`

Supported filter styles:

- `*`
- `.*`
- glob patterns such as `cache/*`
- regex patterns such as `/^GET \/api\//`

Example:

```php
'perfbase' => [
    'include' => [
        'http' => ['route/*', '/^GET \\/api\\//'],
        'console' => ['cache/*', 'migrate'],
    ],
    'exclude' => [
        'http' => ['debug/*'],
        'console' => ['help'],
    ],
],
```

## Error Handling

The adapter is fail-open:

- SDK init failures degrade to a no-op
- missing extension degrades to a no-op
- submit failures do not break request or command execution

Behavior:

- `debug=false`: swallow and optionally log errors
- `debug=true`: rethrow adapter/runtime errors

## Runtime Architecture

Primary entry points:

- [`ConfigProvider.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-yii3/src/ConfigProvider.php)
- [`PerfbaseHttpMiddleware.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-yii3/src/Middleware/PerfbaseHttpMiddleware.php)
- [`ConsoleProfilerSubscriber.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-yii3/src/EventSubscriber/ConsoleProfilerSubscriber.php)
- lifecycle classes in [`src/Lifecycle`](/Users/ben/Projects/Perfbase/environment/projects/lib-yii3/src/Lifecycle)
- support helpers in [`src/Support`](/Users/ben/Projects/Perfbase/environment/projects/lib-yii3/src/Support)

The adapter does not implement its own delivery layer.

## Development

Verify locally with:

```bash
composer install
composer run test
composer run phpstan
```

## Limitations

- No queue support in v1
- No scheduler-specific integration in v1
- No Yii-specific debug toolbar integration
- HTTP and console wiring must be registered explicitly in the host application

## Documentation

Full documentation is available at [perfbase.com/docs](https://perfbase.com/docs).

- **Docs**: [perfbase.com/docs](https://perfbase.com/docs)
- **Issues**: [github.com/perfbaseorg/yii3/issues](https://github.com/perfbaseorg/yii3/issues)
- **Support**: [support@perfbase.com](mailto:support@perfbase.com)

## License

Apache-2.0. See [LICENSE.txt](LICENSE.txt).
