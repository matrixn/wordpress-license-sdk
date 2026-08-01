<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zion\WordPressLicense\FeatureGate;

final class FeatureGateTest extends TestCase
{
    public function test_it_allows_only_explicitly_enabled_features(): void
    {
        $gate = new FeatureGate([
            'analytics' => true,
            'white_label' => false,
        ]);

        self::assertTrue($gate->allows('analytics'));
        self::assertFalse($gate->allows('white_label'));
        self::assertFalse($gate->allows('unknown_feature'));
        self::assertSame([
            'analytics' => true,
            'white_label' => false,
        ], $gate->all());
    }
}
