<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Jobs;

use IndianOpinion\FinetuneExport\FinetuneExportException;

/**
 * Jobs in a custom table, plus a second small table for the duplicate
 * register (see ARCHITECTURE.md). Every query goes through $wpdb->prepare
 * or the $wpdb insert, update and delete helpers.
 */
final class WpdbJobRepository implements JobRepository
{
    public const SCHEMA_VERSION = '1';

    private string $jobs;
    private string $hashes;

    public function __construct(private readonly \wpdb $db)
    {
        $this->jobs = $db->prefix . 'finetune_export_jobs';
        $this->hashes = $db->prefix . 'finetune_export_hashes';
    }

    public function jobsTable(): string
    {
        return $this->jobs;
    }

    public function hashesTable(): string
    {
        return $this->hashes;
    }

    /**
     * CREATE TABLE statements for dbDelta.
     *
     * @return list<string>
     */
    public function schema(): array
    {
        $charset = $this->db->get_charset_collate();
        return [
            "CREATE TABLE {$this->jobs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL DEFAULT 'export',
  state varchar(20) NOT NULL DEFAULT 'queued',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY state (state)
) {$charset};",
            "CREATE TABLE {$this->hashes} (
  job_id bigint(20) unsigned NOT NULL,
  hash char(40) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (job_id,hash)
) {$charset};",
        ];
    }

    public function insert(Job $job): Job
    {
        $ok = $this->db->insert($this->jobs, $this->row($job), ['%s', '%s', '%d', '%d', '%d', '%s']);
        if ($ok === false) {
            throw new FinetuneExportException('Could not store the job: ' . $this->db->last_error);
        }
        $job->id = (int) $this->db->insert_id;
        $this->save($job);
        return $job;
    }

    public function save(Job $job): void
    {
        $ok = $this->db->update(
            $this->jobs,
            $this->row($job),
            ['id' => $job->id],
            ['%s', '%s', '%d', '%d', '%d', '%s'],
            ['%d']
        );
        if ($ok === false) {
            throw new FinetuneExportException('Could not save job ' . $job->id . ': ' . $this->db->last_error);
        }
    }

    public function find(int $id): ?Job
    {
        $row = $this->db->get_row(
            (string) $this->db->prepare('SELECT * FROM %i WHERE id = %d', $this->jobs, $id),
            ARRAY_A
        );
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function all(int $limit = 100): array
    {
        $rows = $this->db->get_results(
            (string) $this->db->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d', $this->jobs, $limit),
            ARRAY_A
        );
        return array_map(fn (array $row): Job => $this->hydrate($row), is_array($rows) ? $rows : []);
    }

    public function inStates(array $states): array
    {
        if ($states === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($states), '%s'));
        $rows = $this->db->get_results(
            (string) $this->db->prepare(
                'SELECT * FROM %i WHERE state IN (' . $placeholders . ') ORDER BY id ASC',
                $this->jobs,
                ...$states
            ),
            ARRAY_A
        );
        return array_map(fn (array $row): Job => $this->hydrate($row), is_array($rows) ? $rows : []);
    }

    public function delete(int $id): void
    {
        $this->clearHashes($id);
        if ($this->db->delete($this->jobs, ['id' => $id], ['%d']) === false) {
            throw new FinetuneExportException('Could not delete job record ' . $id . ': ' . $this->db->last_error);
        }
    }

    public function firstWithHash(int $jobId, string $hash, int $postId): int
    {
        $this->db->query((string) $this->db->prepare(
            'INSERT IGNORE INTO %i (job_id, hash, post_id) VALUES (%d, %s, %d)',
            $this->hashes,
            $jobId,
            $hash,
            $postId
        ));
        $first = $this->db->get_var((string) $this->db->prepare(
            'SELECT post_id FROM %i WHERE job_id = %d AND hash = %s',
            $this->hashes,
            $jobId,
            $hash
        ));
        if ($first === null) {
            throw new FinetuneExportException('Could not record a content hash: ' . $this->db->last_error);
        }
        return (int) $first;
    }

    public function clearHashes(int $jobId): void
    {
        $this->db->delete($this->hashes, ['job_id' => $jobId], ['%d']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Job $job): array
    {
        $data = $job->toArray();
        unset($data['id'], $data['kind'], $data['state'], $data['createdBy'], $data['createdAt'], $data['updatedAt']);
        return [
            'kind' => $job->kind,
            'state' => $job->state,
            'created_by' => $job->createdBy,
            'created_at' => $job->createdAt,
            'updated_at' => $job->updatedAt,
            'data' => (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Job
    {
        $data = json_decode((string) ($row['data'] ?? '{}'), true);
        $data = is_array($data) ? $data : [];
        $data['id'] = (int) $row['id'];
        $data['kind'] = (string) $row['kind'];
        $data['state'] = (string) $row['state'];
        $data['createdBy'] = (int) $row['created_by'];
        $data['createdAt'] = (int) $row['created_at'];
        $data['updatedAt'] = (int) $row['updated_at'];
        // JSON turns integer keys of the stats map into strings; Job::fromArray keeps them as array keys.
        return Job::fromArray($data);
    }
}
