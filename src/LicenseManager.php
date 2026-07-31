<?php

namespace Zion\WordPressLicense;

final class LicenseManager
{
    public const VERSION = '0.1.10';

    private ?LicensePrompt $prompt = null;

    public function __construct(private readonly Config $config, private readonly WordPressHttpClient $http = new WordPressHttpClient)
    {
        $this->config->validate();

        if (! function_exists('add_action') || ! function_exists('register_activation_hook')) {
            return;
        }

        $this->prompt = new LicensePrompt($config, $this);
        $this->prompt->register();
        (new WordPressUpdateAdapter($config, $this))->register();
        (new ServerCommandEndpoint($config, $this))->register();
        add_action('init', [$this, 'scheduleHeartbeat']);
        add_action('admin_init', [$this, 'maybeRefreshFromAdmin']);
        add_action($this->heartbeatHook(), [$this, 'heartbeat']);
        register_activation_hook($config->pluginFile, [$this, 'markLicensePromptForActivation']);
    }

    public function markLicensePromptForActivation(): void
    {
        $this->prompt?->markActivated();
        $this->scheduleHeartbeat(true);
    }

    public function scheduleHeartbeat(bool $force = false): void
    {
        if (! function_exists('wp_schedule_event') || ! function_exists('wp_next_scheduled')) {
            return;
        }

        $interval = $this->pingIntervalHours() * HOUR_IN_SECONDS;
        $schedule = 'zion_license_'.md5($this->config->productSlug);
        add_filter('cron_schedules', static function (array $schedules) use ($schedule, $interval): array {
            $schedules[$schedule] = ['interval' => $interval, 'display' => 'Zion License heartbeat'];

            return $schedules;
        });

        if ($force && function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($this->heartbeatHook());
        }

        if (! wp_next_scheduled($this->heartbeatHook())) {
            wp_schedule_event(time() + min($interval, HOUR_IN_SECONDS), $schedule, $this->heartbeatHook());
        }
    }

    public function heartbeat(): void
    {
        if (! function_exists('get_option')) {
            return;
        }

        try {
            $this->ping(get_option($this->config->licenseOption()) ?: null);
        } catch (\RuntimeException) {
            // The next scheduled heartbeat retries without breaking the WordPress site.
        }
    }

    public function maybeRefreshFromAdmin(): void
    {
        if (! current_user_can('manage_options') || ! function_exists('get_option')) {
            return;
        }

        $last = (int) get_option($this->lastAdminRefreshOption(), 0);
        if ($last > (time() - 600)) {
            return;
        }

        $licenseKey = get_option($this->config->licenseOption()) ?: null;
        if (! is_string($licenseKey) || $licenseKey === '') {
            return;
        }

        update_option($this->lastAdminRefreshOption(), time(), false);
        try {
            $this->ping($licenseKey);
        } catch (\Throwable) {
            // Admin page loads must never break a WordPress site.
        }
    }

    /** @return array{registered: bool, url: string} */
    public function callbackStatus(): array
    {
        $secret = $this->callbackSecret();
        $url = $this->callbackUrl();

        return ['registered' => is_string($url) && $url !== '' && preg_match('/^[a-f0-9]{64}$/', $secret) === 1, 'url' => (string) $url];
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $response = $this->runtimeConfiguration();
        $response['license_state'] = get_option($this->licenseStateOption(), 'unknown');
        $response['license_key_present'] = (bool) get_option($this->config->licenseOption(), '');
        $response['callback'] = $this->callbackStatus();
        $response['last_ping_at'] = get_option($this->lastPingOption(), null);
        $response['installed_version'] = $this->installedVersion();

        return $response;
    }

