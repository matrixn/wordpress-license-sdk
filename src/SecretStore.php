<?php

namespace Zion\WordPressLicense;

use RuntimeException;

/** Stores the license key encrypted at rest while migrating legacy plaintext options. */
final class SecretStore
{
    public static function read(string $option): ?string
    {
        if (! function_exists('get_option')) {
            return null;
        }

        $value = get_option($option, null);
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decrypted = self::decrypt($value);
        if ($decrypted !== null) {
            return $decrypted;
        }

        self::write($option, $value);

        return $value;
    }

    public static function write(string $option, string $value): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option($option, self::encrypt($value), false);
    }

    private static function encrypt(string $value): string
    {
        if (! function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to protect the license key.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (! is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException('The license key could not be encrypted.');
        }

        return 'gcm1:'.base64_encode($iv.$tag.$ciphertext);
    }

    private static function decrypt(string $value): ?string
    {
        if (! str_starts_with($value, 'gcm1:') || ! function_exists('openssl_decrypt')) {
            return null;
        }

        $decoded = base64_decode(substr($value, 5), true);
        if (! is_string($decoded) || strlen($decoded) < 29) {
            return null;
        }

        $plaintext = openssl_decrypt(
            substr($decoded, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($decoded, 0, 12),
            substr($decoded, 12, 16),
        );

        return is_string($plaintext) ? $plaintext : null;
    }

    private static function key(): string
    {
        $salt = function_exists('wp_salt')
            ? wp_salt('auth')
            : (defined('AUTH_KEY') ? AUTH_KEY : 'zion-license-sdk-local-key');

        return hash('sha256', (string) $salt, true);
    }
}
