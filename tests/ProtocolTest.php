<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zion\WordPressLicense\LicenseManager;
use Zion\WordPressLicense\Protocol;

final class ProtocolTest extends TestCase
{
    public function test_it_exposes_a_stable_protocol_contract_independent_of_the_sdk_version(): void
    {
        self::assertSame('1.0', Protocol::VERSION);
        self::assertSame('X-Zion-Protocol-Version', Protocol::HEADER);
        self::assertSame('0.2.0', Protocol::MINIMUM_SDK_VERSION);
        self::assertTrue(version_compare(LicenseManager::VERSION, Protocol::MINIMUM_SDK_VERSION, '>='));
    }
}
