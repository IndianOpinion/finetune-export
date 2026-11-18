<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * Options for Cleaner::clean(). Immutable.
 */
final class CleanOptions
{
    /**
     * @param list<string>      $dynamicBlocks Block names whose saved markup is dropped (PRD case 20).
     * @param list<string>|null $shortcodes    Shortcode tags to strip; null strips any lowercase tag that
     *                                         looks like a shortcode, which also catches tags left behind
     *                                         by plugins that are no longer active.
     */
    public function __construct(
        public readonly bool $keepImageAlt = false,
        public readonly array $dynamicBlocks = [],
        public readonly ?array $shortcodes = null,
        public readonly bool $blocksRendered = false,
    ) {
    }
}
