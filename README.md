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
  <img src="https://img.shields.io/badge/php-8.2%2B-blue" alt="PHP Version">
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

- PHP `>=8.2 <8.7` (Yii packages currently declare support up to PHP 8.5)
- `yiisoft/yii-http` `^1.1`
- `yiisoft/yii-console` `^2.4`
- `perfbase/php-sdk` `^1.9`
- The Perfbase PHP extension installed for the target PHP runtime

## Installation

```bash
composer require perfbase/yii3
```

The package ships Yii config-plugin files that are discovered automatically by `yiisoft/config`.
Override `params['perfbase']` in your application config as needed, for example in `config/common/params.php`:

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

These are the effective defaults for `params['perfbase']`:

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
        'console' => ['serve', 'queue:listen', 'queue:listen-all', 'queue:run', 'queue:consume'],
    ],
]
```

`yiisoft/config` merges list arrays by appending, so a list shipped in package params could never be narrowed by your application. The package params therefore leave out `include`, `exclude` and `profile_http_status_codes`; the values above apply in code when you do not set them, and whatever you set replaces them.

Notes:

- `sample_rate` must be numeric between `0.0` and `1.0`
- values read from environment variables may be strings: `enabled`, `debug` and `log_errors` accept `true`/`false`/`1`/`0`/`yes`/`no`/`on`/`off`; `flags` and `timeout` accept digit strings; blank `api_url`, `proxy`, `flags` or `timeout` fall back to the defaults
- `flags` is the SDK `FeatureFlags` bitmask. The default `FeatureFlags::DefaultFlags` enables every feature except `TrackCpuTime` and `TrackMemoryAllocation`. `0` enables every feature except the opt-in flags `UsePreciseClock` and `TrackCpuTime`; a mask containing only opt-in flags adds them to that set, and any mask containing a regular flag is used exactly
- `profile_http_status_codes` defaults to `[...range(200, 299), ...range(500, 599)]`. Add codes such as `404` if you want to keep them, or set it to `[]` to disable HTTP trace submission entirely.
- `environment` comes from `YII_ENV`, otherwise `production`
- `app_version` is application-defined

## HTTP Integration

HTTP profiling is middleware-based through [`PerfbaseHttpMiddleware.php`](src/Middleware/PerfbaseHttpMiddleware.php).

The package registers the middleware service through Yii config-plugin. Add it to your HTTP middleware pipeline where you want profiling to wrap application handling:

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
- `user_id` when available
- `hostname`
- `environment`
- `app_version`
- `php_version`

Route metadata behavior:

- when `yiisoft/router` is installed, the matched pattern from `Yiisoft\Router\CurrentRoute` becomes the action (`GET /articles/{id}`). The router fills it while the inner handler runs, so it is read after the handler returns; include/exclude filters, which run before the handler, see the request path and any route attributes below
- route template is otherwise resolved from request attributes such as `routePattern`, `route`, or `_route`
- route name is resolved from `routeName` or `route_name`
- controller metadata is resolved from `controller` or `action`
- without route metadata the action falls back to the request path
- `user_id` is read from the `userId` / `user_id` attributes, or from `getId()` / `getUserIdentifier()` on an `identity` / `user` attribute
- `user_agent` and `user_ip` fall back to the PSR-7 request for worker runtimes where `$_SERVER` is not per request
- `http_url` is the full request URL including the query string, passed through the SDK `UrlSanitizer`: values of credential-looking parameters (`token`, `password`, `api_key`, `signature`, `nonce`, session IDs, `email`, and similar) become `REDACTED`, and user info and fragments are dropped. `action` never contains the query string

## Console Integration

Console profiling is wired through [`ConsoleProfilerSubscriber.php`](src/EventSubscriber/ConsoleProfilerSubscriber.php), which subscribes to Symfony Console events used by Yii3 console packages.

The package registers console event listeners through Yii config-plugin, so no manual event-dispatcher wiring is required in standard Yii3 apps.

Subscribed events:

- `ConsoleEvents::COMMAND`
- `ConsoleEvents::ERROR`
- `ConsoleEvents::TERMINATE`

Behavior:

- the span is named `cli` and the action is the command name. Earlier releases named this span `artisan`.
- lifecycles are keyed by the input object identity
- long-running commands are excluded by default: `serve` (yiisoft/yii-console) and the yiisoft/queue workers (`queue:listen`, `queue:listen-all`, `queue:run`, `queue:consume`). A trace started for these would only end when the process exits. If you set `exclude.console`, include these entries again
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
- disabled or filtered requests and commands never build the SDK client, so a disabled install without an API key logs nothing
- submit failures, including an exception thrown by the SDK's `submitTrace()`, do not break request or command execution; the application's response is returned and its own exceptions are rethrown

Behavior:

- `debug=false`: swallow and optionally log errors
- `debug=true`: rethrow adapter/runtime errors

## Runtime Architecture

Primary entry points:

- package config files in `config/`
- [`ConfigProvider.php`](src/ConfigProvider.php)
- [`PerfbaseHttpMiddleware.php`](src/Middleware/PerfbaseHttpMiddleware.php)
- [`ConsoleProfilerSubscriber.php`](src/EventSubscriber/ConsoleProfilerSubscriber.php)
- lifecycle classes in [`src/Lifecycle`](src/Lifecycle)
- support helpers in [`src/Support`](src/Support)

`ConfigProvider` owns default configuration only. Yii integration wiring is delivered through `config/params.php`, `config/di.php`, `config/di-web.php`, and `config/events-console.php`.

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
- HTTP middleware must be added to the host application's middleware pipeline

## Documentation

Full documentation is available at [perfbase.com/docs](https://perfbase.com/docs).

- **Docs**: [perfbase.com/docs](https://perfbase.com/docs)
- **Issues**: [github.com/perfbaseorg/yii3/issues](https://github.com/perfbaseorg/yii3/issues)
- **Support**: [support@perfbase.com](mailto:support@perfbase.com)

## License

Apache-2.0. See [LICENSE.txt](LICENSE.txt).
