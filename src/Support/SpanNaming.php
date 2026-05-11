<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Support;

use Psr\Http\Message\ServerRequestInterface;

class SpanNaming
{
    public static function forHttp(ServerRequestInterface $request, ?string $routeTemplate, ?string $routeName): string
    {
        return 'http';
    }

    public static function forConsole(string $command): string
    {
        return 'artisan';
    }

    private static function ensureLeadingSlash(string $value): string
    {
        $trimmed = ltrim($value, '/');

        return '/' . $trimmed;
    }
}
