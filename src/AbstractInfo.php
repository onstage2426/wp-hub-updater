<?php

namespace WpHubUpdater;

use stdClass;

/**
 * Shared DTO fields and author formatting for plugin and theme info popups.
 *
 * @internal
 */
abstract class AbstractInfo
{
    public ?string $name = null;
    public ?string $slug = null;
    public ?string $version = null;
    public ?string $homepage = null;
    /** @var array<string, string> */
    public array $sections = [];
    public ?string $download_url = null;
    public ?string $author = null;
    public ?string $author_homepage = null;
    public ?int $downloaded = null;
    public ?string $last_updated = null;
    public ?string $requires = null;
    public ?string $tested = null;
    public ?string $requires_php = null;
    /** @var array<string, array{display_name: string, profile: string, avatar: string}>|null */
    public ?array $contributors = null;

    /** Converts this info object to the shape WordPress expects from the info API. */
    abstract public function toWpFormat(): object;

    /**
     * Builds a stdClass pre-populated with the fields common to both plugin
     * and theme API responses. Subclass toWpFormat() calls this then adds
     * type-specific fields.
     */
    protected function buildBaseWpObject(): stdClass
    {
        $info = new stdClass();

        foreach (["name", "slug", "version", "downloaded", "homepage", "last_updated", "requires", "tested", "requires_php"] as $field) {
            $info->$field = $this->$field ?? null;
        }

        $info->download_link = $this->download_url;
        $info->author        = $this->getFormattedAuthor();
        $info->sections      = array_merge(["description" => ""], $this->sections);

        if ($this->contributors !== null) {
            $info->contributors = $this->contributors;
        }

        return $info;
    }

    /** Returns the author as an HTML anchor when a homepage URL is set, or as plain text. */
    protected function getFormattedAuthor(): ?string
    {
        if (!in_array($this->author_homepage, [null, "", "0"], true)) {
            return sprintf('<a href="%s">%s</a>', esc_url($this->author_homepage), esc_html($this->author));
        }
        return $this->author !== null ? esc_html($this->author) : null;
    }
}
