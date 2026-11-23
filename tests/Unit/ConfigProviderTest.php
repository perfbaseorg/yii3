<?php

declare(strict_types=1);

namespace Perfbase\Yii3\Tests\Unit;

use Perfbase\SDK\FeatureFlags;
use Perfbase\Yii3\ConfigProvider;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    public function test_defaults_are_exposed(): void
    {
        $defaults = ConfigProvider::defaults();

        self::assertFalse($defaults['enabled']);
        self::assertSame(0.1, $defaults['sample_rate']);
        self::assertSame([...range(200, 299), ...range(500, 599)], $defaults['profile_http_status_codes']);
        self::assertSame(['*'], $defaults['include']['http']);
        self::assertSame(['serve', 'queue:listen', 'queue:listen-all', 'queue:run', 'queue:consume'], $defaults['exclude']['console']);
        self::assertSame(FeatureFlags::DefaultFlags, $defaults['flags']);
    }
}
