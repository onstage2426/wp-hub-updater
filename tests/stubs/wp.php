<?php

/**
 * Minimal WordPress stubs for unit tests.
 *
 * apply_filters() is a controlled passthrough: tests may register overrides via
 * $GLOBALS['_wp_filter_overrides'] and inspect calls via $GLOBALS['_wp_applied_filters'].
 *
 * get_site_option / update_site_option / update_option / delete_site_option all
 * operate on $GLOBALS['_wp_test_options'] so tests can pre-seed state and read
 * back persisted values without a real database.
 *
 * Call wp_test_reset() in setUp() to start each test with a clean slate.
 */

// ---------------------------------------------------------------------------
// Global state used by the controllable stubs
// ---------------------------------------------------------------------------

$GLOBALS['_wp_test_options']       = [];
$GLOBALS['_wp_applied_filters']    = [];
$GLOBALS['_wp_filter_overrides']   = [];

function wp_test_reset(): void
{
    $GLOBALS['_wp_test_options']     = [];
    $GLOBALS['_wp_applied_filters']  = [];
    $GLOBALS['_wp_filter_overrides'] = [];
}

// ---------------------------------------------------------------------------
// Core classes
// ---------------------------------------------------------------------------

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public readonly string $code    = '',
            public readonly string $message = '',
            public readonly mixed  $data    = '',
        ) {}

        public function get_error_code(): string  { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): mixed    { return $this->data; }
    }
}

// ---------------------------------------------------------------------------
// Type checks
// ---------------------------------------------------------------------------

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

// ---------------------------------------------------------------------------
// URL helpers
// ---------------------------------------------------------------------------

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function add_query_arg(array|string $args, string $url = ''): string
{
    if (!is_array($args)) {
        return $url;
    }
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query($args);
}

// ---------------------------------------------------------------------------
// Filters / actions
// ---------------------------------------------------------------------------

function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    $GLOBALS['_wp_applied_filters'][] = ['tag' => $tag, 'value' => $value, 'args' => $args];
    if (isset($GLOBALS['_wp_filter_overrides'][$tag]) && is_callable($GLOBALS['_wp_filter_overrides'][$tag])) {
        return ($GLOBALS['_wp_filter_overrides'][$tag])($value, ...$args);
    }
    return $value;
}

function do_action(string $tag, mixed ...$args): void {}

function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return true;
}

function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    return true;
}

function remove_filter(string $tag, callable $callback, int $priority = 10): bool
{
    return true;
}

function remove_action(string $tag, callable $callback, int $priority = 10): bool
{
    return true;
}

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

function get_site_option(string $option, mixed $default = false): mixed
{
    return array_key_exists($option, $GLOBALS['_wp_test_options'])
        ? $GLOBALS['_wp_test_options'][$option]
        : $default;
}

function update_site_option(string $option, mixed $value): bool
{
    $GLOBALS['_wp_test_options'][$option] = $value;
    return true;
}

function update_option(string $option, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['_wp_test_options'][$option] = $value;
    return true;
}

function delete_site_option(string $option): bool
{
    unset($GLOBALS['_wp_test_options'][$option]);
    return true;
}

// ---------------------------------------------------------------------------
// Multisite / environment
// ---------------------------------------------------------------------------

function is_multisite(): bool
{
    return false;
}

function wp_doing_cron(): bool
{
    return $GLOBALS['_wp_doing_cron'] ?? false;
}

function current_filter(): string
{
    return $GLOBALS['_wp_current_filter'] ?? '';
}

// ---------------------------------------------------------------------------
// Cron
// ---------------------------------------------------------------------------

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false): bool|WP_Error
{
    return true;
}

function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false): int|false|WP_Error
{
    return 0;
}

function wp_rand(int $min = 0, int $max = 0): int
{
    return random_int($min, max($min, $max));
}

// ---------------------------------------------------------------------------
// HTTP API  (tests must not make real network calls — these are safe stubs)
// ---------------------------------------------------------------------------

function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    return new WP_Error('stub', 'wp_remote_get is not available in unit tests');
}

function wp_remote_retrieve_response_code(array|WP_Error $response): int|string|false
{
    if (is_wp_error($response)) {
        return false;
    }
    return $response['response']['code'] ?? false;
}

function wp_remote_retrieve_body(array|WP_Error $response): string
{
    if (is_wp_error($response)) {
        return '';
    }
    return $response['body'] ?? '';
}

function wp_remote_retrieve_header(array|WP_Error $response, string $header): array|string
{
    if (is_wp_error($response)) {
        return '';
    }
    $headers = $response['headers'] ?? [];
    return $headers[strtolower($header)] ?? '';
}

// ---------------------------------------------------------------------------
// Escaping
// ---------------------------------------------------------------------------

function esc_html(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url(string $url, ?array $protocols = null, string $_context = 'display'): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
