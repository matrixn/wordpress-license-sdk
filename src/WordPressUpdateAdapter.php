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
        add_filter('plugins_api', [$this, 'pluginInformation'], 20, 3);
        add_filter('auto_update_plugin', [$this, 'filterAutoUpdate'], 10, 2);
        add_action('load-plugins.php', [$this, 'refreshPluginScreen']);
    }

    public function injectUpdate(mixed $transient): mixed
    {
        if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
            return $transient;
        }

        $plugin = plugin_basename($this->config->pluginFile);
        $installed = (string) ($transient->checked[$plugin] ?? $this->manager->installedVersion());
        $this->manager->refreshIfDue();
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

    public function filterAutoUpdate(bool|null $update, object $item): bool|null
    {
        if (($item->plugin ?? null) !== plugin_basename($this->config->pluginFile)) {
            return $update;
        }

        $configuration = $this->manager->runtimeConfiguration();
        $state = (string) ($this->manager->status()['license_state'] ?? 'unknown');
        if (! in_array($state, ['active', 'free'], true) || empty($configuration['auto_update_allowed'])) {
            return false;
        }

        return $update;
    }

    /**
     * @return array{available: bool, installed_version: string, latest_version: string, package_url: string, details_url: string}
     */
    public function status(bool $refresh = true): array
    {
        if ($refresh && function_exists('get_option')) {
            $licenseKey = get_option($this->config->licenseOption(), '');
            if (is_string($licenseKey) && $licenseKey !== '') {
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

        return [
            'available' => $package !== ''
                && $installed !== ''
                && $latest !== ''
                && version_compare($latest, $installed, '>'),
            'installed_version' => $installed,
            'latest_version' => $latest,
            'package_url' => $package,
            'details_url' => (string) ($configuration['details_url'] ?? rtrim($this->config->apiUrl, '/')),
            'changelog' => (string) ($configuration['changelog'] ?? ''),
            'auto_update_allowed' => (bool) ($configuration['auto_update_allowed'] ?? false),
            'auto_update_enabled' => $this->isAutoUpdateEnabled(),
            'sdk_version' => \Zion\WordPressLicense\LicenseManager::VERSION,
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

        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        }

        if (! class_exists('Automatic_Upgrader_Skin')) {
            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader-skin.php';
        }

        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $result = $upgrader->upgrade(
            plugin_basename($this->config->pluginFile),
            [
                'package' => $status['package_url'],
                'clear_destination' => true,
            ],
        );

        if ($result !== false && ! is_wp_error($result) && function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
            update_option($this->lastUpdateOption(), current_time('mysql'), false);
        }

        return $result;
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
                'changelog' => 'A new version '.$latest.' is available through Zion License Server.',
            ],
        ];
    }

    /** @return stdClass|null */
    private function availableUpdate(string $installed): ?stdClass
    {
        $configuration = $this->manager->runtimeConfiguration();
        $latest = (string) ($configuration['latest_version'] ?? '');
        $allowed = (bool) ($configuration['auto_update_allowed'] ?? false);
        $available = (bool) ($configuration['update_available'] ?? false)
            || ($latest !== '' && $installed !== '' && version_compare($latest, $installed, '>'));
        $package = (string) ($configuration['package_url'] ?? '');

        if (! $allowed || ! $available || $latest === '' || $package === '' || $installed === '' || version_compare($latest, $installed, '<=')) {
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
        return 'zion_license_last_update_'.md5($this->config->productSlug);
    }
}
