<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Support;

use Psr\Http\Message\ServerRequestInterface;

class SpanNaming
{
    public static function forHttp(ServerRequestInterface $request, ?string $routeTemplate, ?string $routeName): string
    {
        $identifier = $routeTemplate;
        if ($identifier === null || $identifier === '') {
            $identifier = $routeName ?: self::ensureLeadingSlash($request->getUri()->getPath());
        } elseif ($identifier[0] !== '/') {
            $identifier = self::ensureLeadingSlash($identifier);
        }

        return sprintf('http.%s.%s', $request->getMethod(), $identifier);
    }

    public static function forConsole(string $command): string
    {
        return sprintf('console.%s', $command);
    }

    private static function ensureLeadingSlash(string $value): string
    {
        $trimmed = ltrim($value, '/');

        return '/' . $trimmed;
    }
}
