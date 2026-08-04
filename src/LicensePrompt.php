<?php

namespace Zion\WordPressLicense;

use RuntimeException;
use Zion\WordPressLicense\Exceptions\ApiException;

/**
 * Shared WordPress administration flow for entering and validating a product license.
 */
final class LicensePrompt
{
    /** @var array<string, self> */
    private static array $instances = [];

    public function __construct(
        private readonly Config $config,
        private readonly LicenseManager $manager,
    ) {}

    public function register(): void
    {
        self::$instances[$this->config->productSlug] = $this;
        add_action('admin_init', [$this, 'handleSubmission']);
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('admin_footer', [$this, 'renderModal']);
        add_action('admin_post_zion_license_toggle_auto_update', [$this, 'toggleAutoUpdate']);
        add_action('admin_post_zion_license_manual_update', [$this, 'manualUpdate']);
        add_filter('plugin_action_links_'.$this->pluginBasename(), [$this, 'pluginActionLinks']);
        add_filter('plugin_auto_update_setting_html', [$this, 'autoUpdateSettingHtml'], 10, 4);
    }

    public function markActivated(): void
    {
        update_option($this->promptOption(), '1', false);
    }

    public static function trigger(string $productSlug, ?string $label = null): string
    {
        if (! isset(self::$instances[$productSlug])) {
            return '';
        }

        $instance = self::$instances[$productSlug];
        $label ??= $instance->t('Activează licența');

        return sprintf(
            '<a class="button button-secondary zion-license-open" href="#%1$s" data-zion-license-open="%1$s">%2$s</a>',
            esc_attr($instance->modalId()),
            esc_html($label),
        );
    }

    public function handleSubmission(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $action = isset($_POST['zion_license_action']) ? sanitize_key(wp_unslash($_POST['zion_license_action'])) : '';
        if (! in_array($action, ['save', 'refresh_status', 'deactivate'], true) || ($this->config->productSlug !== sanitize_key(wp_unslash($_POST['zion_license_product'] ?? '')))) {
            return;
        }

        check_admin_referer($this->nonceAction());

        if ($action === 'deactivate') {
            try {
                $this->manager->deactivate();
                $this->flash('success', $this->t('Licența a fost dezactivată și activarea a fost eliberată.'));
            } catch (\Throwable) {
                $this->flash('error', $this->t('Licența nu a putut fi dezactivată acum.'));
            }

            $this->redirect(wp_get_referer() ?: admin_url('plugins.php'));
        }

        if ($action === 'refresh_status') {
            try {
                $response = $this->manager->forceRefresh();
                $state = sanitize_key((string) ($response['license_state'] ?? 'unlicensed'));
                update_option($this->stateOption(), $state, false);
                $this->flash(
                    in_array($state, ['active', 'free'], true) ? 'success' : 'error',
                    in_array($state, ['active', 'free'], true)
                        ? $this->t('Datele licenței și actualizările au fost reîmprospătate.')
                        : $this->t('Serverul a răspuns, dar licența nu este activă.'),
                );
            } catch (\Throwable) {
                $this->flash('error', $this->t('Nu am putut comunica acum cu serverul de licențe.'));
            }

            $this->redirect(wp_get_referer() ?: admin_url('plugins.php'));
        }

        $key = $this->normalizeKey((string) ($this->manager->licenseKey() ?? ''));
        if ($action === 'save') {
            $key = $this->normalizeKey((string) wp_unslash($_POST['zion_license_key'] ?? ''));
        }

        if ($key === '') {
            $this->flash('error', $this->t('Introdu o cheie de licență înainte de validare.'));
            $this->redirect();
        }

        if (! $this->validKey($key)) {
            $this->flash('error', sprintf($this->t('Format invalid. Folosește exact %s (%d caractere).'), $this->config->licenseExample(), $this->config->licenseLength()));
            $this->redirect();
        }

        $telemetryConsent = ! empty($_POST['zion_telemetry_consent']);
        $this->manager->setTelemetryConsent($telemetryConsent);
        $this->manager->storeLicenseKey($key);

        try {
            try {
                $response = $this->manager->activate($key, $telemetryConsent);
            } catch (ApiException $exception) {
                // Keep older License Servers usable during a rolling deployment.
                if ($exception->statusCode !== 404) {
                    throw $exception;
                }

                $response = $this->manager->ping($key);
            }
            $state = isset($response['license_state']) ? sanitize_key((string) $response['license_state']) : 'unlicensed';
            update_option($this->stateOption(), $state, false);

            if (in_array($state, ['active', 'free'], true)) {
                delete_option($this->promptOption());
                $this->flash('success', $this->t('Licența a fost activată pentru acest site.'));
            } else {
                update_option($this->promptOption(), '1', false);
                $this->flash('error', $this->t('Licența nu a putut fi activată. Verifică cheia și limita de activări.'));
            }
        } catch (RuntimeException $exception) {
            update_option($this->promptOption(), '1', false);
            $this->flash('error', $this->t('Nu am putut valida licența acum. Încearcă din nou când serverul de licențe este disponibil.'));
        }

        $this->redirect();
    }

