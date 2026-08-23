# Zion WordPress License SDK

SDK PHP pentru integrarea pluginurilor WordPress cu Zion License Server.

Documentația completă de integrare se află în [docs/wordpress-integration.md](docs/wordpress-integration.md).

## Instalare

```bash
composer require zion/wordpress-license-sdk:^0.3
```

În arhiva finală a pluginului trebuie inclus și directorul `vendor/`. SDK-ul
folosește `productSlug` și `productKey` ca identificatori publici ai produsului;
cheia de licență, tokenul de activare și URL-urile temporare de update sunt
gestionate de serverul Zion.

Versiunea curentă: **0.4.12**.

La un update executat prin server, SDK-ul memorează dacă pluginul era activ
înainte de instalare și îl reactivează automat după un update reușit. Pluginurile
dezactivate intenționat rămân dezactivate; dacă reactivarea eșuează, rezultatul
este raportat explicit către server în loc să ascundă problema.

La activare, administratorul poate alege explicit dacă permite telemetria
avansată. Când este activată, SDK-ul trimite doar date tehnice de compatibilitate
(versiuni, limbă, fus orar, multisite și temă); nu trimite conținut, parole sau
chei de licență. Serverul poate dezactiva oricând colectarea din setarea
`telemetry_enabled`, iar SDK-ul afișează starea în modalul licenței.

## Activare și dezactivare explicită

SDK-ul 0.3 folosește un token opac per instalare după activarea inițială.
Cheia de licență este trimisă la activare, iar heartbeat-urile ulterioare pot
folosi tokenul fără să retransmită cheia.

```php
$licenseManager->activate($licenseKey);
$status = $licenseManager->validateLicense();
$licenseManager->deactivate();
```

### Produse cu actualizări gratuite

Un produs poate fi configurat în Zion cu modul `updates_only`. Pentru acest mod
nu se cere și nu se salvează o cheie de licență. `productKey` rămâne obligatoriu:
este identificatorul public al produsului și autentifică primul contact al site-
ului. SDK-ul trimite ping-ul inițial, iar serverul creează automat instalarea și
emite tokenul opac per site.

```php
$status = $licenseManager->status();

if ($status['license_state'] === 'updates_only') {
    // Site-ul este înregistrat pentru update-uri fără licență comercială.
    $this->showFreeUpdatesBadge();
}
```

Serverul poate suspenda update-urile, modifica frecvența heartbeat-ului sau
dezactiva auto-update-ul pentru fiecare instalare, la fel ca pentru un produs
licențiat. SDK-ul nu consideră niciodată `productKey` un secret; nu include chei
de publicare GitHub sau credențiale de server în plugin.

Dezactivarea este explicită și eliberează activarea de pe server. Dezactivarea
pluginului nu dezactivează automat licența.

Răspunsurile API folosesc protocolul Zion `1.0`, iar erorile au coduri stabile
în `Zion\\WordPressLicense\\Exceptions\\ApiException`.

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
- stări explicite de licență și politică offline `Lenient` implicită;
- heartbeat cu jitter și lock pentru a evita ping-urile concurente;
- planuri Free, Pro, Business și Agency transmise de server;
- verificarea funcțiilor premium prin `LicenseManager::allows()`;
- suport pentru pluginuri multilingve prin text domain.

Pentru fluxul complet, inclusiv GitHub Actions și includerea SDK-ului în ZIP,
consultă ghidul de integrare.

Politica offline implicită (`OfflinePolicy::Lenient`) păstrează funcțiile premium
până la `grace_until` primit de la server. `OfflinePolicy::Strict` poate fi
folosită pentru integrări care trebuie să ceară o verificare online mai strictă.

## Verificare și executare update

SDK-ul compară versiunea din headerul pluginului cu versiunea publicată de Zion.
Un update este disponibil numai când versiunea serverului este strict mai mare și
serverul a furnizat un URL ZIP temporar semnat.

Pentru verificarea criptografică a release-urilor, configurează cheia publică
Ed25519 livrată de server. Cheia este publică și poate fi inclusă în plugin;
cheia privată rămâne exclusiv pe server.

```php
$config = new Config(
    apiUrl: 'https://license.zion3d.ro/api/v1',
    productSlug: 'demo-plugin',
    pluginFile: __FILE__,
    productKey: 'zion_public_product_key',
    updatePublicKey: 'BASE64_ED25519_PUBLIC_KEY',
    updateKeyId: 'default',
    requireSignedUpdates: true,
);
```

Manifestul semnat conține versiunea, canalul, numele ZIP-ului și checksum-ul
SHA-256. SDK-ul verifică semnătura înainte de a afișa update-ul și verifică
checksum-ul fișierului după download, înainte ca WordPress să îl instaleze.
Dacă mediul nu este compatibil sau manifestul nu poate fi verificat, update-ul
este blocat și statusul expune motivul în `update_blocked_reason`.

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

