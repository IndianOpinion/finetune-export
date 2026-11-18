<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * No acceptable protected directory exists, or the chosen one was refused.
 * The plugin fails closed: no job runs and no download is served.
 */
final class UnsafeDirectory extends FinetuneExportException
{
}
