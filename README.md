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
