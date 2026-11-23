<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Support;

use Perfbase\SDK\Config;
use Perfbase\SDK\FeatureFlags;
use Perfbase\SDK\Perfbase;

class PerfbaseClientProvider
{
    private const DEFAULT_API_URL = 'https://ingress.perfbase.cloud';

    /** @var array<string, mixed> */
    private array $config;

    /** @var callable */
    private $clientFactory;

    private ?Perfbase $client = null;
    private bool $initialized = false;

    /**
     * @param array<string, mixed> $config
     * @param callable|null $clientFactory
     */
    public function __construct(
        array $config,
        private PerfbaseErrorHandler $errorHandler,
        $clientFactory = null
    ) {
        $this->config = $config;
        $this->clientFactory = $clientFactory ?? static fn (Config $config): Perfbase => new Perfbase($config);
    }

    public function getClient(): ?Perfbase
    {
        if ($this->initialized) {
            return $this->client;
        }

        $this->initialized = true;

        try {
            // Config::fromArray() is type-strict; values from env vars arrive as strings.
            $sdkConfig = Config::fromArray([
                'api_key' => ConfigValue::stringOrNull($this->config['api_key'] ?? null),
                'api_url' => ConfigValue::stringOrNull($this->config['api_url'] ?? null) ?? self::DEFAULT_API_URL,
                'flags' => ConfigValue::int($this->config['flags'] ?? null, FeatureFlags::DefaultFlags),
                'timeout' => ConfigValue::int($this->config['timeout'] ?? null, 10),
                'proxy' => ConfigValue::stringOrNull($this->config['proxy'] ?? null),
            ]);

            $factory = $this->clientFactory;
            $client = $factory($sdkConfig);

            if (!$client instanceof Perfbase) {
                throw new \RuntimeException('Perfbase client factory must return a Perfbase instance.');
            }

            $this->client = $client;
        } catch (\Throwable $throwable) {
            $this->client = null;
            $this->errorHandler->handle($throwable, 'sdk_init');
        }

        return $this->client;
    }
}
