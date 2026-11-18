<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Content;

/**
 * What the pipeline needs from one post. Raw values, before cleaning.
 */
final class PostData
{
    /**
     * @param list<string>          $categories   Category names.
     * @param list<int>             $categoryIds
     * @param list<string>          $tags         Tag names.
     * @param list<int>             $tagIds
     * @param array<string, mixed>  $meta         Allowlisted meta values as stored.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $status,
        public readonly bool $hasPassword,
        public readonly string $localDate,
        public readonly string $isoDate,
        public readonly string $isoModified,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $content,
        public readonly string $permalink,
        public readonly array $categories,
        public readonly array $categoryIds,
        public readonly array $tags,
        public readonly array $tagIds,
        public readonly int $authorId,
        public readonly string $authorName,
        public readonly string $imageAlt,
        public readonly array $meta,
        public readonly bool $optedOut,
        public readonly bool $categoryOptedOut,
    ) {
    }
}
