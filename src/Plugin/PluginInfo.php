<?php

namespace WpHubUpdater\Plugin;

use WpHubUpdater\AbstractInfo;

/**
 * Full plugin metadata shown in the "View details" popup.
 */
final class PluginInfo extends AbstractInfo
{
    /** @var array<string, string> */
    public array $icons = [];
    /** @var array<string, string> */
    public array $banners = [];
    /** WordPress-relative plugin file path, used to populate the update transient. */
    public ?string $filename = null;

    /** @internal */
    public function toWpFormat(): object
    {
        $info = $this->buildBaseWpObject();

        if ($this->banners !== []) {
            $info->banners = array_intersect_key($this->banners, ["high" => true, "low" => true]);
        }

        if ($this->icons !== []) {
            $icons = array_intersect_key($this->icons, [
                "svg" => true, "1x" => true, "2x" => true, "default" => true,
            ]);
            if ($icons !== []) {
                $icons["default"] ??= current($icons);
                $info->icons = $icons;
            }
        }

        return $info;
    }
}
