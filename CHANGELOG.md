# Changelog

## [Unreleased]

## [0.4.5] - 2026-08-04

### Fixed

- status license modals now open only for the plugin link that was clicked;
  multiple plugins using the SDK no longer open duplicate modal dialogs.

## [0.4.2] - 2026-08-02

### Added

- opt-in advanced telemetry consent in the license activation modal;
- technical telemetry is sent only after explicit consent and server enablement;
- telemetry consent and server policy are visible in the license status modal.

### Privacy

- advanced telemetry contains compatibility metadata only; license keys,
  passwords and site content are never included.

## [0.4.1] - 2026-08-02

### Added

- Serverul poate impune verificarea manifestului semnat prin configurația transmisă SDK-ului.
- Configurația operațională primită de SDK rămâne compatibilă cu verificarea locală a cheii publice.

## [0.4.0] - 2026-08-02

### Added

- release manifests signed with Ed25519 and verified in the SDK;
- SHA-256 verification of the downloaded package before WordPress installation;
- PHP, WordPress and minimum SDK compatibility checks;
- update result reporting back to the License Server;
- idempotent server configuration commands (excluding `force_update`).
- stable, beta and alpha release channels with deterministic rollout selection;
- package URL host allowlisting and server-side eligibility checks;
- server rollback support that stops distribution of a withdrawn release.

## [0.3.0] - 2026-08-02

### Added

- protocol Zion versionat (`1.0`) și header `X-Zion-Protocol-Version`;
- excepții API cu cod, status și request ID;
- activare, validare și dezactivare explicită;
- token opac per instalare, stocat criptat în WordPress;
- fallback compatibil pentru serverele care nu au încă endpointul de activare;
- răspunsuri de server cu versiunea minimă și recomandată a SDK-ului.
- enum-uri pentru stările licenței și politica offline;
- jitter și lock pentru heartbeat, plus hook-uri pentru ping reușit/eșuat și schimbări de stare.

### Security

- heartbeat-ul folosește tokenul de instalare după activare și nu retransmite
  cheia de licență când tokenul este disponibil;
- tokenul este revocat la dezactivarea explicită.

- Criptează cheia de licență în opțiunea WordPress și migrează automat valorile legacy stocate în clar.
- Sanitizează configurația primită prin callback-ul serverului înainte de a o persista.
- Reîmprospătează URL-ul semnat de update când cache-ul local a expirat.
- Adaugă retry limitat pentru erori temporare și request ID pentru call-urile API.

## [0.2.0] - 2026-08-01

- Added Free, Pro, Business and Agency plan information from the server.
- Added `LicenseManager::plan()`, `entitlements()`, `featureGate()` and
  `allows()` helpers for premium feature gates.
- Exposed plan and entitlements in `LicenseManager::status()`.
- Kept entitlements fail-closed when the server does not provide a capability.

## [0.1.19] - 2026-08-01

- Injected private plugin updates when WordPress reads an existing update transient.
- Kept the server's explicit `update_available` decision authoritative, including paused updates.

## [0.1.18] - 2026-08-01

- Added the last four characters of the activated license to the read-only status modal.
- Added the server-controlled paused/active update state to the status modal.

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
