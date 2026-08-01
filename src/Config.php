<?php

namespace Zion\WordPressLicense;

use InvalidArgumentException;

final class Config
{
    public function __construct(
        public readonly string $apiUrl,
        public readonly string $productSlug,
        public readonly string $pluginFile,
        public readonly string $productKey,
        public readonly bool $sendAdminEmail = false,
        public readonly string $pluginName = '',
        public readonly string $textDomain = '',
        public readonly ?string $licenseOption = null,
        public readonly OfflinePolicy $offlinePolicy = OfflinePolicy::Lenient,
        public readonly string $updatePublicKey = '',
        public readonly string $updateKeyId = '',
        public readonly bool $requireSignedUpdates = false,
    ) {}

    /** Validates the values supplied by the plugin bootstrap. */
    public function validate(): void
    {
        $url = parse_url($this->apiUrl);
        $path = rtrim((string) ($url['path'] ?? ''), '/');

        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host']) || $path !== '/api/v1') {
            throw new InvalidArgumentException('The licensing API URL must be an HTTPS URL ending in /api/v1.');
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->productSlug) !== 1) {
            throw new InvalidArgumentException('The licensing product slug must contain lowercase letters, numbers, and hyphens only.');
        }

        if (! is_file($this->pluginFile) || ! is_readable($this->pluginFile)) {
            throw new InvalidArgumentException('The plugin bootstrap must pass its readable main file through pluginFile.');
        }
    }

    public function licenseOption(): string
    {
        return $this->licenseOption ?: 'zion_license_key_'.sanitize_key($this->productSlug);
    }

    public function displayName(): string
    {
        return $this->pluginName !== '' ? $this->pluginName : $this->productSlug;
    }

    public function author(): string
    {
        $contents = @file_get_contents($this->pluginFile) ?: '';
        preg_match('/^[ \t\/*#@]*Author:\s*(.+)$/mi', $contents, $matches);

        return isset($matches[1]) && trim($matches[1]) !== '' ? trim($matches[1]) : 'Zion';
    }

    public function textDomain(): string
    {
        if ($this->textDomain !== '') {
            return $this->textDomain;
        }

        $contents = @file_get_contents($this->pluginFile) ?: '';
        preg_match('/^[ \t\/*#@]*Text Domain:\s*(.+)$/mi', $contents, $matches);

        return isset($matches[1]) && trim($matches[1]) !== '' ? trim($matches[1]) : 'zion-wordpress-license-sdk';
    }

    public function licenseProductCode(): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/i', '-', $this->productSlug) ?: $this->productSlug;

        return strtoupper(substr($slug, 0, 8));
    }

    public function licenseExample(): string
    {
        return 'ZION-'.$this->licenseProductCode().'-0BVAU-XQCFB';
    }

    public function licenseLength(): int
    {
        return strlen($this->licenseExample());
    }

    public function licensePattern(): string
    {
        return '/^ZION-'.preg_quote($this->licenseProductCode(), '/').'-[A-Z0-9]{5}-[A-Z0-9]{5}$/';
    }
}
