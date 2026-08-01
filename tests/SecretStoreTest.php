<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zion\WordPressLicense\SecretStore;

final class SecretStoreTest extends TestCase
{
    public function test_it_encrypts_and_decrypts_license_secrets_without_storing_plaintext(): void
    {
        $encrypt = new ReflectionMethod(SecretStore::class, 'encrypt');
        $decrypt = new ReflectionMethod(SecretStore::class, 'decrypt');
        $encrypt->setAccessible(true);
        $decrypt->setAccessible(true);

        $ciphertext = $encrypt->invoke(null, 'ZION-TEST-ABCDE-FGHIJ');

        self::assertStringStartsWith('gcm1:', $ciphertext);
        self::assertStringNotContainsString('ZION-TEST-ABCDE-FGHIJ', $ciphertext);
        self::assertSame(
            'ZION-TEST-ABCDE-FGHIJ',
            $decrypt->invoke(null, $ciphertext),
        );
    }
}
