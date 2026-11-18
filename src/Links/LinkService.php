<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Links;

use IndianOpinion\FinetuneExport\ExportFailed;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Jobs\JobRepository;
use IndianOpinion\FinetuneExport\PermissionDenied;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Support\Clock;
use IndianOpinion\FinetuneExport\Support\Logger;

/**
 * Issues and revokes download links (cases 69, 70, 72 and 78).
 */
final class LinkService
{
    public function __construct(
        private readonly LinkSigner $signer,
        private readonly JobRepository $jobs,
        private readonly Logger $logger,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{url: string, expires: int}
     */
    public function issue(int $jobId, int $userId, int $ttl, string $kind = Job::FILE_TRAIN, bool $email = false): array
    {
        if (!user_can($userId, Plugin::CAP_RUN)) {
            throw new PermissionDenied('That user cannot download exports.');
        }
        $job = $this->jobs->find($jobId);
        if ($job === null || !$job->downloadable($kind)) {
            throw new ExportFailed('not_downloadable', 'Job ' . $jobId . ' has no finished ' . $kind . ' file to download.');
        }
        $issued = $this->signer->issue($jobId, $userId, $ttl, $kind);
        $job->links[$issued['nonce']] = ['user' => $userId, 'expires' => $issued['expires'], 'kind' => $kind];
        // Keep the link table small: forget links that expired a day ago.
        foreach ($job->links as $nonce => $link) {
            if ((int) $link['expires'] < $this->clock->now() - 86400) {
                unset($job->links[$nonce]);
            }
        }
        $job->addHistory('link_issued', $this->clock->now(), ['user' => $userId, 'file' => $kind, 'expires' => $issued['expires']]);
        $this->jobs->save($job);
        $url = self::url($issued['token']);
        $this->logger->info('link issued', ['job' => $jobId, 'user' => $userId, 'token' => Logger::tokenPrefix($issued['token'])]);
        if ($email) {
            $this->email($userId, $url, $issued['expires']);
        }
        return ['url' => $url, 'expires' => $issued['expires']];
    }

    public function revokeAll(int $jobId, int $userId): Job
    {
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            throw new ExportFailed('unknown_job', 'No job with id ' . $jobId . '.');
        }
        $job->links = [];
        $job->addHistory('links_revoked', $this->clock->now(), ['user' => $userId]);
        $this->jobs->save($job);
        return $job;
    }

    /**
     * The link never ends in a file extension (case 70).
     */
    public static function url(string $token): string
    {
        return rest_url(Plugin::REST_NAMESPACE . '/download/' . $token);
    }

    /**
     * Sends the link to the requesting user's own address only (case 78).
     */
    private function email(int $userId, string $url, int $expires): void
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !is_email($user->user_email)) {
            throw new ExportFailed('no_email', 'Your account has no valid email address.');
        }
        $sent = wp_mail(
            $user->user_email,
            __('Your fine-tune export download link', 'finetune-export'),
            sprintf(
                /* translators: 1: download URL, 2: expiry time in UTC. */
                __("Download your export while signed in:\n\n%1\$s\n\nThe link expires at %2\$s UTC and works only for your account.", 'finetune-export'),
                $url,
                gmdate('Y-m-d H:i', $expires)
            )
        );
        if (!$sent) {
            throw new ExportFailed('mail_failed', 'The email could not be sent.');
        }
    }
}
