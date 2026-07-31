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
    }

    public function injectUpdate(mixed $transient): mixed
    {
        if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
            return $transient;
        }

        $plugin = plugin_basename($this->config->pluginFile);
        $installed = (string) ($transient->checked[$plugin] ?? $this->manager->installedVersion());
        if ($this->manager->runtimeConfiguration() === [] && get_option($this->config->licenseOption(), '')) {
            try {
                $this->manager->ping((string) get_option($this->config->licenseOption()));
            } catch (\Throwable) {
                // Keep WordPress updates stable when the licensing server is temporarily unavailable.
            }
        }
        $update = $this->availableUpdate($installed);

        if ($update === null) {
            unset($transient->response[$plugin]);

            return $transient;
        }

        $transient->response[$plugin] = $update;

        return $transient;
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
        $available = (bool) ($configuration['update_available'] ?? false);
        $package = (string) ($configuration['package_url'] ?? '');

        if (! $available || $latest === '' || $package === '' || $installed === '' || version_compare($latest, $installed, '<=')) {
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
            'icons' => [],
            'banners' => [],
        ];
    }
}
