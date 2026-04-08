<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Support;

use Throwable;

class PerfbaseErrorHandler
{
    public function __construct(
        private bool $debug,
        private bool $logErrors
    ) {
    }

    public function handle(Throwable $throwable, string $context = 'unknown'): void
    {
        if ($this->debug) {
            throw $throwable;
        }

        if ($this->logErrors) {
            error_log(sprintf('Perfbase profiling error in %s: %s', $context, $throwable->getMessage()));
        }
    }
}
