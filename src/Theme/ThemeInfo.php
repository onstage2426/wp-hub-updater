<?php

namespace WpHubUpdater\Theme;

use WpHubUpdater\AbstractInfo;

/**
 * Full theme metadata shown in the "View details" popup.
 */
final class ThemeInfo extends AbstractInfo
{
    /** URL of the theme screenshot shown in the info popup, or null when unavailable. */
    public ?string $screenshot_url = null;

    /** @internal */
    public function toWpFormat(): object
    {
        $info = $this->buildBaseWpObject();

        if ($this->screenshot_url !== null) {
            $info->screenshot_url = $this->screenshot_url;
        }

        return $info;
    }
}
