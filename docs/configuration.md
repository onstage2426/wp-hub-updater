# Configuration methods & reading state

All configuration methods return `static` for chaining and apply to both `PluginUpdater` and `ThemeUpdater` unless noted otherwise. Call them immediately after `build()`.

## Public properties

**Shared:**

| Property | Type | Description |
|---|---|---|
| `$updater->slug` | `string` | Unique slug for this updater instance |

**Plugin-only:**

| Property | Type | Description |
|---|---|---|
| `$updater->pluginFile` | `string` | Plugin basename, e.g. `my-plugin/my-plugin.php` |
| `$updater->directoryName` | `string` | Plugin directory name |

**Theme-only:**

| Property | Type | Description |
|---|---|---|
| `$updater->directoryName` | `string` | Theme directory name (same as slug) |

---

## Configuration methods

### `setAccessToken(string $token): static`

Sets the GitHub personal access token for private repositories.

```php
$updater->setAccessToken('ghp_xxxx');
```

The token is injected as `Authorization: token …` on every API call and on the ZIP download. It is never written to any database option. Public repositories work without a token.

To generate a token: GitHub → Settings → Developer settings → Personal access tokens. Minimum scope: `repo` (classic) or `Contents: Read` (fine-grained).

---

### `setBranch(string $branch): static`

Sets the branch to use as the update source. Default is `"main"`.

When the branch is `"master"` or `"main"`, strategies are tried in order: latest release → latest version tag → branch HEAD. For any other branch, only the branch HEAD is used (no version number, no release/tag detection).

```php
$updater->setBranch('develop');   // branch HEAD only
$updater->setBranch('master');    // legacy repos
```

---

### `enableAutoUpdates(bool $enable = true): static`

Opts the entity into WordPress automatic background updates.

```php
$updater->enableAutoUpdates();        // enable
$updater->enableAutoUpdates(false);   // disable
```

Writes to the same site option the admin UI toggle uses (`auto_update_plugins` / `auto_update_themes`).

---

### `setCooldown(int $hours = 24): static`

How many hours after a release is published before the update is surfaced to WordPress. Defaults to `24`. Set to `0` to disable.

```php
$updater->setCooldown(48);   // 48-hour window to catch bad releases
$updater->setCooldown(0);    // surface immediately
```

Has no effect on branch-based sources (they carry no release timestamp).

---

### `setReleaseFilter(callable $callback, int $releaseTypes, int $maxReleases): static`

Picks the latest release by running a custom callback on recent releases.

```php
setReleaseFilter(
    callable $callback,
    int      $releaseTypes = PluginUpdater::RELEASE_FILTER_SKIP_PRERELEASE,
    int      $maxReleases  = 20,   // min 1, max 100
): static
```

`$callback` is called as `fn(string $version, object $release): bool`. Return `true` to accept a release.

```php
$updater->setReleaseFilter(
    fn(string $version) => str_contains($version, '-stable'),
    PluginUpdater::RELEASE_FILTER_ALL,
    50
);
```

| Constant | Description |
|---|---|
| `::RELEASE_FILTER_SKIP_PRERELEASE` | Skip releases marked as pre-release (default) |
| `::RELEASE_FILTER_ALL` | Include pre-releases |

---

### `setReleaseVersionFilter(string $regex, int $releaseTypes, int $maxReleasesToExamine): static`

Shorthand for `setReleaseFilter()` — filters by version string using a regular expression.

```php
$updater->setReleaseVersionFilter('/^2\./');             // only 2.x releases
$updater->setReleaseVersionFilter('/^2\./', PluginUpdater::RELEASE_FILTER_ALL, 30);
```

---

### `setAssetFilter(string $pattern): static`

When a release has multiple assets, selects the first whose name contains `$pattern`. Falls back to the first asset when no match is found.

```php
$updater->setAssetFilter('my-plugin-');   // prefer my-plugin-1.0.zip over my-plugin-1.0-debug.zip
```

---

### `throttleRedundantChecks(bool $enable = true, int $hours = 72): static`

Skips scheduled checks when an update is already queued. Avoids unnecessary API calls when nothing has changed.

```php
$updater->throttleRedundantChecks();            // 72-hour throttle
$updater->throttleRedundantChecks(hours: 48);   // custom period
$updater->throttleRedundantChecks(false);        // disable
```

