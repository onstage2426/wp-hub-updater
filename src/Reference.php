<?php

declare(strict_types=1);

namespace WpHubUpdater;

/**
 * Immutable value object describing a resolved GitHub reference (release, tag, or branch HEAD).
 * Produced by GitHubClient and consumed internally by AbstractUpdater.
 *
 * The hu_vcs_update_detection_strategies filter is intended for removing or reordering the
 * three built-in strategies (latest_release, latest_tag, branch). Constructing Reference
 * objects from user code is not a supported pattern.
 *
 * @internal
 */
final readonly class Reference
{
    public function __construct(
        public string  $name,
        public string  $downloadUrl,
        public ?string $version,
        public ?string $updated,
        public ?string $changelog,
        public ?int    $downloadCount,
    ) {}
}
