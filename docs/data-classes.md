# Data classes

---

## `Update`

The minimal record written to the WordPress update transient.

| Property | Type | Description |
|---|---|---|
| `$slug` | `?string` | Entity slug |
| `$version` | `?string` | Version string from the GitHub release/tag, e.g. `"1.4.2"` |
| `$download_url` | `?string` | ZIP download URL |
| `$homepage` | `?string` | URI from the plugin/theme header |
| `$icons` | `array` | Icon URLs from the local `assets/` directory (plugins only) |
| `$filename` | `?string` | Plugin basename (plugins only) |
| `$last_updated` | `?string` | Release `published_at` from GitHub (falls back to `created_at`); `null` for branch-based sources |
| `$requires` | `?string` | Minimum WP version from `hu.json` |
| `$requires_php` | `?string` | Minimum PHP version from `hu.json` |

If the site does not meet `$requires` or `$requires_php`, the update is not surfaced — `getUpdate()` returns `null` until the site is upgraded.

---

## `PluginInfo`

Full metadata shown in the "View details" popup for plugins.

| Property | Type | Source |
|---|---|---|
| `$name` | `?string` | Plugin Name header |
| `$slug` | `?string` | Plugin slug |
| `$version` | `?string` | Version from GitHub |
| `$homepage` | `?string` | Plugin URI header |
| `$sections` | `array` | Keyed popup sections. `description` from header; `changelog` from `hu.json`, release body, or local file |
| `$download_url` | `?string` | ZIP download URL |
| `$banners` | `array` | Banner images from `assets/` or `hu.json` |
| `$icons` | `array` | Icon images from `assets/` or `hu.json` |
| `$author` | `?string` | Author header |
| `$author_homepage` | `?string` | Author URI header |
| `$downloaded` | `?int` | Download count from the release asset |
| `$last_updated` | `?string` | Release `published_at` from GitHub (falls back to `created_at`) |
| `$requires` | `?string` | Minimum WP version from `hu.json` |
| `$tested` | `?string` | Tested-up-to WP version from `hu.json` |
| `$requires_php` | `?string` | Minimum PHP version from `hu.json` |
| `$contributors` | `?array` | Contributors map from `hu.json` |
| `$filename` | `?string` | Plugin basename |

---

## `ThemeInfo`

Full metadata shown in the theme info popup.

| Property | Type | Source |
|---|---|---|
| `$name` | `?string` | Theme Name header |
| `$slug` | `?string` | Theme slug |
| `$version` | `?string` | Version from GitHub |
| `$homepage` | `?string` | Theme URI header |
| `$sections` | `array` | Keyed popup sections. `description` from header; `changelog` from `hu.json`, release body, or local file |
| `$download_url` | `?string` | ZIP download URL |
| `$screenshot_url` | `?string` | From `wp_get_theme()->get_screenshot()` or `hu.json` |
| `$author` | `?string` | Author header |
| `$author_homepage` | `?string` | Author URI header |
| `$downloaded` | `?int` | Download count from the release asset |
| `$last_updated` | `?string` | Release `published_at` from GitHub (falls back to `created_at`) |
| `$requires` | `?string` | Minimum WP version from `hu.json` |
| `$tested` | `?string` | Tested-up-to WP version from `hu.json` |
| `$requires_php` | `?string` | Minimum PHP version from `hu.json` |
| `$contributors` | `?array` | Contributors map from `hu.json` |
