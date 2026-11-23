<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Lifecycle;

use Perfbase\Yii3\ConfigProvider;
use Perfbase\Yii3\Profiling\AbstractProfiler;
use Perfbase\Yii3\Support\ConfigValue;
use Perfbase\Yii3\Support\FilterMatcher;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use Perfbase\Yii3\Support\SpanNaming;

class ConsoleCommandLifecycle extends AbstractProfiler
{
    public function __construct(
        private string $command,
        PerfbaseClientProvider $clientProvider,
        PerfbaseErrorHandler $errorHandler,
        array $config,
        string $environment,
        string $appVersion
    ) {
        parent::__construct(
            SpanNaming::forConsole($command),
            $clientProvider,
            $errorHandler,
            $config,
            $environment,
            $appVersion
        );
    }

    protected function shouldProfile(): bool
    {
        if (!ConfigValue::bool($this->config['enabled'] ?? null, false)) {
            return false;
        }

        return FilterMatcher::passesFilters(
            [$this->command],
            $this->normalizeFilters($this->config['include']['console'] ?? ['*']),
            $this->normalizeFilters($this->config['exclude']['console'] ?? ConfigProvider::LONG_RUNNING_COMMANDS)
        );
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $this->setAttributes([
            'source' => 'console',
            'action' => $this->command,
        ]);
    }

    /**
     * @param mixed $filters
     * @return array<int, string>
     */
    private function normalizeFilters($filters): array
    {
        if (!is_array($filters)) {
            return [];
        }

        return array_values(array_filter($filters, static function ($filter): bool {
            return is_string($filter) && $filter !== '';
        }));
    }
}