    public function registerStatusPage(): void
    {
        add_management_page($this->t('Status licență'), $this->t('Status licență'), 'manage_options', $this->statusPageSlug(), [$this, 'renderStatusPage']);
    }

    /** @param array<int, string> $links @return array<int, string> */
    public function pluginActionLinks(array $links): array
    {
        $links[] = sprintf('<a href="#%1$s" data-zion-license-status-open="%1$s">%2$s</a>', esc_attr($this->statusModalId()), esc_html($this->t('Status licență')));

        $status = $this->manager->status();
        if ($this->needsLicense()) {
            $links[] = sprintf('<a href="#%1$s" data-zion-license-open="%1$s">%2$s</a>', esc_attr($this->modalId()), esc_html($this->t('Adaugă o licență nouă')));
        }

        if (! empty($status['auto_update_allowed'])) {
            $enabled = in_array($this->pluginBasename(), (array) get_site_option('auto_update_plugins', []), true);
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($this->adminPostUrl('zion_license_toggle_auto_update')),
                esc_html($enabled ? $this->t('Dezactivează auto-update') : $this->t('Activează auto-update')),
            );
        }

        if (! empty($status['update_available']) && ! empty($status['package_url'])) {
            $links[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($this->adminPostUrl('zion_license_manual_update')),
                esc_html($this->t('Actualizează acum')),
            );
        }

