# Changelog

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
