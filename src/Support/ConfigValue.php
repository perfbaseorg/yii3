<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Support;

/**
 * Normalises `params['perfbase']` values, which often come from environment
 * variables as strings ("12", "false", ""), into the types the SDK requires.
 */
final class ConfigValue
{
    /**
     * @param mixed $value
     */
    public static function bool($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    public static function int($value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    public static function stringOrNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     */
    public static function string($value, string $default = ''): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }
}
