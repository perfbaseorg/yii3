<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Profiling;

use Perfbase\SDK\Perfbase;
use Perfbase\Yii3\Support\PerfbaseClientProvider;
use Perfbase\Yii3\Support\PerfbaseErrorHandler;

abstract class AbstractProfiler
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @var array<string, string> */
    protected array $attributes = [];

    private ?Perfbase $client = null;
    private bool $started = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected string $spanName,
        protected PerfbaseClientProvider $clientProvider,
        protected PerfbaseErrorHandler $errorHandler,
        array $config,
        protected string $environment,
        protected string $appVersion
    ) {
        $this->config = $config;
    }

    public function startProfiling(): void
    {
        if ($this->started) {
            return;
        }

        try {
            $this->client = $this->clientProvider->getClient();
            if ($this->client === null) {
                return;
            }

            if (!$this->passesSampleRateCheck() || !$this->shouldProfile()) {
                return;
            }

            if (!$this->client->isExtensionAvailable()) {
                return;
            }

            $this->client->startTraceSpan($this->spanName);
            $this->setDefaultAttributes();
            $this->started = true;
        } catch (\Throwable $throwable) {
            $this->started = false;
            $this->errorHandler->handle($throwable, 'start_profiling');
        }
    }

    public function stopProfiling(): void
    {
        if (!$this->started || $this->client === null) {
            return;
        }

        try {
            foreach ($this->attributes as $key => $value) {
                $this->client->setAttribute($key, $value);
            }

            if (!$this->client->stopTraceSpan($this->spanName)) {
                return;
            }

            if (!$this->shouldSubmitTrace()) {
                $this->client->reset();
                return;
            }

            $result = $this->client->submitTrace();
            if (!$result->isSuccess()) {
                $this->errorHandler->handle(
                    new \RuntimeException(sprintf(
                        'Trace submission failed (%s): %s',
                        $result->getStatus(),
                        $result->getMessage()
                    )),
                    'submit_trace'
                );
            }
        } catch (\Throwable $throwable) {
            $this->errorHandler->handle($throwable, 'stop_profiling');
        } finally {
            $this->started = false;
        }
    }

    public function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    public function setException(\Throwable $throwable): void
    {
        $this->setAttribute('exception', $throwable->getMessage());
    }

    public function setExitCode(int $exitCode): void
    {
        $this->setAttribute('exit_code', (string) $exitCode);
    }

    public function hasStarted(): bool
    {
        return $this->started;
    }

    protected function setDefaultAttributes(): void
    {
        $this->setAttributes([
            'hostname' => gethostname() ?: '',
            'environment' => $this->environment,
            'app_version' => $this->appVersion,
            'php_version' => phpversion() ?: '',
        ]);
    }

    protected function passesSampleRateCheck(): bool
    {
        $sampleRate = $this->config['sample_rate'] ?? 0.1;

        if (!is_numeric($sampleRate)) {
            throw new \RuntimeException('Configured perfbase sample_rate must be numeric.');
        }

        $sampleRate = (float) $sampleRate;

        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new \RuntimeException('Configured perfbase sample_rate must be between 0.0 and 1.0.');
        }

        if ($sampleRate === 1.0) {
            return true;
        }

        if ($sampleRate === 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $sampleRate;
    }

    abstract protected function shouldProfile(): bool;

    protected function shouldSubmitTrace(): bool
    {
        return true;
    }
}
