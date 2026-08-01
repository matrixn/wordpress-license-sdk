# Zion WordPress License SDK

SDK PHP pentru integrarea pluginurilor WordPress cu Zion License Server.

Documentația completă de integrare se află în [docs/wordpress-integration.md](docs/wordpress-integration.md).

## Instalare

```bash
composer require zion/wordpress-license-sdk:^0.2
```

În arhiva finală a pluginului trebuie inclus și directorul `vendor/`. SDK-ul
folosește `productSlug` și `productKey` ca identificatori publici ai produsului;
cheia de licență, tokenul de activare și URL-urile temporare de update sunt
gestionate de serverul Zion.

Versiunea curentă: **0.2.0**.

## Ce oferă SDK-ul

- activare și heartbeat pentru licență;
- callback REST securizat pentru comenzi de la server;
- ecran de activare și pagină de status în WordPress;
- link `Status licență` lângă acțiunile pluginului din pagina Plugins;
- linkuri pentru `Activează auto-update`, `Dezactivează auto-update` și
  `Actualizează acum`, afișate numai când serverul permite acțiunea;
- verificarea conexiunii și reîmprospătarea datelor licenței, inclusiv golirea
  cache-ului WordPress pentru update-uri;
- integrare cu actualizările native WordPress;
- verificare strictă a versiunii serverului și executarea update-ului privat prin
  `LicenseManager::updateIfAvailable()`;
- verificarea entitlements prin `FeatureGate`;
- planuri Free, Pro, Business și Agency transmise de server;
- verificarea funcțiilor premium prin `LicenseManager::allows()`;
- suport pentru pluginuri multilingve prin text domain.

Pentru fluxul complet, inclusiv GitHub Actions și includerea SDK-ului în ZIP,
consultă ghidul de integrare.

## Verificare și executare update

SDK-ul compară versiunea din headerul pluginului cu versiunea publicată de Zion.
Un update este disponibil numai când versiunea serverului este strict mai mare și
serverul a furnizat un URL ZIP temporar semnat.

```php
$status = $licenseManager->updateStatus();

if ($status['available']) {
    echo esc_html($status['latest_version']);
}

if (current_user_can('update_plugins')) {
    $result = $licenseManager->updateIfAvailable();
}
```

`updateIfAvailable()` folosește `Plugin_Upgrader` și nu descarcă nimic când nu
există o versiune mai nouă sau utilizatorul nu are capabilitatea necesară.

În modalul `Status licență`, butonul `Reîmprospătează datele` face un ping
imediat, actualizează `last_ping_at` și reconstruiește metadatele de update.

## Planuri și entitlements

Serverul transmite planul activ și capabilitățile permise la fiecare ping.
Aceste valori sunt un control de interfață în plugin; serverul rămâne sursa
de adevăr pentru licență și nu trebuie tratate ca un secret.

```php
if ($licenseManager->allows('analytics')) {
    $this->registerAnalytics();
}

$status = $licenseManager->status();
// $status['plan'] === 'pro'
// $status['entitlements']['multiple_templates'] === true
```

Pentru cod mai complex poți folosi direct:

```php
$gate = $licenseManager->featureGate();

if ($gate->allows('white_label')) {
    $this->enableWhiteLabel();
}
```

Cheile disponibile și planurile implicite sunt definite de License Server.
Un plugin trebuie să păstreze un fallback sigur când o capabilitate nu este
prezentă sau serverul nu poate fi contactat.

