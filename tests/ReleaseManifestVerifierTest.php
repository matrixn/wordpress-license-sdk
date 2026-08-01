<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zion\WordPressLicense\ReleaseManifestVerifier;

final class ReleaseManifestVerifierTest extends TestCase
{
    public function test_it_verifies_the_signed_manifest_and_rejects_tampering(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $manifest = [
            'product_slug' => 'demo-plugin',
            'version' => '1.2.3',
            'sha256' => hash('sha256', 'zip'),
        ];
        $verifier = new ReleaseManifestVerifier;
        $signature = base64_encode(sodium_crypto_sign_detached(
            $verifier->canonicalize($manifest),
            sodium_crypto_sign_secretkey($keyPair),
        ));
        $publicKey = base64_encode(sodium_crypto_sign_publickey_from_secretkey(
            sodium_crypto_sign_secretkey($keyPair),
        ));

        self::assertTrue($verifier->verify($manifest, $signature, $publicKey));
        self::assertFalse($verifier->verify([...$manifest, 'version' => '1.2.4'], $signature, $publicKey));
    }
}
