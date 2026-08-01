<?php

namespace Zion\WordPressLicense;

final class ReleaseManifestVerifier
{
    /** @param array<string, mixed> $manifest */
    public function canonicalize(array $manifest): string
    {
        $encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';

        $encoded = $encode(
            $this->sortKeys($manifest),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return is_string($encoded) ? $encoded : '';
    }

    /** @param array<string, mixed> $manifest */
    public function verify(array $manifest, string $signature, string $publicKey): bool
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $decodedSignature = base64_decode($signature, true);
        $decodedKey = base64_decode($publicKey, true);
        if (! is_string($decodedSignature) || strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        if (! is_string($decodedKey) || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $decodedSignature,
            $this->canonicalize($manifest),
            $decodedKey,
        );
    }

    /** @param array<string, mixed> $manifest */
    private function sortKeys(array $manifest): array
    {
        foreach ($manifest as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $manifest[$key] = $this->sortKeys($value);
            }
        }

        ksort($manifest);

        return $manifest;
    }
}
