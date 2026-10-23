# PHIRST

NIHR PHIRST WordPress theme built with Flynt, Timber 2, Twig and ACF Pro.

## Development

Requires PHP >=8.1, Node >=20, Composer and Advanced Custom Fields Pro.

```sh
composer install
npm install
npm start
```

Local WordPress: http://phirst.local/ (configured in `vite.config.js`).

```sh
npm run build:production
```

Compiled `dist/` assets are tracked for deployment. Dependencies, credentials and the WordPress database are excluded from Git.

See [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) for implementation and content setup.

## Deployment and UAT

UAT: https://phirst.readysalteddev.co.uk/ (first migrated on 2026-10-23).

Deploy the production `dist/` directory, including `dist/.vite/manifest.json`, and install PHP dependencies from `composer.lock`. Database and uploads must be transferred separately from the theme repository.

Exclude `dist/hot` from transfers and migration-plugin archives. It is a local Vite marker, not a production asset. The theme enables Vite only when WordPress reports a `local` or `development` environment; set UAT to `staging` and the live site to `production` with `WP_ENVIRONMENT_TYPE` in `wp-config.php`. Local development uses `local` and `npm start` as before.

After a whole-site import, verify HTTPS home/site URLs, serialized content URLs, permalinks and uploaded files. Clear WordPress and hosting caches: SiteGround's dynamic HTML cache can retain old localhost asset URLs even after the hot marker is removed. Verify an ordinary request to the homepage, not just a cache-bypassing query string. Keep search indexing disabled on UAT.

## Content setup

On a fresh WordPress database, activate ACF Pro and PHIRST, then run:

```sh
wp eval-file tools/seed-phirst-site.php
wp eval-file tools/import-phirst-homepage.php
```

The initial seed preserves an already configured Home. Review [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) before rerunning setup over edited content. [COMPONENTS.md](COMPONENTS.md) inventories the existing component library.

To update only the partner strip on an existing seeded site, run `wp eval-file tools/import-phirst-partners.php`. The nine original logos are bundled locally and imported as separate Media Library attachments.

## Legacy content migration

The complete PHIRST migration is available locally and on UAT: real evaluation themes, all evaluations/news, staff profiles and source documents. See [MIGRATION.md](MIGRATION.md) for setup commands, editing, validation and source-content exceptions. Database/uploads and private source exports are separate from the theme repository.
