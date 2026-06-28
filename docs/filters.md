# WordPress filters & actions

All filter tags are scoped per instance: `hu_{tag}-{slug}`. Use `$updater->getUniqueName('tag')` to get the correct name.

The filter names listed here are the stable public API. Filters not listed here are internal and may change between versions.

---

## `hu_request_info_result-{slug}`

Fired after the GitHub API populates the info shown in the "View details" popup. Receives the populated `PluginInfo` or `ThemeInfo` (or `null` on failure). Return a modified info object or `null` to suppress the popup.

```php
use MyPlugin\WpHubUpdater\Plugin\PluginInfo;

add_filter($updater->getUniqueName('request_info_result'), function (?PluginInfo $info): ?PluginInfo {
    if ($info) {
        $info->sections['changelog'] = '<p>See GitHub releases for changelog.</p>';
    }
    return $info;
});
```

---

## `hu_request_update_result-{slug}`

Fired after the GitHub API is called during a check, before the result is written to state. Always receives a non-null `Update`. Return `null` or any non-`Update` value to suppress the update.

```php
add_filter($updater->getUniqueName('request_update_result'), function (Update $update): ?Update {
    return $update;
});
```

---

## `hu_pre_inject_update-{slug}`

Fired just before an update is written into the WP update transient. Last chance to modify the `Update` object.

```php
add_filter($updater->getUniqueName('pre_inject_update'), function (Update $update): Update {
    $update->download_url = 'https://example.com/my-plugin.zip';
    return $update;
});
```

---

## `hu_check_now-{slug}`

Controls whether the scheduler should run a check now. Receives `bool $shouldCheck`, `int $lastCheck` (timestamp), and `int $period` (hours).

```php
add_filter($updater->getUniqueName('check_now'), function (bool $should, int $lastCheck, int $period): bool {
    return (time() - $lastCheck) > DAY_IN_SECONDS;
}, 10, 3);
```

---

## `hu_first_check_time-{slug}`

Controls the timestamp of the first scheduled cron event. Defaults to a random offset within the first `checkPeriod` window to spread load across installations.

```php
add_filter($updater->getUniqueName('first_check_time'), function (int $timestamp): int {
    return time();   // check on the very next cron run
});
```

---

## `hu_vcs_update_detection_strategies-{slug}`

Lets you reorder or remove entries from the ordered strategy map passed to `GitHubClient::chooseReference()`.

```php
use MyPlugin\WpHubUpdater\Plugin\PluginUpdater;

add_filter($updater->getUniqueName('vcs_update_detection_strategies'), function (array $strategies): array {
    unset($strategies[PluginUpdater::STRATEGY_LATEST_TAG]);   // releases only, skip tags
    return $strategies;
});
```

Available keys: `PluginUpdater::STRATEGY_LATEST_RELEASE`, `::STRATEGY_LATEST_TAG`, `::STRATEGY_BRANCH`. The same constants are available on `ThemeUpdater`.

---

## `hu_remove_from_default_update_checks-{slug}`

By default the entity is excluded from the WP.org update payload. Return `false` to include it.

```php
add_filter($updater->getUniqueName('remove_from_default_update_checks'), '__return_false');
```

> **Tip (plugins only):** Adding `Update URI: https://github.com/your-org/your-repo` to your plugin header lets WordPress 5.8+ handle the exclusion natively — the library skips registering this filter entirely.

---

## `hu_retain_fields-{slug}`

Controls which of the built-in fields are copied from the info object into an `Update` record during `checkForUpdates()`. Use it to remove fields you do not want carried over. This filter fires only during update detection, not when stored state is reloaded.

```php
add_filter('hu_retain_fields-my-plugin', function (array $fields): array {
    return array_diff($fields, ['icons']);   // don't copy icons to the Update record
});
```

The default field list is: `slug`, `version`, `download_url`, `homepage`, `icons`, `filename`, `last_updated`, `requires`, `requires_php`.

---

## `hu_api_error` *(action)*

Fired whenever the GitHub API returns an error. Receives `WP_Error $error`, the raw HTTP response, the request URL, and the entity slug.

```php
add_action('hu_api_error', function (WP_Error $error, mixed $response, ?string $url, ?string $slug): void {
    error_log('[' . $slug . '] ' . $error->get_error_message());
}, 10, 4);
```

**Error codes:**

| Code | Meaning |
|---|---|
| `hu-github-auth-error` | 401 — token missing, expired, or wrong scope |
| `hu-github-http-error` | Non-200 response not covered by other codes |
| `hu-github-rate-limited` | 429 or 403 with `x-ratelimit-remaining: 0`. `WP_Error` data includes `reset_time` (Unix timestamp or `null`). Follow-on requests are automatically blocked until reset |
| `hu-github-api-version-expired` | 410 — the versioned API header is no longer supported. The request is retried once without it; update the library when you see this |
| `hu-no-update-source` | All detection strategies returned null |
| `hu-no-plugin-version` | Source found but carried no version number |
