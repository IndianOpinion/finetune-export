<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Storage;

/**
 * An accepted export directory.
 */
final class ResolvedDirectory
{
    /**
     * @param string $path          Absolute path files are written to (site subdirectory included).
     * @param string $base          The candidate before the multisite subdirectory.
     * @param string $source        constant, filter or default.
     * @param bool   $insideWebRoot True only in the acknowledged case (PRD case 62).
     */
    public function __construct(
        public readonly string $path,
        public readonly string $base,
        public readonly string $source,
        public readonly bool $insideWebRoot,
    ) {
    }
}
