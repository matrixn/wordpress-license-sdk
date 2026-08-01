<?php

require_once __DIR__.'/../src/SecretStore.php';

use Zion\WordPressLicense\SecretStore;

it('encrypts and decrypts license secrets without storing plaintext', function (): void {
    $encrypt = new ReflectionMethod(SecretStore::class, 'encrypt');
    $decrypt = new ReflectionMethod(SecretStore::class, 'decrypt');
    $encrypt->setAccessible(true);
    $decrypt->setAccessible(true);

    $ciphertext = $encrypt->invoke(null, 'ZION-TEST-ABCDE-FGHIJ');

    expect($ciphertext)->toStartWith('gcm1:')
        ->and($ciphertext)->not->toContain('ZION-TEST-ABCDE-FGHIJ')
        ->and($decrypt->invoke(null, $ciphertext))->toBe('ZION-TEST-ABCDE-FGHIJ');
});
