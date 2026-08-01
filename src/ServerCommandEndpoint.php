<?php

namespace Zion\WordPressLicense;

use WP_Error;
use WP_REST_Request;

/** Receives authenticated server-to-plugin commands through the WordPress REST API. */
final class ServerCommandEndpoint
{
    public function __construct(private readonly Config $config, private readonly LicenseManager $manager) {}

    public function register(): void
    {
        if (! function_exists('add_action') || ! function_exists('register_rest_route')) {
            return;
        }

        add_action('rest_api_init', function (): void {
            register_rest_route('zion-license/v1', '/command', [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'authorize'],
            ]);
        });
    }

    public function authorize(WP_REST_Request $request): bool|WP_Error
    {
        $timestamp = (string) $request->get_header('X-Zion-Timestamp');
        $nonce = (string) $request->get_header('X-Zion-Nonce');
        $signature = (string) $request->get_header('X-Zion-Signature');

        if (! ctype_digit($timestamp) || $nonce === '' || $signature === '' || abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('zion_invalid_signature', 'Invalid or expired Zion server signature.', ['status' => 401]);
        }

        $nonceKey = 'zion_license_nonce_'.md5($this->config->productSlug.$nonce);
        if (function_exists('get_transient') && get_transient($nonceKey) !== false) {
            return new WP_Error('zion_replayed_request', 'This Zion server request was already processed.', ['status' => 409]);
        }

        $expected = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, $request->get_body()]), $this->manager->callbackSecret());
        if (! hash_equals($expected, $signature)) {
            return new WP_Error('zion_invalid_signature', 'Invalid Zion server signature.', ['status' => 401]);
        }

        if (function_exists('set_transient')) {
            set_transient($nonceKey, '1', 10 * MINUTE_IN_SECONDS);
        }

        return true;
    }

    public function handle(WP_REST_Request $request): array|WP_Error
    {
        $payload = $request->get_json_params();
        if (! is_array($payload) || ($payload['product_slug'] ?? null) !== $this->config->productSlug) {
            return new WP_Error('zion_invalid_command', 'The command does not target this product.', ['status' => 422]);
        }

        $configuration = $payload['configuration'] ?? [];
        if (! is_array($configuration)) {
            return new WP_Error('zion_invalid_command', 'Invalid command configuration.', ['status' => 422]);
        }

        $configuration = $this->sanitizeConfiguration($configuration);
        if ($configuration === null) {
            return new WP_Error('zion_invalid_command', 'Invalid command configuration.', ['status' => 422]);
        }

        $this->manager->storeRuntimeConfiguration($configuration);

        $autoUpdate = false;
        $command = (string) ($payload['command'] ?? '');
        if (in_array($command, ['update_available', 'sync_configuration', 'force_update'], true) && function_exists('wp_update_plugins')) {
            if (function_exists('delete_site_transient')) {
                delete_site_transient('update_plugins');
            }

            wp_update_plugins();

            $plugin = plugin_basename($this->config->pluginFile);
            $enabled = in_array($plugin, (array) get_site_option('auto_update_plugins', []), true)
                || (function_exists('wp_is_auto_update_enabled_for_type') && wp_is_auto_update_enabled_for_type('plugin'));
            if (($enabled || $command === 'force_update') && ! empty($configuration['update_available']) && ! empty($configuration['auto_update_allowed'])) {
                $autoUpdate = $this->manager->updateIfAvailable(true) !== false;
            }
        }

        return [
            'received' => true,
            'sdk_version' => LicenseManager::VERSION,
            'plugin_version' => $this->manager->installedVersion(),
            'update_available' => (bool) ($configuration['update_available'] ?? false),
            'latest_version' => $configuration['latest_version'] ?? null,
            'auto_update_attempted' => $autoUpdate,
        ];
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed>|null */
    private function sanitizeConfiguration(array $configuration): ?array
    {
        $result = [];
        foreach (['ping_interval_hours', 'latest_version', 'server_version', 'sdk_latest_version', 'details_url', 'package_url', 'package_expires_at', 'changelog'] as $key) {
            if (array_key_exists($key, $configuration) && ! is_scalar($configuration[$key]) && $configuration[$key] !== null) {
                return null;
            }
            if (array_key_exists($key, $configuration)) {
                $result[$key] = is_string($configuration[$key])
                    ? substr($configuration[$key], 0, $key === 'changelog' ? 200000 : 2048)
                    : $configuration[$key];
            }
        }

        foreach (['updates_paused', 'released', 'zip_available', 'update_available', 'auto_update_allowed', 'sdk_update_available'] as $key) {
            if (array_key_exists($key, $configuration)) {
                $result[$key] = (bool) $configuration[$key];
            }
        }

        if (isset($configuration['entitlements'])) {
            if (! is_array($configuration['entitlements']) || count($configuration['entitlements']) > 100) {
                return null;
            }
            $result['entitlements'] = array_map(static fn (mixed $value): bool => $value === true, $configuration['entitlements']);
        }

        if (($result['ping_interval_hours'] ?? null) !== null && ! in_array((int) $result['ping_interval_hours'], [1, 2, 6, 12, 24], true)) {
            return null;
        }

        foreach (['details_url', 'package_url'] as $key) {
            if (isset($result[$key]) && (! is_string($result[$key]) || ! str_starts_with($result[$key], 'https://'))) {
                return null;
            }
        }

        return $result;
    }
}
