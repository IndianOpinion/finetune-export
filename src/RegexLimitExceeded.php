<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport;

/**
 * A regular expression hit the PCRE backtrack or recursion limit. The record
 * being redacted is skipped, never written unredacted.
 */
final class RegexLimitExceeded extends FinetuneExportException
{
}
