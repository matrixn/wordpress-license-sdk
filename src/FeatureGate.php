<?php

namespace Zion\WordPressLicense;

final class FeatureGate
{
    /** @param array<string, bool> $entitlements */
    public function __construct(private readonly array $entitlements = []) {}

    public function allows(string $feature): bool
    {
        return ($this->entitlements[$feature] ?? false) === true;
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return $this->entitlements;
    }
}