    /** @return array<string, mixed> */
    public function ping(?string $licenseKey = null): array
    {
        $payload = [
            'product_slug' => $this->config->productSlug,
            'installation_uuid' => $this->installationId(),
            'site_url' => function_exists('home_url') ? home_url('/') : '',
            'plugin_version' => $this->header('Version'),
            'wordpress_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
            'php_version' => PHP_VERSION,
            'license_key' => $licenseKey,
            'callback_url' => $this->callbackUrl(),
            'callback_secret' => $this->callbackSecret(),
        ];

        if ($this->config->sendAdminEmail && function_exists('get_option')) {
            $payload['admin_email'] = get_option('admin_email');
        }

        $payload['system_data'] = $this->systemData();
        $response = $this->http->post(
            rtrim($this->config->apiUrl, '/').'/wordpress/ping',
            array_filter($payload),
            ['X-Zion-Product-Key' => $this->config->productKey],
        );
        $this->storeRuntimeConfiguration($response['configuration'] ?? []);
        update_option($this->licenseStateOption(), sanitize_key((string) ($response['license_state'] ?? 'unknown')), false);
        update_option($this->lastPingOption(), current_time('mysql'), false);
        update_option($this->detailsOption(), $response, false);

        return $response;
    }

    private function installationId(): string
    {
        $option = 'zion_license_installation_'.md5($this->config->productSlug);
        $id = function_exists('get_option') ? get_option($option) : null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid4();
        if (function_exists('update_option')) {
            update_option($option, $id, false);
        }

        return $id;
    }

    public function installedVersion(): string
    {
        return (string) ($this->header('Version') ?? '');
    }

    /** @return array<string, mixed> */
    public function runtimeConfiguration(): array
    {
        $configuration = function_exists('get_option') ? get_option('zion_license_runtime_'.md5($this->config->productSlug), []) : [];

        return is_array($configuration) ? $configuration : [];
    }

    public function callbackSecret(): string
    {
        $option = 'zion_license_callback_secret_'.md5($this->config->productSlug);
        $secret = function_exists('get_option') ? get_option($option) : null;

        if (is_string($secret) && preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));
        if (function_exists('update_option')) {
            update_option($option, $secret, false);
        }

        return $secret;
    }

    private function callbackUrl(): ?string
    {
        if (! function_exists('rest_url')) {
            return null;
        }

        return rest_url('zion-license/v1/command');
    }

    private function header(string $header): ?string
    {
        $contents = @file_get_contents($this->config->pluginFile) ?: '';
        preg_match('/^[ \t\/*#@]*'.preg_quote($header, '/').':\s*(.+)$/mi', $contents, $matches);

        return $matches[1] ?? null;
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @return array<string, scalar|bool|null> */
    private function systemData(): array
    {
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;

        return array_filter([
            'site_language' => function_exists('get_locale') ? get_locale() : null,
            'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : null,
            'is_multisite' => function_exists('is_multisite') ? is_multisite() : null,
            'memory_limit' => ini_get('memory_limit') ?: null,
            'php_sapi' => PHP_SAPI,
            'theme_name' => $theme ? $theme->get('Name') : null,
            'theme_version' => $theme ? $theme->get('Version') : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $configuration */
    public function storeRuntimeConfiguration(array $configuration): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option('zion_license_runtime_'.md5($this->config->productSlug), $configuration, false);
        $this->scheduleHeartbeat(true);
    }

    private function heartbeatHook(): string
    {
        return 'zion_license_heartbeat_'.md5($this->config->productSlug);
    }

    private function licenseStateOption(): string { return 'zion_license_state_'.md5($this->config->productSlug); }
    private function detailsOption(): string { return 'zion_license_details_'.md5($this->config->productSlug); }
    private function lastPingOption(): string { return 'zion_license_last_ping_'.md5($this->config->productSlug); }
    private function lastAdminRefreshOption(): string { return 'zion_license_admin_refresh_'.md5($this->config->productSlug); }

    private function pingIntervalHours(): int
    {
        $interval = (int) ($this->runtimeConfiguration()['ping_interval_hours'] ?? 6);

        return in_array($interval, [1, 2, 6, 12, 24], true) ? $interval : 6;
    }
}
