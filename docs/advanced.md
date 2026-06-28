# MU-plugins, debugging, WP-CLI & reliability

---

## MU-plugins

MU-plugins placed directly in `wp-content/mu-plugins/` (not in a subdirectory) are detected automatically — no extra call needed.

MU-plugins in a subdirectory (e.g. `mu-plugins/my-plugin/my-plugin.php`) must call `setMuPluginFile()` with the plugin's filename:

```php
$updater = PluginUpdater::build(
    'https://github.com/your-org/your-repo',
    __FILE__,
)->setMuPluginFile('my-plugin.php');
```

---

## Debugging

Enable debug mode to send errors and warnings to `error_log()`:

```php
$updater->enableDebugMode();
```

This logs warnings when the plugin/theme file cannot be read, the version header is missing, or the API returns no usable version. On any site where `wp_get_environment_type()` returns `'development'` or `'staging'`, debug mode activates automatically without needing `WP_DEBUG = true`.

To inspect errors from the last check programmatically:

```php
$updater->checkForUpdates();

foreach ($updater->getLastRequestApiErrors() as $item) {
    error_log($item['error']->get_error_message() . ' — ' . $item['url']);
}
```

---

## WP-CLI

When WP-CLI is active, the updater automatically registers a command group for each configured instance. No extra setup required.

**Command namespace:** `wp hu <slug>`

### `wp hu <slug> check`

Triggers an immediate update check and prints the result.

```
$ wp hu my-plugin check
Checking for updates...
Success: Update available: 1.4.2
```

### `wp hu <slug> status`

Displays the current update state without making any API calls.

```
$ wp hu my-plugin status
slug:                  my-plugin
type:                  plugin
installed version:     1.3.0
last check:            2026-06-09 10:34:21 UTC
available version:     1.4.2
update available:      yes
cooldown active:       no
requirement blocked:   no
```

### `wp hu <slug> clear`

Resets the stored state — clears the last-check timestamp and any stored update record.

```
$ wp hu my-plugin clear
Success: Update state cleared.
```

### `wp hu <slug> enable-auto-updates` / `disable-auto-updates`

Opts the entity in or out of WordPress automatic background updates.

```
$ wp hu my-plugin enable-auto-updates
Success: Automatic updates enabled.
```

To install a pending update, use the standard WP-CLI command:

```
$ wp plugin update my-plugin   # or: wp theme update my-theme
```

---

## Reliability features

**Concurrent check lock** — if two requests try to run `checkForUpdates()` at the same time, one acquires a short-lived lock and performs the API call while the other immediately returns the last-known stored update. The lock expires after 60 seconds, so a crashed process can never block future checks.

**Transient-failure retries** — failed API requests (network errors, `500`/`502`/`503`/`504`) are retried with exponential backoff. See [`setMaxRetries()`](configuration.md#setmaxretriesint-maxretries--2-int-initialdelayms--500-static) to change the defaults.

**Automatic rollback on upgrade failure** — before each upgrade, the updater captures the download URL of the currently-installed version and passes it to the WordPress upgrader as rollback data. If the upgrade fails, WordPress restores the previous version automatically.

**Rate-limit backoff** — when GitHub returns a rate-limit response (429 or 403 with `x-ratelimit-remaining: 0`), all subsequent API calls are automatically skipped until the reset time. Defaults to a 1-hour block when no reset time is provided. The block is stored in `hu_ratelimit-{slug}` and cleared automatically when it expires.

**ETag cache integrity** — the ETag cache is validated every time it is loaded from the database and again before each request. Corrupt or partially-written entries are discarded. The cache is capped at 30 entries to prevent unbounded growth.

**API version sunset handling** — all requests include `X-GitHub-Api-Version: 2026-03-10`. On a 410 response (version deprecated), the request is automatically retried once without the version header so updates keep working on long-lived installs. Update the library when you see `hu-github-api-version-expired` in your logs.

---

## Full example

```php
use MyPlugin\WpHubUpdater\Plugin\PluginUpdater;
use MyPlugin\WpHubUpdater\Plugin\PluginInfo;

$updater = PluginUpdater::build(
    'https://github.com/acme/my-plugin',
    __FILE__,
    'my-plugin',
    24
);

$updater
    ->setAccessToken('ghp_xxxx')
    ->setReleaseVersionFilter('/^2\./')
    ->throttleRedundantChecks(hours: 72)
    ->enableAutoUpdates()
    ->enableDebugMode();

add_filter($updater->getUniqueName('request_info_result'), function (?PluginInfo $info): ?PluginInfo {
    if ($info && !empty($info->sections['changelog'])) {
        $info->sections['changelog'] .= '<p><a href="https://github.com/acme/my-plugin/releases">Full release history on GitHub</a></p>';
    }
    return $info;
});
```
