<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Content;

use IndianOpinion\FinetuneExport\CleanOptions;
use IndianOpinion\FinetuneExport\Profile;

/**
 * Where posts come from. The WordPress implementation reads through core
 * functions only (PRD non-goal 7).
 */
interface ContentSource
{
    /**
     * Checks the parts of a profile that depend on the site, such as public
     * post types. Throws InvalidProfile.
     */
    public function validate(Profile $profile): void;

    /**
     * Candidate post ids after $afterId, ascending (case 9).
     *
     * @return list<int>
     */
    public function nextIds(Profile $profile, int $afterId, int $limit): array;

    /**
     * Number of candidate posts, for progress.
     */
    public function total(Profile $profile): int;

    /**
     * The post, or null when it is gone or may never be selected (case 5)
     * or does not match the profile's status and password rules.
     */
    public function post(int $id, Profile $profile): ?PostData;

    /**
     * The post body before cleaning: content filters and dynamic block
     * rendering applied only when the profile asks for them.
     */
    public function renderBody(PostData $post, Profile $profile): string;

    public function cleanOptions(Profile $profile): CleanOptions;

    /**
     * Names of site users for case 43a, display, first, last, nickname and login.
     *
     * @return list<string>
     */
    public function userNames(): array;
}
