<?php

namespace Zion\WordPressLicense;

use stdClass;

/** Integrates updates announced by Zion into WordPress's native Plugins screen. */
final class WordPressUpdateAdapter
{
    public function __construct(private readonly Config $config, private readonly LicenseManager $manager) {}

    public function register(): void
    {
        if (! function_exists('add_filter') || ! function_exists('plugin_basename')) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('site_transient_update_plugins', [$this, 'injectUpdate'], 20);
        add_filter('plugins_api', [$this, 'pluginInformation'], 20, 3);
        add_filter('auto_update_plugin', [$this, 'filterAutoUpdate'], 10, 2);
        add_filter('upgrader_pre_download', [$this, 'verifyPackageDownload'], 10, 3);
        add_action('load-plugins.php', [$this, 'refreshPluginScreen']);
    }

    public function injectUpdate(mixed $transient): mixed
    {
        if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
            return $transient;
        }

        $plugin = plugin_basename($this->config->pluginFile);
        $installed = (string) ($transient->checked[$plugin] ?? $this->manager->installedVersion());
        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }
        $this->manager->refreshForUpdateIfNeeded();
        $update = $this->availableUpdate($installed);

        if ($update === null) {
            unset($transient->response[$plugin]);

            return $transient;
        }

        $transient->response[$plugin] = $update;

        return $transient;
    }

    public function refreshPluginScreen(): void
    {
        if (! function_exists('wp_update_plugins') || ! function_exists('current_user_can') || ! current_user_can('update_plugins')) {
            return;
        }

        $this->manager->refreshIfDue();
        wp_update_plugins();
    }

    public function filterAutoUpdate(?bool $update, object $item): ?bool
    {
        if (($item->plugin ?? null) !== plugin_basename($this->config->pluginFile)) {
            return $update;
        }

        $configuration = $this->manager->runtimeConfiguration();
        $state = (string) ($this->manager->status()['license_state'] ?? 'unknown');
        if (! in_array($state, ['active', 'grace_period', 'free', 'updates_only'], true) || empty($configuration['auto_update_allowed'])) {
            return false;
        }

        return $update;
    }

    /**
     * @return array{available: bool, installed_version: string, latest_version: string, package_url: string, details_url: string, manifest: array<string, mixed>, manifest_verified: bool, blocked_reason: ?string}
     */
    public function status(bool $refresh = true): array
    {
        if ($refresh && function_exists('get_option')) {
            $licenseKey = $this->manager->licenseKey() ?? '';
            if ((is_string($licenseKey) && $licenseKey !== '') || $this->manager->isUpdatesOnly()) {
                try {
                    $this->manager->ping($licenseKey);
                } catch (\Throwable) {
                    // Keep the locally cached update state during outages.
                }
            }
        }

        $configuration = $this->manager->runtimeConfiguration();
        $installed = $this->manager->installedVersion();
        $latest = (string) ($configuration['latest_version'] ?? '');
        $package = (string) ($configuration['package_url'] ?? '');
        $available = array_key_exists('update_available', $configuration)
            ? (bool) $configuration['update_available']
            : ($latest !== '' && $installed !== '' && version_compare($latest, $installed, '>'));

        return [
            'available' => $available
                && $package !== ''
                && $installed !== ''
                && $latest !== ''
                && version_compare($latest, $installed, '>'),
            'installed_version' => $installed,
            'latest_version' => $latest,
            'package_url' => $package,
            'package_expires_at' => $configuration['package_expires_at'] ?? null,
            'details_url' => (string) ($configuration['details_url'] ?? rtrim($this->config->apiUrl, '/')),
            'changelog' => (string) ($configuration['changelog'] ?? ''),
            'manifest' => is_array($configuration['manifest'] ?? null) ? $configuration['manifest'] : [],
            'manifest_verified' => $this->manifestIsTrusted($configuration),
            'blocked_reason' => $this->updateBlockedReason($configuration, $installed, $latest),
            'auto_update_allowed' => (bool) ($configuration['auto_update_allowed'] ?? false),
            'auto_update_enabled' => $this->isAutoUpdateEnabled(),
            'sdk_version' => $this->manager->sdkVersion(),
            'last_update_at' => function_exists('get_option') ? get_option($this->lastUpdateOption(), null) : null,
        ];
    }

    /**
     * Runs WordPress's native upgrader with the signed package URL supplied by
     * the licensing server. The SDK never downloads a release directly when
     * the server version is equal to or lower than the installed version.
     */
    public function updateIfAvailable(bool $internal = false): mixed
    {
        if (! $internal && (! function_exists('current_user_can') || ! current_user_can('update_plugins'))) {
            return false;
        }

        $status = $this->status(! $internal);
        if (! $status['available']) {
            return false;
        }

        $plugin = plugin_basename($this->config->pluginFile);
        $activationState = $this->captureActivationState($plugin);

        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        }

        if (! class_exists('Automatic_Upgrader_Skin')) {
            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader-skin.php';
        }

        $skin = new \Automatic_Upgrader_Skin;
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->upgrade(
            $plugin,
            [
                'package' => $status['package_url'],
                'clear_destination' => true,
            ],
        );

        $updateSucceeded = $result !== false && ! is_wp_error($result);
        $reactivationError = null;
        if ($updateSucceeded && $activationState['active']) {
            $reactivationError = $this->restoreActivation($plugin, $activationState['network']);
            if ($reactivationError instanceof \WP_Error) {
                // The package was updated, but the plugin must not remain
                // silently disabled when it was active before the update.
                $result = $reactivationError;
            }
        }

        if ($updateSucceeded && ! $reactivationError instanceof \WP_Error && function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
            update_option($this->lastUpdateOption(), current_time('mysql'), false);
        }

        $this->manager->reportUpdateResult(
            $status['latest_version'],
            $updateSucceeded && ! $reactivationError instanceof \WP_Error,
            $reactivationError instanceof \WP_Error
                ? $reactivationError->get_error_message()
                : (is_wp_error($result) ? $result->get_error_message() : null),
        );

        return $result;
    }

    public function verifyPackageDownload(mixed $reply, string $package, mixed $upgrader): mixed
    {
        $status = $this->status(false);
        if ($reply !== false || $package === '' || ! $this->isAllowedPackageUrl($package)) {
            return $reply;
        }

        $downloadPackage = $package;
        $cachedPackage = (string) ($status['package_url'] ?? '');
        $expiresAt = isset($status['package_expires_at'])
            ? strtotime((string) $status['package_expires_at'])
            : false;
        if ($package !== $cachedPackage || $expiresAt === false || $expiresAt <= time()) {
            $this->manager->refreshForUpdateIfNeeded();
            $status = $this->status(false);
            $downloadPackage = (string) ($status['package_url'] ?? '');

            if ($downloadPackage === '' || empty($status['available']) || ! $this->isAllowedPackageUrl($downloadPackage)) {
                return new \WP_Error('zion_update_link_expired', 'Linkul temporar pentru actualizare a expirat. Reîmprospătează datele licenței și încearcă din nou.');
            }
        }

        $sha256 = (string) ($status['manifest']['sha256'] ?? '');
        if ($sha256 === '') {
            if (! $this->signedUpdatesRequired($status)) {
                return $reply;
            }

            return new \WP_Error('zion_missing_manifest_checksum', 'The Zion release manifest does not contain a checksum.');
        }

        if (! function_exists('download_url')) {
            return new \WP_Error('zion_download_api_unavailable', 'WordPress cannot download the update package.');
        }

        $temporaryFile = download_url($downloadPackage, 120);
        if (is_wp_error($temporaryFile)) {
            return $temporaryFile;
        }

        $actual = hash_file('sha256', $temporaryFile);
        if (! is_string($actual) || ! hash_equals(strtolower($sha256), strtolower($actual))) {
            @unlink($temporaryFile);

            return new \WP_Error('zion_update_checksum_mismatch', 'The downloaded Zion update failed checksum verification.');
        }

        return $temporaryFile;
    }

    public function pluginInformation(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || ! is_object($args) || ($args->slug ?? null) !== $this->config->productSlug) {
            return $result;
        }

        $configuration = $this->manager->runtimeConfiguration();
        $latest = (string) ($configuration['latest_version'] ?? '');

        if ($latest === '') {
            return $result;
        }

        return (object) [
            'name' => $this->config->displayName(),
            'slug' => $this->config->productSlug,
            'version' => $latest,
            'author' => $this->config->author(),
            'homepage' => (string) ($configuration['details_url'] ?? rtrim($this->config->apiUrl, '/')),
            'requires' => (string) ($configuration['requires_wordpress'] ?? ''),
            'requires_php' => (string) ($configuration['requires_php'] ?? ''),
            'sections' => [
                'description' => $this->config->displayName().' receives private updates from Zion License Server.',
                'changelog' => (string) ($configuration['changelog'] ?? 'A new version '.$latest.' is available through Zion License Server.'),
            ],
        ];
    }

    private function availableUpdate(string $installed): ?stdClass
    {
        $configuration = $this->manager->runtimeConfiguration();
        $latest = (string) ($configuration['latest_version'] ?? '');
        $available = array_key_exists('update_available', $configuration)
            ? (bool) $configuration['update_available']
            : ($latest !== '' && $installed !== '' && version_compare($latest, $installed, '>'));
        $package = (string) ($configuration['package_url'] ?? '');

        if (! $available || $latest === '' || $package === '' || $installed === '' || version_compare($latest, $installed, '<=')) {
            return null;
        }

        if (! $this->isCompatible($configuration) || ! $this->manifestIsTrusted($configuration) || ! $this->isAllowedPackageUrl($package)) {
            return null;
        }

        return (object) [
            'id' => 'zion-license/'.$this->config->productSlug,
            'slug' => $this->config->productSlug,
            'plugin' => plugin_basename($this->config->pluginFile),
            'new_version' => $latest,
            'url' => (string) ($configuration['details_url'] ?? rtrim($this->config->apiUrl, '/')),
            'package' => $package,
            'requires' => (string) ($configuration['requires_wordpress'] ?? ''),
            'requires_php' => (string) ($configuration['requires_php'] ?? ''),
            'sections' => ['changelog' => (string) ($configuration['changelog'] ?? '')],
            'zion_manifest' => is_array($configuration['manifest'] ?? null) ? $configuration['manifest'] : [],
            'icons' => [],
            'banners' => [],
        ];
    }

    private function isAutoUpdateEnabled(): bool
    {
        if (! function_exists('get_site_option')) {
            return false;
        }

        return in_array(
            plugin_basename($this->config->pluginFile),
            (array) get_site_option('auto_update_plugins', []),
            true,
        );
    }

    private function lastUpdateOption(): string
    {
        return 'zion_license_last_update_'.md5($this->config->storageKey());
    }

    /** @return array{active: bool, network: bool} */
    private function captureActivationState(string $plugin): array
    {
        if (! function_exists('activate_plugin')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $network = function_exists('is_multisite')
            && is_multisite()
            && function_exists('is_plugin_active_for_network')
            && is_plugin_active_for_network($plugin);
        $active = $network
            || (function_exists('is_plugin_active') && is_plugin_active($plugin));

        return [
            'active' => $active,
            'network' => $network,
        ];
    }

    private function restoreActivation(string $plugin, bool $network): ?\WP_Error
    {
        $alreadyActive = $network
            ? (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin))
            : (function_exists('is_plugin_active') && is_plugin_active($plugin));
        if ($alreadyActive) {
            return null;
        }

        if (! function_exists('activate_plugin')) {
            return new \WP_Error(
                'zion_reactivation_unavailable',
                'Pluginul a fost actualizat, dar WordPress nu poate încărca API-ul de reactivare.',
            );
        }

        $activation = activate_plugin($plugin, '', $network, false);
        if (is_wp_error($activation)) {
            return new \WP_Error(
                'zion_reactivation_failed',
                'Pluginul a fost actualizat, dar reactivarea automată a eșuat: '.$activation->get_error_message(),
                ['previous' => $activation],
            );
        }

        return null;
    }

    /** @param array<string, mixed> $configuration */
    private function manifestIsTrusted(array $configuration): bool
    {
        $manifest = $configuration['manifest'] ?? null;
        $signature = (string) ($configuration['manifest_signature'] ?? '');
        $publicKey = $this->config->updatePublicKey;

        if (! is_array($manifest)) {
            return $publicKey === '' && ! $this->signedUpdatesRequired($configuration);
        }

        if ($publicKey === '') {
            return ! $this->signedUpdatesRequired($configuration);
        }

        if ($this->config->updateKeyId !== '' && (string) ($configuration['manifest_key_id'] ?? '') !== $this->config->updateKeyId) {
            return false;
        }

        return (new ReleaseManifestVerifier)->verify($manifest, $signature, $publicKey);
    }

    /** @param array<string, mixed> $configuration */
    private function isCompatible(array $configuration): bool
    {
        $manifest = is_array($configuration['manifest'] ?? null) ? $configuration['manifest'] : $configuration;
        $php = (string) ($manifest['requires_php'] ?? '');
        $wordpress = (string) ($manifest['requires_wordpress'] ?? '');
        $minimumSdk = (string) ($manifest['minimum_sdk_version'] ?? '');

        if ($php !== '' && version_compare(PHP_VERSION, $php, '<')) {
            return false;
        }
        if ($wordpress !== '' && function_exists('get_bloginfo') && version_compare((string) get_bloginfo('version'), $wordpress, '<')) {
            return false;
        }

        return $minimumSdk === '' || version_compare($this->manager->sdkVersion(), $minimumSdk, '>=');
    }

    /** @param array<string, mixed> $configuration */
    private function updateBlockedReason(array $configuration, string $installed, string $latest): ?string
    {
        if (! $this->isCompatible($configuration)) {
            return 'incompatible_environment';
        }
        if (! $this->manifestIsTrusted($configuration)) {
            return 'untrusted_manifest';
        }
        if (! $this->isAllowedPackageUrl((string) ($configuration['package_url'] ?? ''))) {
            return 'disallowed_package_host';
        }
        if ($latest !== '' && $installed !== '' && version_compare($latest, $installed, '<=')) {
            return 'up_to_date';
        }

        return null;
    }

    private function isAllowedPackageUrl(string $package): bool
    {
        $packageUrl = parse_url($package);
        $apiUrl = parse_url($this->config->apiUrl);

        return is_array($packageUrl)
            && is_array($apiUrl)
            && ($packageUrl['scheme'] ?? null) === 'https'
            && strtolower((string) ($packageUrl['host'] ?? '')) === strtolower((string) ($apiUrl['host'] ?? ''));
    }

    /** @param array<string, mixed> $configuration */
    private function signedUpdatesRequired(array $configuration): bool
    {
        return $this->config->requireSignedUpdates
            || (bool) ($configuration['require_signed_updates'] ?? false);
    }
}
