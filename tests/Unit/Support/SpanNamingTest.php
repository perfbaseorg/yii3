<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit\Support;

use Nyholm\Psr7\ServerRequest;
use Perfbase\Yii3\Support\SpanNaming;
use PHPUnit\Framework\TestCase;

class SpanNamingTest extends TestCase
{
    public function test_http_prefers_route_template(): void
    {
        $request = new ServerRequest('GET', 'https://example.com/articles/42');

        self::assertSame('http.GET./articles/{id}', SpanNaming::forHttp($request, '/articles/{id}', 'app_article'));
    }

    public function test_http_falls_back_to_route_name_then_path(): void
    {
        $request = new ServerRequest('POST', 'https://example.com/articles/42');

        self::assertSame('http.POST.app_article', SpanNaming::forHttp($request, null, 'app_article'));
        self::assertSame('http.POST./articles/42', SpanNaming::forHttp($request, null, null));
    }

    public function test_http_adds_leading_slash_to_route_template_when_missing(): void
    {
        $request = new ServerRequest('GET', 'https://example.com/articles/42');

        self::assertSame('http.GET./articles/{id}', SpanNaming::forHttp($request, 'articles/{id}', null));
    }

    public function test_console_span_name_is_stable(): void
    {
        self::assertSame('console.app:sync', SpanNaming::forConsole('app:sync'));
    }
}
