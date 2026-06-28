<?php

namespace WpHubUpdater;

use stdClass;

/**
 * Update record injected into WordPress's update transient when a newer version is available.
 */
final class Update
{
    public ?string $slug = null;
    public ?string $version = null;
    public ?string $download_url = null;
    public ?string $homepage = null;
    /** @var array<string, string> */
    public array $icons = [];
    public ?string $filename = null;
    public ?string $last_updated = null;
    public ?string $requires = null;
    public ?string $requires_php = null;
    public ?string $tested = null;

    /** @var list<string> */
    private static array $fieldNames = [
        "slug",
        "version",
        "download_url",
        "homepage",
        "icons",
        "filename",
        "last_updated",
        "requires",
        "requires_php",
        "tested",
    ];

    /**
     * Builds an Update from any object, applying the hu_retain_fields filter to
     * control which fields are copied. Only fires during update detection,
     * not when rehydrating stored state.
     */
    public static function fromObject(object $object): static
    {
        $update = new self();
        $fields = apply_filters(
            "hu_retain_fields-" . ($object->slug ?? ""),
            self::$fieldNames,
        );
        foreach ($fields as $field) {
            if (property_exists($object, $field)) {
                $update->$field = $object->$field;
            }
        }
        return $update;
    }

    /**
     * Rebuilds an Update from a stored stdClass without triggering any
     * filters. Used when rehydrating persisted state on page load, before
     * user filter callbacks have been registered.
     *
     * @internal
     */
    public static function restore(object $object): static
    {
        $update = new self();
        foreach (self::$fieldNames as $field) {
            if (property_exists($object, $field)) {
                $update->$field = $object->$field;
            }
        }
        return $update;
    }

    /**
     * Serialises the update to a plain object suitable for storage in a WP
     * site option.
     *
     * @internal
     */
    public function toStdClass(): stdClass
    {
        $obj = new stdClass();
        foreach (self::$fieldNames as $field) {
            $obj->$field = $this->$field;
        }
        return $obj;
    }

    /**
     * Converts to the object shape WordPress expects inside the update
     * transient response array.
     *
     * @internal
     */
    public function toWpFormat(): stdClass
    {
        $update = new stdClass();
        $update->slug = $this->slug;
        $update->new_version = $this->version;
        $update->package = $this->download_url;
        $update->url = $this->homepage;
        $update->plugin       = $this->filename;
        $update->requires     = $this->requires;
        $update->requires_php = $this->requires_php;
        $update->tested       = $this->tested;

        if ($this->icons !== []) {
            $icons = array_intersect_key($this->icons, [
                "svg" => true,
                "1x" => true,
                "2x" => true,
                "default" => true,
            ]);
            if ($icons !== []) {
                $icons["default"] ??= current($icons);
                $update->icons = $icons;
            }
        }

        return $update;
    }
}
