# Zion WordPress License SDK

SDK PHP pentru integrarea pluginurilor WordPress cu Zion License Server.

Documentația completă de integrare se află în [docs/wordpress-integration.md](docs/wordpress-integration.md).

## Instalare

```bash
composer require zion/wordpress-license-sdk:^0.1.9
```

În arhiva finală a pluginului trebuie inclus și directorul `vendor/`. SDK-ul
folosește `productSlug` și `productKey` ca identificatori publici ai produsului;
cheia de licență, tokenul de activare și URL-urile temporare de update sunt
gestionate de serverul Zion.

Versiunea curentă: **0.1.9**.

## Ce oferă SDK-ul

- activare și heartbeat pentru licență;
- callback REST securizat pentru comenzi de la server;
- ecran de activare și pagină de status în WordPress;
- link `Status licență` lângă acțiunile pluginului din pagina Plugins;
- verificarea conexiunii și reîmprospătarea datelor licenței;
- integrare cu actualizările native WordPress;
- verificarea entitlements prin `FeatureGate`;
- suport pentru pluginuri multilingve prin text domain.

Pentru fluxul complet, inclusiv GitHub Actions și includerea SDK-ului în ZIP,
consultă ghidul de integrare.
