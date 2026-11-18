<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Storage;

/**
 * The facts directory resolution needs, gathered from WordPress by the
 * plugin and passed in, so Storage is testable with temporary directories.
 */
final class StorageEnvironment
{
    /**
     * @param string      $abspath          ABSPATH.
     * @param string      $contentDir       WP_CONTENT_DIR.
     * @param string      $uploadsDir       Uploads base directory.
     * @param string|null $constantDir      FINETUNE_EXPORT_DIR, when defined.
     * @param string|null $filterDir        Value of the directory filter.
     * @param string|null $acknowledgedPath Path the operator acknowledged as inside the web root.
     * @param int         $siteId           Blog id on multisite, 0 on a single site.
     * @param list<string>  $webRoots         Other directories a web server serves: the site's home
     *                                        directory when WordPress lives in a subdirectory, and the
     *                                        server's document root when PHP reports one.
     */
    public function __construct(
        public readonly string $abspath,
        public readonly string $contentDir,
        public readonly string $uploadsDir,
        public readonly ?string $constantDir = null,
        public readonly ?string $filterDir = null,
        public readonly ?string $acknowledgedPath = null,
        public readonly int $siteId = 0,
        public readonly array $webRoots = [],
    ) {
    }

    public function withAcknowledgedPath(?string $path): self
    {
        return new self(
            $this->abspath,
            $this->contentDir,
            $this->uploadsDir,
            $this->constantDir,
            $this->filterDir,
            $path,
            $this->siteId,
            $this->webRoots
        );
    }
}
