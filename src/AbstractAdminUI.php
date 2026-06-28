<?php

namespace WpHubUpdater;

/**
 * Shared manual-check flow and result notices for plugin and theme admin UIs.
 *
 * @internal
 */
abstract class AbstractAdminUI
{
    private readonly string $manualCheckErrorTransient;
    protected \Closure $cbOnAdminInit;
    protected \Closure $cbDisplayManualCheckResult;

    public function __construct(protected AbstractUpdater $updateChecker)
    {
        $this->manualCheckErrorTransient  = $updateChecker->getUniqueName("manual_check_errors");
        $this->cbOnAdminInit              = $this->onAdminInit(...);
        $this->cbDisplayManualCheckResult = $this->displayManualCheckResult(...);
        add_action("admin_init", $this->cbOnAdminInit);
    }

    /**
     * Registers type-specific hooks. Called on admin_init. Concrete classes
     * should check capability, call handleManualCheck(), and register notices.
     */
    abstract protected function onAdminInit(): void;

    /** Returns the admin URL to redirect to after a manual check (plugins.php or themes.php). */
    abstract protected function getAdminPageUrl(): string;

    /** Removes all hooks registered by this instance. */
    abstract public function removeHooks(): void;

    // -------------------------------------------------------------------------
    // Shared: manual check handler
    // -------------------------------------------------------------------------

    /**
     * Processes a manual check request: validates the nonce, runs the check,
     * stores any errors, and redirects back with a status parameter.
     */
    protected function handleManualCheck(): void
    {
        $shouldCheck =
            isset($_GET["hu_check_for_updates"], $_GET["hu_slug"]) &&
            $_GET["hu_slug"] == $this->updateChecker->slug &&
            check_admin_referer("hu_check_for_updates");

        if (!$shouldCheck) {
            return;
        }

        $update               = $this->updateChecker->checkForUpdates();
        $status               = $update instanceof Update ? "update_available" : "no_update";
        $lastRequestApiErrors = $this->updateChecker->getLastRequestApiErrors();

        if (!$update instanceof Update && $lastRequestApiErrors !== []) {
            $status = "error";
            set_site_transient($this->manualCheckErrorTransient, $lastRequestApiErrors, 60);
        }

        wp_safe_redirect(add_query_arg(
            ["hu_update_check_result" => $status, "hu_slug" => $this->updateChecker->slug],
            $this->getAdminPageUrl(),
        ));
        exit();
    }

    // -------------------------------------------------------------------------
    // Shared: result notice
    // -------------------------------------------------------------------------

    /**
     * Renders an admin notice showing the outcome of the last manual check.
     * Only displayed when the current page carries the matching query parameters.
     */
    protected function displayManualCheckResult(): void
    {
        if (
            !isset($_GET["hu_update_check_result"], $_GET["hu_slug"]) ||
            $_GET["hu_slug"] != $this->updateChecker->slug
        ) {
            return;
        }

        $status     = sanitize_key($_GET["hu_update_check_result"]);
        $title      = $this->updateChecker->getEntityTitle();
        $noticeType = "success";
        $details    = "";

        $message = match ($status) {
            "no_update" => sprintf(
                _x("The %s is up to date.", "entity name", $this->updateChecker->getTextDomain()),
                $title,
            ),
            "update_available" => sprintf(
                _x("A new version of %s is available.", "entity name", $this->updateChecker->getTextDomain()),
                $title,
            ),
            "error" => (function () use ($title, &$noticeType, &$details): string {
                $noticeType = "error";
                $details    = $this->formatManualCheckErrors(
                    get_site_transient($this->manualCheckErrorTransient),
                );
                delete_site_transient($this->manualCheckErrorTransient);
                return sprintf(
                    _x("Could not determine if updates are available for %s.", "entity name", $this->updateChecker->getTextDomain()),
                    $title,
                );
            })(),
            default => (function () use ($status, &$noticeType): string {
                $noticeType = "error";
                return sprintf(__('Unknown update checker status "%s"', $this->updateChecker->getTextDomain()), $status);
            })(),
        };

        $message = apply_filters(
            $this->updateChecker->getUniqueName("manual_check_message"),
            $message,
            $status,
        );

        wp_admin_notice(
            "<p>" . wp_kses_post($message) . "</p>{$details}",
            ["type" => $noticeType, "dismissible" => true, "paragraph_wrap" => false],
        );
    }

    private function formatManualCheckErrors(mixed $errors): string
    {
        if (empty($errors)) {
            return "";
        }

        $showAsList   = count($errors) > 1;
        $formatString = $showAsList
            ? "<li>%1\$s <code>%2\$s</code></li>"
            : "<p>%1\$s <code>%2\$s</code></p>";
        $output = $showAsList ? "<ol>" : "";

        foreach ($errors as $item) {
            /** @var \WP_Error $wpError */
            $wpError = $item["error"];
            $output .= sprintf(
                $formatString,
                esc_html($wpError->get_error_message()),
                esc_html((string) $wpError->get_error_code()),
            );
        }

        return $output . ($showAsList ? "</ol>" : "");
    }
}
