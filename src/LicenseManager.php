<?php

namespace Zion\WordPressLicense;

use Zion\WordPressLicense\Exceptions\ApiException;

final class LicenseManager
{
    public const VERSION = '0.3.0';

    private ?LicensePrompt $prompt = null;

    private ?WordPressUpdateAdapter $updates = null;

    public function __construct(private readonly Config $config, private readonly WordPressHttpClient $http = new WordPressHttpClient)
    {
        $this->config->validate();

        if (! function_exists('add_action') || ! function_exists('register_activation_hook')) {
            return;
        }

        $this->prompt = new LicensePrompt($config, $this);
        $this->prompt->register();
        $this->updates = new WordPressUpdateAdapter($config, $this);
        $this->updates->register();
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
            $jitter = random_int(300, max(300, min($interval, HOUR_IN_SECONDS)));
            wp_schedule_event(time() + $jitter, $schedule, $this->heartbeatHook());
        }
    }

    public function heartbeat(): void
    {
        if (! function_exists('get_option')) {
            return;
        }

        $lock = new HeartbeatLock('zion_license_heartbeat_lock_'.md5($this->config->productSlug));
        if (! $lock->acquire()) {
            return;
        }

        try {
            $this->ping($this->licenseKey());
        } catch (\Throwable $exception) {
            $this->markOfflineFailure($exception);
            // The next scheduled heartbeat retries without breaking the WordPress site.
        } finally {
            $lock->release();
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

        $licenseKey = $this->licenseKey();
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
        $details = function_exists('get_option') ? get_option($this->detailsOption(), []) : [];
        if (is_array($details)) {
            foreach (['expires_at', 'license_state', 'next_ping_after'] as $key) {
                if (array_key_exists($key, $details)) {
                    $response[$key] = $details[$key];
                }
            }
        }
        $response['license_state'] = $this->effectiveLicenseState();
        $response['plan'] = $this->plan();
        $response['entitlements'] = $this->entitlements();
        $response['license_key_present'] = $this->licenseKey() !== null;
        $response['activation_token_present'] = $this->activationToken() !== null;
        $response['callback'] = $this->callbackStatus();
        $response['last_ping_at'] = get_option($this->lastPingOption(), null);
        $response['last_error'] = function_exists('get_option') ? get_option($this->lastErrorOption(), null) : null;
        $response['installed_version'] = $this->installedVersion();
        $response['protocol'] = $this->protocolStatus();

        if ($this->updates !== null) {
            $update = $this->updates->status(false);
            $response['update_available'] = $update['available'];
            $response['latest_version'] = $update['latest_version'];
            $response['package_url'] = $update['package_url'];
            $response['changelog'] = $update['changelog'];
            $response['auto_update_allowed'] = $update['auto_update_allowed'];
            $response['auto_update_enabled'] = $update['auto_update_enabled'];
            $response['sdk_version'] = $update['sdk_version'];
            $response['last_update_at'] = $update['last_update_at'];
            $response['manifest'] = $update['manifest'];
            $response['manifest_verified'] = $update['manifest_verified'];
            $response['update_blocked_reason'] = $update['blocked_reason'];
        }

        return $response;
    }

    /** @return array{version: string, minimum_sdk_version: string, recommended_sdk_version: string, deprecated: bool} */
    public function protocolStatus(): array
    {
        $configuration = $this->runtimeConfiguration();
        $minimum = (string) ($configuration['minimum_sdk_version'] ?? Protocol::MINIMUM_SDK_VERSION);
        $recommended = (string) ($configuration['recommended_sdk_version'] ?? self::VERSION);

        return [
            'version' => (string) ($configuration['protocol_version'] ?? Protocol::VERSION),
            'minimum_sdk_version' => $minimum,
            'recommended_sdk_version' => $recommended,
            'deprecated' => version_compare(self::VERSION, $minimum, '<'),
        ];
    }

    public function plan(): string
    {
        $plan = (string) ($this->runtimeConfiguration()['plan'] ?? 'free');

        return in_array($plan, ['free', 'pro', 'business', 'agency'], true) ? $plan : 'free';
    }

    /** @return array<string, bool> */
    public function entitlements(): array
    {
        if (! in_array($this->effectiveLicenseState(), ['active', 'grace_period', 'free'], true)) {
            return [];
        }

        $entitlements = $this->runtimeConfiguration()['entitlements'] ?? [];

        if (! is_array($entitlements)) {
            return [];
        }

        return array_filter($entitlements, static fn (mixed $enabled): bool => $enabled === true);
    }

    public function featureGate(): FeatureGate
    {
        return new FeatureGate($this->entitlements());
    }

    public function allows(string $feature): bool
    {
        return $this->featureGate()->allows($feature);
    }

    /** Refreshes license and update data only when the server-defined interval elapsed. */
    public function refreshIfDue(): array
    {
        if (! function_exists('get_option')) {
            return $this->runtimeConfiguration();
        }

        $licenseKey = $this->licenseKey() ?? '';
        if (! is_string($licenseKey) || $licenseKey === '') {
            return $this->runtimeConfiguration();
        }

        $last = get_option($this->lastPingOption(), null);
        $interval = $this->pingIntervalHours() * HOUR_IN_SECONDS;
        $lastTimestamp = is_string($last) ? strtotime($last) : false;
        if ($lastTimestamp !== false && $lastTimestamp > time() - $interval) {
            return $this->runtimeConfiguration();
        }

        try {
            return $this->ping($licenseKey);
        } catch (\Throwable $exception) {
            $this->markOfflineFailure($exception);

            return $this->runtimeConfiguration();
        }
    }

    /** Refreshes sooner when a cached signed package URL has expired. */
    public function refreshForUpdateIfNeeded(): array
    {
        $configuration = $this->runtimeConfiguration();
        $expiresAt = isset($configuration['package_expires_at'])
            ? strtotime((string) $configuration['package_expires_at'])
            : false;
        $packageExpired = $expiresAt === false || $expiresAt <= time();
        $updateAnnounced = ! empty($configuration['update_available']);

        if ($updateAnnounced && $packageExpired && $this->licenseKey() !== null) {
            try {
                return $this->ping($this->licenseKey());
            } catch (\Throwable $exception) {
                $this->markOfflineFailure($exception);

                return $configuration;
            }
        }

        return $this->refreshIfDue();
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
            'sdk_version' => self::VERSION,
            'callback_url' => $this->callbackUrl(),
            'callback_secret' => $this->callbackSecret(),
        ];

        if ($this->config->sendAdminEmail && function_exists('get_option')) {
            $payload['admin_email'] = get_option('admin_email');
        }

        $payload['system_data'] = $this->systemData();
        $headers = ['X-Zion-Product-Key' => $this->config->productKey];
        $token = $this->activationToken();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        } else {
            $payload['license_key'] = $licenseKey ?? $this->licenseKey();
        }

        try {
            $response = $this->http->post(rtrim($this->config->apiUrl, '/').'/wordpress/ping', array_filter($payload), $headers);
        } catch (ApiException $exception) {
            if ($token === null || ! in_array($exception->errorCode, ['invalid_activation_token', 'activation_not_found'], true)) {
                throw $exception;
            }

            $this->clearActivationToken();
            $payload['license_key'] = $licenseKey ?? $this->licenseKey();
            unset($headers['Authorization']);
            $response = $this->http->post(rtrim($this->config->apiUrl, '/').'/wordpress/ping', array_filter($payload), $headers);
        }

        if (is_string($response['activation_token'] ?? null) && $response['activation_token'] !== '') {
            $this->storeActivationToken($response['activation_token']);
        }
        $this->storeRuntimeConfiguration(array_merge(
            is_array($response['configuration'] ?? null) ? $response['configuration'] : [],
            [
                'expires_at' => $response['expires_at'] ?? null,
                'license_state' => $response['license_state'] ?? null,
            ],
        ));
        $oldState = function_exists('get_option') ? sanitize_key((string) get_option($this->licenseStateOption(), 'unknown')) : 'unknown';
        $newState = sanitize_key((string) ($response['license_state'] ?? 'unknown'));
        update_option($this->licenseStateOption(), $newState, false);
        update_option($this->lastPingOption(), current_time('mysql'), false);
        update_option($this->detailsOption(), $response, false);
        if (function_exists('delete_option')) {
            delete_option($this->lastErrorOption());
        }
        if (function_exists('do_action')) {
            do_action('zion_license_ping_succeeded', $response);
            if ($oldState !== $newState) {
                do_action('zion_license_state_changed', $oldState, $newState);
            }
        }

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

    /**
     * Refreshes the server state and returns the update decision for this
     * installation. An update is available only when the server version is
     * strictly greater than the installed plugin version.
     *
     * @return array{available: bool, installed_version: string, latest_version: string, package_url: string, details_url: string}
     */
    public function updateStatus(bool $refresh = true): array
    {
        return $this->updates?->status($refresh) ?? [
            'available' => false,
            'installed_version' => $this->installedVersion(),
            'latest_version' => '',
            'package_url' => '',
            'details_url' => rtrim($this->config->apiUrl, '/'),
        ];
    }

    /**
     * Starts and executes the private WordPress update when a newer server
     * version is available. Returns false when no update is needed or the
     * current request cannot perform updates; otherwise returns WordPress's
     * upgrader result (or WP_Error).
     */
    public function updateIfAvailable(bool $internal = false): mixed
    {
        return $this->updates?->updateIfAvailable($internal) ?? false;
    }

    public function reportUpdateResult(string $version, bool $success, ?string $error = null): void
    {
        $token = $this->activationToken();
        if ($token === null) {
            return;
        }

        try {
            $this->http->post(rtrim($this->config->apiUrl, '/').'/updates/report', [
                'product_slug' => $this->config->productSlug,
                'installation_uuid' => $this->installationId(),
                'version' => $version,
                'status' => $success ? 'success' : 'failed',
                'error' => $error,
            ], [
                'Authorization' => 'Bearer '.$token,
                'X-Zion-Product-Key' => $this->config->productKey,
            ]);
        } catch (\Throwable $exception) {
            if (function_exists('do_action')) {
                do_action('zion_license_update_report_failed', $exception, $version, $success);
            }
        }
    }

    /**
     * Remove WordPress' cached plugin update metadata without discarding the
     * signed Zion runtime configuration (which contains the package URL).
     */
    public function clearUpdateCache(): void
    {
        if (function_exists('delete_site_transient')) {
            delete_site_transient('update_plugins');
        }

        if (function_exists('delete_transient')) {
            delete_transient('update_plugins');
        }

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
    }

    /**
     * Force a fresh license ping, then invalidate WordPress update caches and
     * ask WordPress to rebuild its plugin update transient.
     *
     * @return array<string, mixed>
     */
    public function forceRefresh(): array
    {
        if (! function_exists('get_option')) {
            return $this->runtimeConfiguration();
        }

        $licenseKey = $this->licenseKey() ?? '';
        if (! is_string($licenseKey) || $licenseKey === '') {
            throw new \RuntimeException('No license key is configured.');
        }

        $response = $this->ping($licenseKey);
        $this->clearUpdateCache();

        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        return $response;
    }

    public function licenseKey(): ?string
    {
        return SecretStore::read($this->config->licenseOption());
    }

    public function storeLicenseKey(string $licenseKey): void
    {
        SecretStore::write($this->config->licenseOption(), $licenseKey);
    }

    /** @return array<string, mixed> */
    public function activate(string $licenseKey): array
    {
        $payload = [
            'product_slug' => $this->config->productSlug,
            'installation_uuid' => $this->installationId(),
            'site_url' => function_exists('home_url') ? home_url('/') : '',
            'plugin_version' => $this->header('Version'),
            'sdk_version' => self::VERSION,
            'wordpress_version' => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
            'php_version' => PHP_VERSION,
            'license_key' => $licenseKey,
            'callback_url' => $this->callbackUrl(),
            'callback_secret' => $this->callbackSecret(),
        ];
        $response = $this->http->post(rtrim($this->config->apiUrl, '/').'/licenses/activate', array_filter($payload), [
            'X-Zion-Product-Key' => $this->config->productKey,
        ]);
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $this->storeLicenseKey($licenseKey);
        if (is_string($data['activation_token'] ?? null)) {
            $this->storeActivationToken($data['activation_token']);
        }
        $this->storeRuntimeConfiguration($data);
        update_option($this->licenseStateOption(), sanitize_key((string) ($data['license_state'] ?? 'unknown')), false);
        update_option($this->lastPingOption(), current_time('mysql'), false);
        update_option($this->detailsOption(), $data, false);
        if (function_exists('do_action')) {
            do_action('zion_license_activated', $data);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function validateLicense(): array
    {
        $token = $this->activationToken();
        if ($token === null) {
            return $this->ping($this->licenseKey());
        }

        $response = $this->http->post(rtrim($this->config->apiUrl, '/').'/licenses/validate', [
            'product_slug' => $this->config->productSlug,
            'installation_uuid' => $this->installationId(),
            'site_url' => function_exists('home_url') ? home_url('/') : '',
        ], [
            'Authorization' => 'Bearer '.$token,
            'X-Zion-Product-Key' => $this->config->productKey,
        ]);
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $this->storeRuntimeConfiguration($data);
        update_option($this->licenseStateOption(), sanitize_key((string) ($data['license_state'] ?? 'unknown')), false);
        update_option($this->lastPingOption(), current_time('mysql'), false);
        update_option($this->detailsOption(), $data, false);

        return $data;
    }

    /** @return array<string, mixed> */
    public function deactivate(): array
    {
        $token = $this->activationToken();
        if ($token === null) {
            $this->clearLocalLicenseState();

            return ['license_state' => 'deactivated'];
        }

        $response = $this->http->post(rtrim($this->config->apiUrl, '/').'/licenses/deactivate', [
            'product_slug' => $this->config->productSlug,
            'installation_uuid' => $this->installationId(),
            'site_url' => function_exists('home_url') ? home_url('/') : '',
        ], [
            'Authorization' => 'Bearer '.$token,
            'X-Zion-Product-Key' => $this->config->productKey,
        ]);
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $this->clearLocalLicenseState();
        if (function_exists('do_action')) {
            do_action('zion_license_deactivated', $data);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function runtimeConfiguration(): array
    {
        $configuration = function_exists('get_option') ? get_option($this->runtimeOption(), []) : [];

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

        $data = array_filter([
            'site_language' => function_exists('get_locale') ? get_locale() : null,
            'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : null,
            'is_multisite' => function_exists('is_multisite') ? is_multisite() : null,
            'memory_limit' => ini_get('memory_limit') ?: null,
            'php_sapi' => PHP_SAPI,
            'theme_name' => $theme ? $theme->get('Name') : null,
            'theme_version' => $theme ? $theme->get('Version') : null,
        ], static fn ($value) => $value !== null && $value !== '');

        return function_exists('apply_filters')
            ? (array) apply_filters('zion_license_system_data', $data)
            : $data;
    }

    /** @param array<string, mixed> $configuration */
    public function storeRuntimeConfiguration(array $configuration): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option($this->runtimeOption(), $configuration, false);
        $this->scheduleHeartbeat(true);
    }

    private function heartbeatHook(): string
    {
        return 'zion_license_heartbeat_'.md5($this->config->productSlug);
    }

    private function licenseStateOption(): string
    {
        return 'zion_license_state_'.md5($this->config->productSlug);
    }

    private function detailsOption(): string
    {
        return 'zion_license_details_'.md5($this->config->productSlug);
    }

    private function lastPingOption(): string
    {
        return 'zion_license_last_ping_'.md5($this->config->productSlug);
    }

    private function lastAdminRefreshOption(): string
    {
        return 'zion_license_admin_refresh_'.md5($this->config->productSlug);
    }

    private function lastErrorOption(): string
    {
        return 'zion_license_last_error_'.md5($this->config->productSlug);
    }

    private function markOfflineFailure(\Throwable $exception): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option($this->lastErrorOption(), [
            'code' => $exception instanceof ApiException ? $exception->errorCode : 'server_unavailable',
            'message' => substr($exception->getMessage(), 0, 500),
            'at' => current_time('mysql'),
        ], false);
        if (function_exists('do_action')) {
            do_action('zion_license_ping_failed', $exception);
        }
    }

    private function effectiveLicenseState(): string
    {
        $state = sanitize_key((string) (function_exists('get_option') ? get_option($this->licenseStateOption(), 'unknown') : 'unknown'));
        $configuration = $this->runtimeConfiguration();
        $lastError = function_exists('get_option') ? get_option($this->lastErrorOption(), null) : null;
        if (! is_array($lastError)) {
            return $state !== '' ? $state : LicenseState::Unconfigured->value;
        }

        if ($state === 'active' && $this->config->offlinePolicy === OfflinePolicy::Lenient) {
            $graceUntil = isset($configuration['grace_until']) ? strtotime((string) $configuration['grace_until']) : false;
            if ($graceUntil !== false && $graceUntil > time()) {
                return LicenseState::GracePeriod->value;
            }
            if (! array_key_exists('expires_at', $configuration) || $configuration['expires_at'] === null) {
                return LicenseState::Active->value;
            }
        }

        return LicenseState::Unreachable->value;
    }

    private function activationTokenOption(): string
    {
        return 'zion_license_activation_token_'.md5($this->config->productSlug);
    }

    private function activationToken(): ?string
    {
        return SecretStore::read($this->activationTokenOption());
    }

    private function storeActivationToken(string $token): void
    {
        SecretStore::write($this->activationTokenOption(), $token);
    }

    private function clearActivationToken(): void
    {
        SecretStore::delete($this->activationTokenOption());
    }

    private function clearLocalLicenseState(): void
    {
        SecretStore::delete($this->config->licenseOption());
        $this->clearActivationToken();
        if (function_exists('delete_option')) {
            delete_option($this->licenseStateOption());
            delete_option($this->detailsOption());
            delete_option($this->runtimeOption());
        }
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($this->heartbeatHook());
        }
        $this->clearUpdateCache();
    }

    private function runtimeOption(): string
    {
        return 'zion_license_runtime_'.md5($this->config->productSlug);
    }

    private function pingIntervalHours(): int
    {
        $interval = (int) ($this->runtimeConfiguration()['ping_interval_hours'] ?? 6);

        return in_array($interval, [1, 2, 6, 12, 24], true) ? $interval : 6;
    }
}
