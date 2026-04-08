<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit\Support;

use Perfbase\Yii3\Support\PerfbaseErrorHandler;
use PHPUnit\Framework\TestCase;

class PerfbaseErrorHandlerTest extends TestCase
{
    public function test_debug_mode_rethrows(): void
    {
        $handler = new PerfbaseErrorHandler(true, true);

        $this->expectException(\RuntimeException::class);
        $handler->handle(new \RuntimeException('boom'), 'test');
    }

    public function test_production_mode_logs_when_enabled(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'perfbase-log-');
        self::assertIsString($logFile);

        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $handler = new PerfbaseErrorHandler(false, true);
            $handler->handle(new \RuntimeException('logged failure'), 'test');

            $contents = file_get_contents($logFile);
            self::assertIsString($contents);
            self::assertStringContainsString('Perfbase profiling error in test: logged failure', $contents);
        } finally {
            ini_set('error_log', $previous === false ? '' : (string) $previous);
            @unlink($logFile);
        }
    }

    public function test_production_mode_silences_when_logging_disabled(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'perfbase-log-');
        self::assertIsString($logFile);

        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);
        file_put_contents($logFile, '');

        try {
            $handler = new PerfbaseErrorHandler(false, false);
            $handler->handle(new \RuntimeException('hidden failure'), 'test');

            $contents = file_get_contents($logFile);
            self::assertSame('', $contents);
        } finally {
            ini_set('error_log', $previous === false ? '' : (string) $previous);
            @unlink($logFile);
        }
    }
}
