# wp-hub-updater

<p><img alt="PHP 8.3+" src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white"> <img alt="WordPress 6.6+" src="https://img.shields.io/badge/WordPress-6.6%2B-21759B?logo=wordpress&logoColor=white"> <img alt="License" src="https://img.shields.io/badge/license-MIT-green"> <img alt="Packagist Version" src="https://img.shields.io/packagist/v/onstage2426/wp-hub-updater"> <img alt="CI" src="https://github.com/onstage2426/wp-hub-updater/actions/workflows/ci.yml/badge.svg"></p>

Delivers WordPress plugin and theme updates from a GitHub repository. Hooks into the standard WordPress update system so your entity appears in the Updates screen and can be installed with a single click.

## Installation

```bash
composer require --dev onstage2426/wp-hub-updater
```

**Requirements:** PHP 8.3+, WordPress 6.6+

> This is a build-time tool — it generates a prefixed copy of itself into your project and is excluded from the final ZIP. See [Distribution → Namespace isolation](docs/distribution.md#namespace-isolation).

## Quick start

Examples below use `MyPlugin` as the namespace prefix. Replace it with whatever you passed to `vendor/bin/wp-hub-updater`.

**Plugin** — add two lines to your main plugin file:

```php
use MyPlugin\WpHubUpdater\Plugin\PluginUpdater;

PluginUpdater::build('https://github.com/your-org/your-plugin', __FILE__)
    ->setAccessToken('ghp_xxxx');  // omit for public repos
```

**Theme** — add two lines to `functions.php`:

```php
use MyPlugin\WpHubUpdater\Theme\ThemeUpdater;

ThemeUpdater::build('https://github.com/your-org/your-theme', 'your-theme')
    ->setAccessToken('ghp_xxxx');  // omit for public repos
```

## Documentation

- [Configuration](docs/configuration.md) — all chainable methods, access token, branch, cooldown, release filters, reading state
- [WordPress filters](docs/filters.md) — filters and actions for info, update, detection strategies, error handling
- [Data classes](docs/data-classes.md) — `Update`, `PluginInfo`, `ThemeInfo` properties
- [Distribution](docs/distribution.md) — namespace isolation, release pipeline, draft-first workflow, version stamping, ZIP
- [`hu.json`](docs/hu-json.md) — metadata schema, changelog, local assets
- [Advanced](docs/advanced.md) — MU-plugins, debugging, WP-CLI, reliability features

## License

MIT — see [LICENSE](LICENSE).