        return $links;
    }

    /**
     * Keep the native WordPress auto-update column useful for private plugins.
     * Core normally hides this control when no WordPress.org update endpoint
     * exists, so the SDK supplies the same control using the signed Zion gate.
     */
    public function autoUpdateSettingHtml(string $html, string $pluginFile, mixed $plugin = null, mixed $autoUpdates = ''): string
    {
        if ($pluginFile !== $this->pluginBasename()) {
            return $html;
        }

        $status = $this->manager->status();
        if (empty($status['auto_update_allowed'])) {
            return $html;
        }

        $enabled = in_array($pluginFile, (array) get_site_option('auto_update_plugins', []), true);
        $label = $enabled ? $this->t('Dezactivează auto-update') : $this->t('Activează auto-update');

        return sprintf(
            '<a href="%1$s" class="update-link zion-auto-update-link">%2$s</a>',
            esc_url($this->adminPostUrl('zion_license_toggle_auto_update')),
            esc_html($label),
        );
    }

    public function toggleAutoUpdate(): void
    {
        if (! current_user_can('update_plugins')) {
            wp_die(esc_html($this->t('Nu ai permisiunea necesară.')), '', ['response' => 403]);
        }

        check_admin_referer($this->adminPostNonceAction());
        if ($this->pluginBasename() !== sanitize_text_field(wp_unslash($_REQUEST['plugin'] ?? ''))) {
            $this->redirect(admin_url('plugins.php'));
        }

        $status = $this->manager->status();
        if (empty($status['auto_update_allowed'])) {
            $this->flash('error', $this->t('Auto-update-ul nu este permis pentru acest produs.'));
            $this->redirect(admin_url('plugins.php'));
        }

        $plugins = array_values(array_filter((array) get_site_option('auto_update_plugins', []), 'is_string'));
        $basename = $this->pluginBasename();
        if (in_array($basename, $plugins, true)) {
            $plugins = array_values(array_diff($plugins, [$basename]));
            $message = $this->t('Auto-update-ul a fost dezactivat.');
        } else {
            $plugins[] = $basename;
            $message = $this->t('Auto-update-ul a fost activat.');
        }

        update_site_option('auto_update_plugins', $plugins);
        $this->flash('success', $message);
        $this->redirect(admin_url('plugins.php'));
    }

    public function manualUpdate(): void
    {
        if (! current_user_can('update_plugins')) {
            wp_die(esc_html($this->t('Nu ai permisiunea necesară.')), '', ['response' => 403]);
        }

        check_admin_referer($this->adminPostNonceAction());
        if ($this->pluginBasename() !== sanitize_text_field(wp_unslash($_REQUEST['plugin'] ?? ''))) {
            $this->redirect(admin_url('plugins.php'));
        }

        try {
            $result = $this->manager->updateIfAvailable();
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }

            $this->flash(
                $result === false ? 'error' : 'success',
                $result === false
                    ? $this->t('Nu există o actualizare disponibilă sau licența nu permite actualizarea.')
                    : $this->t('Pluginul a fost actualizat cu succes.'),
            );
        } catch (\Throwable $exception) {
            $this->flash('error', $this->t('Actualizarea pluginului a eșuat: ').$exception->getMessage());
        }

        $this->redirect(admin_url('plugins.php'));
    }

    public function renderStatusPage(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html($this->t('Nu ai permisiunea necesară.')));
        }

        $status = $this->manager->status();
        $flash = get_transient($this->flashOption());
        delete_transient($this->flashOption());
        $callback = $status['callback'] ?? ['registered' => false, 'url' => ''];
        ?>
        <div class="wrap zion-license-status"><h1><?php echo esc_html($this->t('Status licență')); ?> — <?php echo esc_html($this->config->displayName()); ?></h1>
            <?php if (is_array($flash)) { ?><div class="notice notice-<?php echo esc_attr($flash['type']); ?> is-dismissible"><p><?php echo esc_html($flash['message']); ?></p></div><?php } ?>
            <div class="zion-license-grid">
                <div class="zion-license-card"><span><?php echo esc_html($this->t('Status')); ?></span><strong class="zion-license-state zion-license-state--<?php echo esc_attr((string) ($status['license_state'] ?? 'unknown')); ?>"><?php echo esc_html(ucfirst((string) ($status['license_state'] ?? 'unknown'))); ?></strong></div>
                <div class="zion-license-card"><span><?php echo esc_html($this->t('Versiune instalată')); ?></span><strong><?php echo esc_html((string) ($status['installed_version'] ?? '—')); ?></strong></div>
                <div class="zion-license-card"><span><?php echo esc_html($this->t('Ultimul ping')); ?></span><strong><?php echo esc_html((string) ($status['last_ping_at'] ?? '—')); ?></strong></div>
                <div class="zion-license-card"><span><?php echo esc_html($this->t('Callback securizat')); ?></span><strong class="<?php echo $callback['registered'] ? 'is-ok' : 'is-error'; ?>"><?php echo $callback['registered'] ? esc_html($this->t('Înregistrat')) : esc_html($this->t('Lipsește')); ?></strong></div>
            </div>
            <div class="zion-license-panel"><h2><?php echo esc_html($this->t('Verifică și actualizează datele')); ?></h2><p><?php echo esc_html($this->t('Verificarea face un ping securizat către server, actualizează detaliile licenței și înregistrează callback-ul pentru comenzi de update.')); ?></p><form method="post"><?php wp_nonce_field($this->nonceAction()); ?><input type="hidden" name="zion_license_action" value="refresh_status"><input type="hidden" name="zion_license_product" value="<?php echo esc_attr($this->config->productSlug); ?>"><button class="button button-primary button-hero" type="submit"><?php echo esc_html($this->t('Verifică conexiunea și actualizează')); ?></button></form></div>
            <div class="zion-license-panel"><h2><?php echo esc_html($this->t('Detalii')); ?></h2><dl><dt><?php echo esc_html($this->t('Expiră la')); ?></dt><dd><?php echo esc_html((string) ($status['expires_at'] ?? '—')); ?></dd><dt><?php echo esc_html($this->t('Versiune instalată')); ?></dt><dd><?php echo esc_html((string) ($status['installed_version'] ?? '—')); ?></dd><dt><?php echo esc_html($this->t('Versiune disponibilă')); ?></dt><dd><?php echo esc_html((string) ($status['latest_version'] ?? '—')); ?><?php if (! empty($status['update_available'])) { ?> <strong class="is-ok"><?php echo esc_html($this->t('Actualizare disponibilă')); ?></strong><?php } ?></dd><dt><?php echo esc_html($this->t('SDK')); ?></dt><dd><?php echo esc_html((string) ($status['sdk_latest_version'] ?? $status['sdk_version'] ?? '—')); ?><?php if (! empty($status['sdk_update_available'])) { ?> <strong class="is-ok"><?php echo esc_html($this->t('SDK nou disponibil')); ?></strong><?php } ?></dd><dt><?php echo esc_html($this->t('Callback URL')); ?></dt><dd><code><?php echo esc_html((string) ($callback['url'] ?? '—')); ?></code></dd></dl></div>
        </div><style>.zion-license-status{max-width:980px}.zion-license-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:24px 0}.zion-license-card,.zion-license-panel{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;box-shadow:0 8px 28px rgba(16,24,40,.06)}.zion-license-card span{display:block;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.zion-license-card strong{display:block;margin-top:10px;font-size:20px}.is-ok,.zion-license-state--active,.zion-license-state--free{color:#16803c}.is-error,.zion-license-state--unlicensed,.zion-license-state--unknown{color:#b42318}.zion-license-panel{margin:16px 0}.zion-license-panel h2{margin-top:0}.zion-license-panel dt{float:left;clear:left;width:180px;color:#646970;padding:7px 0}.zion-license-panel dd{margin-left:190px;padding:7px 0}.zion-license-panel code{word-break:break-all}@media(max-width:800px){.zion-license-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:480px){.zion-license-grid{grid-template-columns:1fr}.zion-license-panel dt{float:none;width:auto}.zion-license-panel dd{margin-left:0}}</style>
        <?php
    }

    public function renderNotice(): void
    {
        if (! current_user_can('manage_options') || ! $this->needsLicense()) {
            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible zion-license-notice"><p><strong>%1$s</strong> %2$s %3$s</p></div>',
            esc_html($this->config->displayName()),
            esc_html($this->t('este activ, dar licența nu este încă activată.')),
            self::trigger($this->config->productSlug, $this->t('Introdu licența')),
        );
    }

    public function renderModal(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $flash = get_transient($this->flashOption());
        delete_transient($this->flashOption());
        $open = $this->needsLicense() && get_option($this->promptOption(), '') === '1';
        ?>
        <style>
            .zion-license-modal{position:fixed;z-index:100000;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.45)}
            .zion-license-modal.is-open{display:flex}.zion-license-dialog{width:min(520px,100%);padding:28px;border-radius:14px;background:#fff;box-shadow:0 24px 80px rgba(15,23,42,.3)}
            .zion-license-dialog h2{margin:0 0 8px;color:#172554}.zion-license-dialog p{margin:0 0 18px;color:#475569}.zion-license-dialog label{display:block;margin-bottom:7px;font-weight:600}.zion-license-dialog input{width:100%;margin:0;padding:10px 12px;letter-spacing:.06em;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.zion-license-dialog input.is-valid{border-color:#16a34a;box-shadow:0 0 0 1px #16a34a}.zion-license-dialog input.is-invalid{border-color:#dc2626;box-shadow:0 0 0 1px #dc2626}.zion-license-dialog__hint{display:block;margin:8px 0 18px;color:#64748b;font-size:12px}.zion-license-dialog__hint.is-valid{color:#15803d}.zion-license-dialog__hint.is-invalid{color:#b91c1c}.zion-license-dialog__consent{display:flex!important;align-items:flex-start;gap:10px;margin:4px 0 20px!important;padding:12px;border:1px solid #dbe4ee;border-radius:10px;background:#f8fafc;font-weight:400!important}.zion-license-dialog__consent input{width:auto;margin-top:3px;padding:0;letter-spacing:0;font-family:inherit}.zion-license-dialog__consent strong,.zion-license-dialog__consent small{display:block}.zion-license-dialog__consent strong{color:#172554;font-size:13px}.zion-license-dialog__consent small{margin-top:4px;color:#64748b;font-size:11px;line-height:1.5}.zion-license-dialog__actions{display:flex;gap:10px;justify-content:flex-end}.zion-license-dialog__message{padding:10px 12px;margin-bottom:16px;border-radius:8px}.zion-license-dialog__message--error{background:#fef2f2;color:#991b1b}.zion-license-dialog__message--success{background:#ecfdf5;color:#166534}
        </style>
        <div id="<?php echo esc_attr($this->modalId()); ?>" class="zion-license-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($this->modalId()); ?>-title">
            <form class="zion-license-dialog" method="post">
                <h2 id="<?php echo esc_attr($this->modalId()); ?>-title"><?php echo esc_html($this->t('Activează licența')); ?></h2>
                <p><?php echo esc_html(sprintf($this->t('Introdu cheia pentru %s. Licența este verificată și asociată securizat acestui site.'), $this->config->displayName())); ?></p>
                <?php if (is_array($flash)) { ?><div class="zion-license-dialog__message zion-license-dialog__message--<?php echo esc_attr($flash['type']); ?>"><?php echo esc_html($flash['message']); ?></div><?php } ?>
                <?php wp_nonce_field($this->nonceAction()); ?>
                <input type="hidden" name="zion_license_action" value="save"><input type="hidden" name="zion_license_product" value="<?php echo esc_attr($this->config->productSlug); ?>">
                <label for="<?php echo esc_attr($this->modalId()); ?>-key"><?php echo esc_html($this->t('Cheie de licență')); ?></label>
                <input id="<?php echo esc_attr($this->modalId()); ?>-key" type="text" name="zion_license_key" value="<?php echo esc_attr((string) ($this->manager->licenseKey() ?? '')); ?>" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="<?php echo esc_attr($this->config->licenseExample()); ?>" maxlength="<?php echo esc_attr((string) $this->config->licenseLength()); ?>" required>
                <small class="zion-license-dialog__hint" data-zion-license-hint><?php echo esc_html(sprintf($this->t('Format necesar: %s · %d caractere.'), $this->config->licenseExample(), $this->config->licenseLength())); ?></small>
                <label class="zion-license-dialog__consent"><input type="checkbox" name="zion_telemetry_consent" value="1" <?php checked($this->manager->telemetryConsent(), true); ?>><span><strong><?php echo esc_html($this->t('Activează telemetria avansată')); ?></strong><small><?php echo esc_html($this->t('Opțional: trimitem doar date tehnice pentru compatibilitate și depanare — versiunea pluginului și SDK-ului, versiunea WordPress/PHP, limba, fusul orar, multisite și tema. Nu trimitem conținutul site-ului, chei de licență sau parole.')); ?></small></span></label>
                <div class="zion-license-dialog__actions"><button class="button" type="button" data-zion-license-close><?php echo esc_html($this->t('Mai târziu')); ?></button><button class="button button-primary" type="submit"><?php echo esc_html($this->t('Validează și activează')); ?></button></div>
            </form>
        </div>
        <script>
        (()=>{const modal=document.getElementById(<?php echo wp_json_encode($this->modalId()); ?>);if(!modal)return;const input=modal.querySelector('input[name="zion_license_key"]'),hint=modal.querySelector('[data-zion-license-hint]'),form=modal.querySelector('form'),pattern=new RegExp(<?php echo wp_json_encode($this->config->licensePattern()); ?>.slice(1,-1)),example=<?php echo wp_json_encode($this->config->licenseExample()); ?>,length=<?php echo (int) $this->config->licenseLength(); ?>;const validate=()=>{input.value=input.value.toUpperCase().replace(/\s+/g,'');const empty=input.value.length===0,valid=pattern.test(input.value);input.classList.toggle('is-valid',valid);input.classList.toggle('is-invalid',!empty&&!valid);hint.classList.toggle('is-valid',valid);hint.classList.toggle('is-invalid',!empty&&!valid);hint.textContent=valid?'✓ '+input.value.length+'/'+length+' <?php echo esc_js($this->t('caractere · format valid')); ?>':(empty?'<?php echo esc_js($this->t('Format necesar:')); ?> '+example+' · '+length+' <?php echo esc_js($this->t('caractere.')); ?>':'<?php echo esc_js($this->t('Format invalid. Sunt necesare')); ?> '+length+' <?php echo esc_js($this->t('caractere în formatul')); ?> '+example);return valid};input.addEventListener('input',validate);form.addEventListener('submit',e=>{if(!validate()){e.preventDefault();input.focus()}});validate();const open=()=>{modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');input.focus()};const close=()=>{modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true')};document.addEventListener('click',e=>{const trigger=e.target.closest('[data-zion-license-open]');if(trigger&&trigger.dataset.zionLicenseOpen===modal.id){e.preventDefault();open()}if(e.target===modal||e.target.closest('[data-zion-license-close]'))close()});<?php echo $open ? 'open();' : ''; ?>})();
        </script>
        <?php $this->renderStatusModal(); ?>
        <?php
    }

    private function renderStatusModal(): void
    {
        $status = $this->manager->status();
        $changelog = (string) ($status['changelog'] ?? '');
        $updateAvailable = ! empty($status['update_available']);
        $autoEnabled = ! empty($status['auto_update_enabled']);
        $autoAllowed = ! empty($status['auto_update_allowed']);
        $pingInterval = (int) ($status['ping_interval_hours'] ?? 0);
        $updatesPaused = ! empty($status['updates_paused']);
        $licenseSuffix = (string) ($status['license_key_suffix'] ?? '');
        $telemetryConsent = ! empty($status['telemetry_consent']);
        $telemetryEnabled = ! array_key_exists('telemetry_enabled', $status) || ! empty($status['telemetry_enabled']);
        ?>
        <style>
            .zion-license-status-modal{position:fixed;z-index:100000;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.58)}
            .zion-license-status-modal.is-open{display:flex}.zion-license-status-dialog{width:min(760px,100%);max-height:min(820px,92vh);overflow:auto;padding:28px;border-radius:18px;background:#fff;box-shadow:0 24px 80px rgba(15,23,42,.35)}
            .zion-license-status-dialog h2{margin:0 0 18px;color:#172554}.zion-license-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}.zion-license-status-card{padding:14px;border:1px solid #dbe4ee;border-radius:12px;background:#f8fafc}.zion-license-status-card small{display:block;color:#64748b}.zion-license-status-card strong{display:block;margin-top:6px;color:#0f172a}.zion-license-changelog{max-height:320px;overflow:auto;border:1px solid #dbe4ee;border-radius:12px;padding:16px;background:#f8fafc;color:#334155;font-size:12px;line-height:1.55}.zion-license-changelog__version{margin:0 0 8px;font-weight:700;color:#172554}.zion-license-changelog__badge{display:inline-block;margin-left:8px;border-radius:999px;padding:2px 8px;font-size:10px;font-weight:600}.zion-license-changelog__badge--current{background:#dcfce7;color:#166534}.zion-license-changelog__badge--new{background:#dbeafe;color:#1d4ed8}.zion-license-status-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}@media(max-width:640px){.zion-license-status-grid{grid-template-columns:1fr 1fr}}
        </style>
        <div id="<?php echo esc_attr($this->statusModalId()); ?>" class="zion-license-status-modal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="zion-license-status-dialog">
                <h2><?php echo esc_html($this->t('Status licență')); ?> — <?php echo esc_html($this->config->displayName()); ?></h2>
                <div class="zion-license-status-grid">
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Licență')); ?></small><strong><?php echo esc_html((string) ($status['license_state'] ?? 'unknown')); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Licență folosită')); ?></small><strong><?php echo esc_html($licenseSuffix !== '' ? '••••'.$licenseSuffix : '—'); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Versiune instalată')); ?></small><strong><?php echo esc_html((string) ($status['installed_version'] ?? '—')); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Versiune server')); ?></small><strong><?php echo esc_html((string) ($status['latest_version'] ?? '—')); ?><?php if ($updateAvailable) { ?> <em><?php echo esc_html($this->t('Update disponibil')); ?></em><?php } ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('SDK instalat')); ?></small><strong><?php echo esc_html((string) ($status['sdk_version'] ?? '—')); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Auto-update')); ?></small><strong><?php echo esc_html($autoAllowed ? ($autoEnabled ? $this->t('Activat') : $this->t('Dezactivat')) : $this->t('Blocat de server')); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Update-uri server')); ?></small><strong><?php echo esc_html($updatesPaused ? $this->t('Puse pe pauză') : $this->t('Active')); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Frecvență ping')); ?></small><strong><?php echo esc_html($pingInterval > 0 ? $pingInterval.' '.$this->t('ore') : '—'); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Ultima comunicare cu serverul')); ?></small><strong><?php echo esc_html((string) ($status['last_ping_at'] ?? '—')); ?></strong></div>
                    <div class="zion-license-status-card"><small><?php echo esc_html($this->t('Telemetrie avansată')); ?></small><strong><?php echo esc_html($telemetryEnabled ? ($telemetryConsent ? $this->t('Activată') : $this->t('Dezactivată')) : $this->t('Dezactivată de server')); ?></strong></div>
                </div>
                <h3><?php echo esc_html($this->t('Changelog')); ?></h3>
                <?php if ($changelog !== '') { ?><div class="zion-license-changelog"><?php $installed = (string) ($status['installed_version'] ?? '');
                    foreach (preg_split('/\R/', $changelog) ?: [] as $line) {
                        if (preg_match('/^##\s+\[?([0-9]+(?:\.[0-9]+){1,3})\]?/', trim($line), $match)) {
                            $isCurrent = $installed !== '' && version_compare($match[1], $installed, '=');
                            $isNew = $installed !== '' && version_compare($match[1], $installed, '>'); ?><p class="zion-license-changelog__version"><?php echo esc_html($line); ?><?php if ($isCurrent) { ?><span class="zion-license-changelog__badge zion-license-changelog__badge--current"><?php echo esc_html($this->t('current version')); ?></span><?php } elseif ($isNew) { ?><span class="zion-license-changelog__badge zion-license-changelog__badge--new"><?php echo esc_html($this->t('new version')); ?></span><?php } ?></p><?php } else { ?><div><?php echo esc_html($line); ?></div><?php }
                            } ?></div><?php } else { ?><p><?php echo esc_html($this->t('Nu există încă un changelog importat pentru acest release.')); ?></p><?php } ?>
                <div class="zion-license-status-actions"><?php if ($updateAvailable && ! empty($status['package_url'])) { ?><a class="button" href="<?php echo esc_url($this->adminPostUrl('zion_license_manual_update')); ?>"><?php echo esc_html($this->t('Actualizează acum')); ?></a><?php } ?><form method="post"><?php wp_nonce_field($this->nonceAction()); ?><input type="hidden" name="zion_license_action" value="refresh_status"><input type="hidden" name="zion_license_product" value="<?php echo esc_attr($this->config->productSlug); ?>"><button type="submit" class="button button-primary"><?php echo esc_html($this->t('Reîmprospătează datele')); ?></button></form><form method="post" onsubmit="return confirm('<?php echo esc_js($this->t('Ești sigur că vrei să dezactivezi licența pentru acest site?')); ?>');"><?php wp_nonce_field($this->nonceAction()); ?><input type="hidden" name="zion_license_action" value="deactivate"><input type="hidden" name="zion_license_product" value="<?php echo esc_attr($this->config->productSlug); ?>"><button type="submit" class="button"><?php echo esc_html($this->t('Dezactivează licența')); ?></button></form><button type="button" class="button" data-zion-license-status-close><?php echo esc_html($this->t('Închide')); ?></button></div>
            </div>
        </div>
        <script>
        (()=>{const modal=document.getElementById(<?php echo wp_json_encode($this->statusModalId()); ?>);if(!modal)return;const open=()=>{modal.classList.add('is-open');modal.setAttribute('aria-hidden','false')};const close=()=>{modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true')};document.addEventListener('click',e=>{const trigger=e.target.closest('[data-zion-license-status-open]');if(trigger&&trigger.dataset.zionLicenseStatusOpen===modal.id){e.preventDefault();open()}if(e.target===modal||e.target.closest('[data-zion-license-status-close]'))close()})})();
        </script>
        <?php
    }

    private function needsLicense(): bool
    {
        return ! in_array((string) get_option($this->stateOption(), ''), ['active', 'free'], true);
    }

    private function t(string $text): string
    {
        return function_exists('__') ? __($text, $this->config->textDomain()) : $text;
    }

    private function normalizeKey(string $key): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim(sanitize_text_field($key))) ?: '');
    }

    private function validKey(string $key): bool
    {
        return preg_match($this->config->licensePattern(), $key) === 1 && strlen($key) === $this->config->licenseLength();
    }

    private function modalId(): string
    {
        return 'zion-license-'.md5($this->config->storageKey());
    }

    private function nonceAction(): string
    {
        return 'zion-license-'.md5($this->config->storageKey());
    }

    private function promptOption(): string
    {
        return 'zion_license_prompt_'.md5($this->config->storageKey());
    }

    private function stateOption(): string
    {
        return 'zion_license_state_'.md5($this->config->storageKey());
    }

    private function flashOption(): string
    {
        return 'zion_license_flash_'.md5($this->config->storageKey());
    }

    private function pluginBasename(): string
    {
        return function_exists('plugin_basename') ? plugin_basename($this->config->pluginFile) : basename($this->config->pluginFile);
    }

    private function statusPageSlug(): string
    {
        return 'zion-license-status-'.md5($this->config->storageKey());
    }

    private function statusPageUrl(): string
    {
        return admin_url('admin.php?page='.$this->statusPageSlug());
    }

    private function statusModalId(): string
    {
        return 'zion-license-status-'.md5($this->config->storageKey());
    }

    private function adminPostNonceAction(): string
    {
        return 'zion-license-admin-post-'.md5($this->config->storageKey());
    }

    private function adminPostUrl(string $action): string
    {
        return add_query_arg([
            'action' => $action,
            '_wpnonce' => wp_create_nonce($this->adminPostNonceAction()),
            'plugin' => $this->pluginBasename(),
        ], admin_url('admin-post.php'));
    }

    private function flash(string $type, string $message): void
    {
        set_transient($this->flashOption(), ['type' => $type, 'message' => $message], MINUTE_IN_SECONDS);
    }

    private function redirect(?string $url = null): never
    {
        wp_safe_redirect($url ?: wp_get_referer() ?: admin_url('plugins.php'));
        exit;
    }
}
