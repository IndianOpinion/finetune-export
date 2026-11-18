<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Rest;

use IndianOpinion\FinetuneExport\FinetuneExportException;
use IndianOpinion\FinetuneExport\Jobs\Job;
use IndianOpinion\FinetuneExport\Plugin;
use IndianOpinion\FinetuneExport\Profile;
use IndianOpinion\FinetuneExport\Profiles;
use IndianOpinion\FinetuneExport\Estimate\Stats;

/**
 * REST routes (PRD section 5.3). Preview and job routes use the normal REST
 * cookie and nonce check; the download route authenticates itself (case 73).
 */
final class RestController
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        register_rest_route(Plugin::REST_NAMESPACE, '/download/(?P<token>[A-Za-z0-9_-]{1,200})', [
            'methods' => ['GET', 'HEAD'],
            'callback' => [$this, 'download'],
            // The handler answers 401 and 404 itself, in the order of case 74.
            'permission_callback' => '__return_true',
            'args' => ['token' => ['type' => 'string', 'required' => true]],
        ]);
        register_rest_route(Plugin::REST_NAMESPACE, '/preview', [
            'methods' => 'POST',
            'callback' => [$this, 'preview'],
            'permission_callback' => static fn (): bool => current_user_can(Plugin::CAP_RUN),
        ]);
        register_rest_route(Plugin::REST_NAMESPACE, '/jobs', [
            'methods' => 'GET',
            'callback' => [$this, 'jobs'],
            'permission_callback' => static fn (): bool => current_user_can(Plugin::CAP_RUN),
        ]);
        register_rest_route(Plugin::REST_NAMESPACE, '/jobs/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'job'],
            'permission_callback' => static fn (): bool => current_user_can(Plugin::CAP_RUN),
        ]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function download(\WP_REST_Request $request, ?ResponseSink $sink = null): void
    {
        $userId = self::cookieUser();
        $origin = $request->get_header('origin');
        $handler = $this->plugin->downloadHandler();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : null;
        $handler->handle(
            new DownloadRequest((string) $request->get_param('token'), $request->get_method(), $origin, $ip),
            $userId,
            $sink ?? new PhpResponseSink()
        );
    }

    /**
     * The signed-in user from the logged-in cookie, including its session
     * token, when the REST layer reports none because there is no REST
     * nonce (case 73, lesson 5).
     */
    public static function cookieUser(): int
    {
        $current = get_current_user_id();
        if ($current > 0) {
            return $current;
        }
        $user = wp_validate_auth_cookie('', 'logged_in');
        return is_int($user) && $user > 0 ? $user : 0;
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function preview(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();
        $data = is_array($body) && isset($body['profile']) && is_array($body['profile']) ? $body['profile'] : (is_array($body) ? $body : []);
        $limit = isset($body['limit']) ? (int) $body['limit'] : null;
        try {
            $profile = Profile::fromArray($data);
            Profiles::assertAllowed($profile, current_user_can(Plugin::CAP_SENSITIVE));
            $result = $this->plugin->exporter()->preview($profile, $this->plugin->settings()->previewSize($limit));
        } catch (FinetuneExportException $e) {
            return new \WP_Error('finetune_export_invalid', $e->getMessage(), ['status' => 400]);
        }
        $records = [];
        foreach ($result->records as $record) {
            $records[] = [
                'post_id' => $record['post_id'],
                'line' => $record['line'],
                'estimated_tokens' => $record['tokens'],
                'redactions' => $record['counts'],
            ];
        }
        return new \WP_REST_Response([
            'records' => $records,
            'skipped' => $result->skipped,
            'notes' => $result->notes,
            'estimate_note' => 'Token counts are estimates and can be off by 25 percent or more, most for non-Latin scripts.',
        ]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function jobs(\WP_REST_Request $request): \WP_REST_Response
    {
        $out = [];
        foreach ($this->plugin->jobs()->all(100) as $job) {
            $out[] = self::describe($job);
        }
        return new \WP_REST_Response($out);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function job(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $job = $this->plugin->jobs()->find((int) $request->get_param('id'));
        if ($job === null) {
            return new \WP_Error('finetune_export_not_found', 'No such job.', ['status' => 404]);
        }
        return new \WP_REST_Response(self::describe($job));
    }

    /**
     * Status, counters and statistics of a job. Never file contents or links.
     *
     * @return array<string, mixed>
     */
    public static function describe(Job $job): array
    {
        $files = [];
        foreach ($job->files as $kind => $file) {
            $files[$kind] = array_intersect_key($file, array_flip(['state', 'size', 'removed_at', 'missing_since', 'removal_failed_at']));
        }
        return [
            'id' => $job->id,
            'kind' => $job->kind,
            'state' => $job->state,
            'profile' => $job->profile['id'] ?? '',
            'created_by' => $job->createdBy,
            'created_at' => $job->createdAt,
            'ended_at' => $job->endedAt,
            'progress' => ['processed' => $job->counter('processed'), 'total' => $job->total],
            'counters' => $job->counters,
            'skipped' => $job->skipped,
            'redaction' => $job->redaction,
            'top_redacted_posts' => array_keys($job->topRedacted),
            'estimated_tokens' => Stats::fromArray($job->stats)->summary(),
            'notes' => $job->notes,
            'degraded' => $job->degraded(),
            'error' => $job->error,
            'files' => $files,
            'downloads' => $job->downloads,
        ];
    }
}
