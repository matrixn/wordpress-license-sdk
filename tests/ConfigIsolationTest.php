<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zion\WordPressLicense\Config;

final class ConfigIsolationTest extends TestCase
{
    public function test_local_storage_identity_is_unique_per_plugin_file(): void
    {
        $first = new Config(
            apiUrl: 'https://licenses.example.test/api/v1',
            productSlug: 'same-product',
            pluginFile: __FILE__,
            productKey: 'public-key',
        );
        $second = new Config(
            apiUrl: 'https://licenses.example.test/api/v1',
            productSlug: 'same-product',
            pluginFile: __DIR__.'/OtherPlugin.php',
            productKey: 'public-key',
        );

        self::assertNotSame($first->storageKey(), $second->storageKey());
        self::assertNotSame($first->licenseOption(), $second->licenseOption());
    }

    public function test_explicit_sdk_version_is_kept_per_plugin(): void
    {
        $config = new Config(
            apiUrl: 'https://licenses.example.test/api/v1',
            productSlug: 'my-product',
            pluginFile: __FILE__,
            productKey: 'public-key',
            sdkVersion: '0.4.4',
        );

        self::assertSame('0.4.4', $config->installedSdkVersion());
    }
}
