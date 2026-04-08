<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit\Support;

use Perfbase\Yii3\Support\FilterMatcher;
use PHPUnit\Framework\TestCase;

class FilterMatcherTest extends TestCase
{
    public function test_matches_wildcard_filter(): void
    {
        self::assertTrue(FilterMatcher::matches(['/articles/123'], ['*']));
        self::assertTrue(FilterMatcher::matches(['/articles/123'], ['.*']));
    }

    public function test_matches_regex_filter(): void
    {
        self::assertTrue(FilterMatcher::matches(['GET /articles/123'], ['/^GET \\/articles\\/\\d+$/']));
        self::assertFalse(FilterMatcher::matches(['POST /articles/123'], ['/^GET \\/articles\\/\\d+$/']));
    }

    public function test_matches_glob_filter(): void
    {
        self::assertTrue(FilterMatcher::matches(['console app:sync'], ['console*']));
        self::assertFalse(FilterMatcher::matches(['console app:sync'], ['http*']));
    }

    public function test_passes_filters_requires_include_and_respects_exclude(): void
    {
        self::assertTrue(FilterMatcher::passesFilters(['GET /articles/123'], ['GET *'], []));
        self::assertFalse(FilterMatcher::passesFilters(['GET /admin'], ['GET *'], ['GET /admin']));
        self::assertFalse(FilterMatcher::passesFilters(['GET /articles/123'], [], []));
    }
}
