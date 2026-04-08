<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Fixtures;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class ArrayContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private array $entries)
    {
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }

        $entry = $this->entries[$id];
        if (is_callable($entry)) {
            return $entry($this);
        }

        return $entry;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
