<?php

namespace Zion\WordPressLicense;

final class HeartbeatLock
{
    public function __construct(private readonly string $key) {}

    public function acquire(int $ttl = 60): bool
    {
        if (function_exists('wp_cache_add') && wp_cache_add($this->key, (string) time(), 'zion_license', $ttl)) {
            return true;
        }

        if (! function_exists('get_transient') || ! function_exists('set_transient')) {
            return true;
        }

        if (get_transient($this->key) !== false) {
            return false;
        }

        set_transient($this->key, '1', $ttl);

        return true;
    }

    public function release(): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($this->key, 'zion_license');
        }
        if (function_exists('delete_transient')) {
            delete_transient($this->key);
        }
    }
}
