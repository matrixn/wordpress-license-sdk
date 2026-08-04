<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zion\WordPressLicense\Config;

final class LicenseProductCodeTest extends TestCase
{
    public function test_legacy_product_code_is_accepted_without_changing_the_canonical_slug(): void
    {
        $config = new Config(
            apiUrl: 'https://licenses.example.test/api/v1',
            productSlug: 'zion-woocommerce-multicurrency',
            pluginFile: __FILE__,
            productKey: 'public-key',
            additionalLicenseProductCodes: ['ZION-MUL'],
        );

        self::assertSame('zion-woocommerce-multicurrency', $config->productSlug);
        self::assertSame(25, $config->licenseLength());
        self::assertSame(1, preg_match($config->licensePattern(), 'ZION-ZION-MUL-XMUJO-YXQKB'));
        self::assertSame(0, preg_match($config->licensePattern(), 'ZION-ZION-OLD-XMUJO-YXQKB'));
    }
}