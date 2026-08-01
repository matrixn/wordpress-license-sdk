# Changelog

## [0.1.17] - 2026-08-01

- Added the server-defined ping interval to the read-only license status modal.
- Added support for a signed force-update command from the license server.

## [0.1.16] - 2026-08-01

- Added the native WordPress Automatic Updates column control for private plugins.
- Kept the explicit plugin action link as a fallback on WordPress versions that hide the column.

## [0.1.15] - 2026-08-01

- Added explicit Enable/Disable auto-update and Update now actions beside the plugin.
- Added the same control to WordPress' native Automatic Updates column for private plugins.
- Added a force-refresh action that pings the license server and clears WordPress update caches.
- The license status modal now shows the last server communication and includes a refresh button.
- Improved error handling for manual updates and server permission checks.

## [0.1.14] - 2026-08-01

- Fixed plugin activation fatal error caused by the missing status modal ID helper.

## [0.1.13] - 2026-08-01

- Fixed WordPress update detection when the cached `update_available` flag is stale.
- Added server-interval refreshes on the Plugins screen and native auto-update permission handling.
- Added direct update execution, update metadata, last-update tracking, and a status/changelog modal.

## [0.1.12] - 2026-08-01

- Added `LicenseManager::updateStatus()` to compare the installed plugin version
  with the version published by Zion.
- Added `LicenseManager::updateIfAvailable()` to execute a private update through
  WordPress `Plugin_Upgrader` only when the server version is newer.
- Kept capability checks, temporary package URLs, and safe no-update behavior.

## [0.1.11] - 2026-08-01

- Repară refresh-ul paginii de status și afișarea datei de expirare.
- Raportează versiunea SDK către server și afișează diferențele de versiune.
- Reîmprospătează update-urile WordPress și poate executa update automat când pluginul este configurat pentru actualizări automate.

## [0.1.10] - 2026-08-01

- Update-urile WordPress sunt anunțate numai când serverul furnizează un URL ZIP semnat și temporar.
- ZIP-ul release-ului importat din GitHub este livrat prin serverul de licențe, fără expunerea repository-ului privat.

Toate modificările sunt documentate aici începând cu primele commituri ale
pachetului. Versiunile sunt publicate prin taguri Git.

## [0.1.9] - 2026-07-31

- Adăugată documentația completă pentru integrarea SDK-ului în pluginuri
  WordPress în `docs/wordpress-integration.md`.
- Actualizat README-ul cu instalare, bootstrap, status, update-uri și reguli de
  securitate.
- Aliniată constanta `LicenseManager::VERSION` la versiunea publicată.

## [0.1.8] - 2026-07-31

- Adăugată pagina de status al licenței în administrarea WordPress.
- Adăugat linkul `Status licență` în acțiunile pluginului din pagina Plugins.
- Adăugată verificarea manuală a conexiunii și reîmprospătarea datelor licenței.
- Adăugat statusul callback-ului securizat și persistarea ultimului ping.
- Adăugată interogarea configurației de update și integrarea cu update-urile
  native WordPress.

## [0.1.7] - 2026-07-31

- Publicat tagul inițial de versiune pentru pachetul pregătit pentru Composer.

## Înainte de 0.1.7

- Pregătit pachetul pentru Packagist și adăugat metadatele Composer.
- Adăugat `.gitignore` pentru artefacte locale Composer.
- Adăugate componentele de bază: `Config`, `FeatureGate`, `LicenseManager`,
  `LicensePrompt`, `ServerCommandEndpoint`, `WordPressHttpClient` și
  `WordPressUpdateAdapter`.
- Adăugat bootstrap-ul inițial și README-ul proiectului.

## Istoric de commituri

Commiturile care au construit pachetul, în ordine:

```text
3cf8e1e  Publica versiunea initiala a Zion WordPress License SDK
e4185d4  Publica versiunea initiala a Zion WordPress License SDK
40415b6  Adauga README.md
d7666c6  Adauga src/Config.php
6391335  Adauga src/FeatureGate.php
a5c049d  Adauga src/LicenseManager.php
cbc07eb  Adauga src/LicensePrompt.php
c659cf9  Adauga src/ServerCommandEndpoint.php
c01b4c4  Adauga src/WordPressHttpClient.php
ccf4f52  Adauga src/WordPressUpdateAdapter.php
f2c9121  Finished composer.json
adb3a15  Pregateste
58f8d47  Pregateste pachetul pentru Packagist
18ba008  Pregateste
668378c  Ignora fisierele locale Composer
7ce44c1  tag 0.1.7
9c7755a  Add license status page and update checks
```
