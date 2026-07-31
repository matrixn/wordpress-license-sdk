# Integrarea SDK-ului în pluginuri WordPress

Acest ghid este pentru dezvoltatorul unui plugin care folosește Zion License
Server pentru licențiere și update-uri private.

## 1. Instalează SDK-ul

În directorul pluginului:

```bash
composer require zion/wordpress-license-sdk:^0.1.9
```

În `composer.json`, SDK-ul trebuie să fie în `require`, nu în `require-dev`,
deoarece este folosit pe site-ul clientului. După instalare, rulează:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

La împachetarea pluginului, păstrează `vendor/` și fișierele SDK runtime în ZIP.
Poți exclude testele, `.git`, `.github` și fișierele locale de dezvoltare.

## 2. Bootstrap-ul pluginului

În fișierul principal al pluginului, după antetul WordPress, încarcă autoloaderul
și creează configurația SDK:

```php
<?php
/**
 * Plugin Name: Exemplu Zion
 * Version: 1.0.0
 * Author: Zion
 * Text Domain: exemplu-zion
 */

defined('ABSPATH') || exit;

require_once __DIR__.'/vendor/autoload.php';

use Zion\WordPressLicense\Config;
use Zion\WordPressLicense\LicenseManager;

$zionLicense = new LicenseManager(new Config(
    apiUrl: 'https://license.zion3d.ro/api/v1',
    productSlug: 'exemplu-zion',
    pluginFile: __FILE__,
    productKey: 'zpk_produsul-tau',
    sendAdminEmail: false,
    pluginName: 'Exemplu Zion',
    textDomain: 'exemplu-zion',
));
```

`apiUrl` trebuie să fie HTTPS și să se termine exact în `/api/v1`. `productSlug`
este slugul stabil al produsului, iar `productKey` este un identificator public
injectat în build. Nu folosi `productKey` ca secret: orice valoare dintr-un plugin
WordPress poate fi citită de administratorul site-ului.

## 3. Activează licența

SDK-ul adaugă automat o interfață de activare în administrarea WordPress. Dacă
licența nu este activă, poți afișa butonul în propriul ecran de setări:

```php
echo LicensePrompt::trigger('exemplu-zion', 'Activează licența');
```

Utilizatorul introduce cheia în formatul produsului, iar SDK-ul trimite activarea
la server. Cheia este salvată în opțiunile WordPress ale site-ului.

## 4. Pagina de status și verificarea conexiunii

SDK-ul înregistrează o pagină `Status licență` în meniul Tools și adaugă linkul
`Status licență` în pagina Plugins, lângă acțiunile pluginului (inclusiv lângă
Dezactivare). Pagina afișează:

- starea licenței;
- versiunea instalată;
- data ultimului ping;
- expirarea și versiunea disponibilă;
- starea callback-ului securizat;
- URL-ul callback-ului;
- un buton pentru verificarea conexiunii și actualizarea datelor.

Recomandă utilizatorului să apese acest buton după instalarea sau actualizarea
SDK-ului. Ping-ul înregistrează callback-ul securizat, astfel încât serverul să
poată trimite comenzi către instalarea respectivă.

## 5. Verificarea funcționalităților licențiate

Nu răspândi requesturi API prin codul pluginului. Folosește `FeatureGate` și
controlează doar funcțiile premium:

```php
use Zion\WordPressLicense\FeatureGate;

$configuration = $zionLicense->runtimeConfiguration();
$gate = new FeatureGate(
    is_array($configuration['entitlements'] ?? null)
        ? $configuration['entitlements']
        : [],
);

if ($gate->allows('advanced_reports')) {
    // Înregistrează meniul și funcțiile premium.
}
```

Pluginul trebuie să rămână stabil când serverul este indisponibil. Păstrează
grace period-ul și dezactivează doar funcțiile premium, nu întregul site.

## 6. Update-uri native WordPress

`LicenseManager` înregistrează automat `WordPressUpdateAdapter`. Serverul trimite
configurația de update prin ping, iar SDK-ul o folosește pentru:

- verificarea versiunii instalate;
- anunțarea unei versiuni noi în Plugins;
- afișarea informațiilor pluginului;
- descărcarea pachetului prin URL-ul furnizat de server.

Pluginul trebuie să aibă un antet WordPress valid cu `Version`. Dacă versiunea
din release este `1.2.0`, actualizează și antetul pluginului înainte de build.

Pentru verificarea manuală, administratorul poate deschide pagina de status și
poate apăsa „Verifică conexiunea și actualizează”. Heartbeat-ul automat repetă
verificarea conform intervalului primit de la server.

## 7. Build-ul release-ului

Un build minimal pentru GitHub Actions:

```yaml
- name: Install runtime dependencies
  run: composer install --no-dev --prefer-dist --optimize-autoloader

- name: Build plugin archive
  run: |
    mkdir -p build/exemplu-zion
    rsync -a ./ build/exemplu-zion/ \
      --exclude=.git \
      --exclude=.github \
      --exclude=tests \
      --exclude=node_modules \
      --exclude=build
    zip -r exemplu-zion.zip build/exemplu-zion
```

Dacă folosești workflow-ul Zion, `productSlug` și `productKey` se injectează în
copia din `build/`, nu în sursa repository-ului. Secretul de publicare trebuie
să rămână în GitHub Actions Secrets și nu se include niciodată în ZIP.

## 8. Reguli de securitate

- folosește întotdeauna `https://license.zion3d.ro/api/v1`;
- nu trata `productKey` ca secret;
- nu include tokenuri de publicare, chei private sau PAT-uri în plugin;
- nu loga cheia de licență sau URL-uri de download complete;
- validează datele primite de utilizator prin escaping WordPress;
- păstrează `vendor/` în arhiva runtime;
- testează instalarea ZIP-ului pe un WordPress curat înainte de publicare.

## 9. Depanare

### Serverul raportează că lipsește callback-ul securizat

Actualizează SDK-ul la ultima versiune, intră în `Plugins → Status licență` și
apasă butonul de verificare. Apoi rulează un heartbeat sau așteaptă următorul
interval. Callback-ul este înregistrat la ping și nu poate fi înregistrat doar
prin faptul că fișierul SDK există în `vendor/`.

### Nu apare update-ul

Verifică antetul `Version`, cheia activă, starea licenței, ultimul ping și dacă
serverul a publicat un `package_url`. Curăță cache-ul de update WordPress și
rulează din nou verificarea din pagina de status.

### Pluginul produce o eroare după instalare

Verifică existența `vendor/autoload.php`, compatibilitatea PHP (`^8.1`) și faptul
că `pluginFile` indică fișierul principal lizibil al pluginului.

## 10. Compatibilitate și versionare

Versiunile SDK urmează Semantic Versioning. Pentru producție folosește o
constrângere de forma `^0.1.9` și actualizează controlat. Testează fiecare
actualizare pe un site staging înainte de a genera release-ul public.