---

### `setMaxRetries(int $maxRetries, int $initialDelayMs = 500): static`

Controls retries for failed API requests. Only retries transient failures: network errors and `500`/`502`/`503`/`504`. Rate limits, auth failures, and 404s are not retried.

During interactive page loads, retries are capped at 1 regardless of `$maxRetries`.

```php
$updater->setMaxRetries(3, 250);   // up to 3 retries, starting at 250 ms (doubles each retry)
$updater->setMaxRetries(0);        // disable retries
```

---

### `setTextDomain(string $domain): static`

Sets the text domain used for translating admin notices and manual-check result messages. Leave unset if your plugin/theme has no translations for this library's strings.

```php
$updater->setTextDomain('my-plugin');
```

---

### `enableDebugMode(bool $enable = true): static`

Sends errors and warnings to `error_log()`. Debug mode activates automatically — without calling this method — when `WP_DEBUG` is `true` **or** when `wp_get_environment_type()` returns `'development'` or `'staging'`.

```php
$updater->enableDebugMode();         // force on
$updater->enableDebugMode(false);    // force off
```

---

### `setMuPluginFile(string $muPluginFile): static` *(plugin only)*

Only needed for MU-plugins in a subdirectory (e.g. `mu-plugins/my-plugin/my-plugin.php`). Flat MU-plugins (files placed directly in `wp-content/mu-plugins/`) are detected automatically. See [MU-plugins](advanced.md#mu-plugins).

```php
$updater->setMuPluginFile('my-mu-plugin.php');
```

---

### `removeHooks(): void`

Removes all WordPress hooks registered by this updater instance. Called automatically on plugin uninstall. Call manually if you need to tear down the updater at runtime.

---

## Reading state

### `checkForUpdates(): ?Update`

Forces an immediate check against the GitHub API. Returns an `Update` if a newer version is available, `null` when the installed version cannot be read. When a concurrent check is already in progress, returns the last-known stored update immediately without making an API call.

```php
$update = $updater->checkForUpdates();
if ($update) {
    error_log('New version: ' . $update->version);
}
```

---

### `getUpdate(): ?Update`

Returns the last known update from stored state without making any API call. Returns `null` when up to date or no check has run yet.

---

### `getLastCheck(): int`

Returns the Unix timestamp of the last completed update check, or `0` if no check has run yet.

```php
$ts = $updater->getLastCheck();
if ($ts > 0) {
    echo 'Last checked: ' . gmdate('Y-m-d H:i:s', $ts) . ' UTC';
}
```

---

### `getStatus(): array`

Returns a flat associative array of the current state, suitable for display.

```php
$status = $updater->getStatus();
// [
//   'slug'                => 'my-plugin',
//   'type'                => 'plugin',
//   'installed_version'   => '1.3.0',
//   'last_check'          => '2026-06-09 10:34:21 UTC',
//   'available_version'   => '1.4.2',
//   'update_available'    => 'yes',
//   'cooldown_active'     => 'no',
//   'requirement_blocked' => 'no',
// ]
```

`update_available` is `'yes'` only when the cooldown has passed and all requirements are met. `cooldown_active` is `'yes'` when an update exists but is suppressed by the cooldown window. `requirement_blocked` is `'yes'` when an update exists and the cooldown has passed but the site does not meet the `requires` or `requires_php` values from `hu.json`.

---

### `getLastRequestApiErrors(): array`

Returns API errors from the most recent `checkForUpdates()` call. Each entry has keys `error` (`WP_Error`), `httpResponse`, and `url`.

```php
foreach ($updater->getLastRequestApiErrors() as $item) {
    error_log($item['error']->get_error_message() . ' [' . $item['url'] . ']');
}
```

---

### `getEntityTitle(): string`

Returns the human-readable display name from the plugin/theme file header. Falls back to the slug when no name header is present.

---

### `getUniqueName(string $baseTag): string`

Returns `hu_{baseTag}-{slug}`. Used to build scoped filter names.

```php
$updater->getUniqueName('request_info_result');
// → "hu_request_info_result-my-plugin"
```

---

### `resetState(): void`

Clears the stored check state (last-check timestamp, checked version, stored update) and persists the result.
