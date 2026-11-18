<?php

declare(strict_types=1);

namespace IndianOpinion\FinetuneExport\Links;

use IndianOpinion\FinetuneExport\Support\Clock;

/**
 * Signed, expiring download tokens (PRD case 71).
 *
 * A token is 83 characters of base64url with no dot and no extension: a
 * 30-byte payload (version, job id, user id, expiry, file kind, 16-byte
 * random nonce) followed by its 32-byte HMAC-SHA256. The key is derived from
 * the site's authentication salts and a per-site secret, so rotating the
 * salts invalidates every link. Signatures are compared with hash_equals.
 */
final class LinkSigner
{
    public const TOKEN_LENGTH = 83;
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{83}$/';
    private const VERSION = 1;
    private const KINDS = ['train' => 1, 'validation' => 2];

    private string $key;

    public function __construct(string $salts, string $siteSecret, private readonly Clock $clock)
    {
        if ($siteSecret === '' || $salts === '') {
            throw new \InvalidArgumentException('Link signing needs the site salts and secret.');
        }
        $this->key = hash_hmac('sha256', 'finetune-export/download-link/v1', $salts . '|' . $siteSecret, true);
    }

    /**
     * @return array{token: string, nonce: string, expires: int}
     */
    public function issue(int $jobId, int $userId, int $ttl, string $kind = 'train'): array
    {
        if ($jobId < 1 || $jobId > 0xFFFFFFFF || $userId < 1 || $userId > 0xFFFFFFFF) {
            throw new \InvalidArgumentException('Job and user ids must be positive 32-bit numbers.');
        }
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Unknown file kind ' . $kind);
        }
        $expires = $this->clock->now() + max(1, $ttl);
        $nonce = random_bytes(16);
        $payload = pack('CNNNC', self::VERSION, $jobId, $userId, $expires, self::KINDS[$kind]) . $nonce;
        $token = self::encode($payload . hash_hmac('sha256', $payload, $this->key, true));
        return ['token' => $token, 'nonce' => bin2hex($nonce), 'expires' => $expires];
    }

    /**
     * Checks format, signature, user and expiry, in that order. Revocation
     * and job lookups are the caller's, after this passes.
     */
    public function verify(string $token, int $userId): LinkCheck
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return new LinkCheck(LinkCheck::MALFORMED);
        }
        $raw = self::decode($token);
        if ($raw === null || strlen($raw) !== 62) {
            return new LinkCheck(LinkCheck::MALFORMED);
        }
        $payload = substr($raw, 0, 30);
        $mac = substr($raw, 30);
        if (!hash_equals(hash_hmac('sha256', $payload, $this->key, true), $mac)) {
            return new LinkCheck(LinkCheck::BAD_SIGNATURE);
        }
        /** @var array{version: int, job: int, user: int, expires: int, kind: int} $fields */
        $fields = unpack('Cversion/Njob/Nuser/Nexpires/Ckind', $payload);
        $kind = array_search($fields['kind'], self::KINDS, true);
        if ($fields['version'] !== self::VERSION || $kind === false) {
            return new LinkCheck(LinkCheck::BAD_SIGNATURE);
        }
        $nonce = bin2hex(substr($payload, 14, 16));
        if ($fields['user'] !== $userId) {
            return new LinkCheck(LinkCheck::WRONG_USER, $fields['job'], $fields['user'], $fields['expires'], $kind, $nonce);
        }
        if ($fields['expires'] < $this->clock->now()) {
            return new LinkCheck(LinkCheck::EXPIRED, $fields['job'], $fields['user'], $fields['expires'], $kind, $nonce);
        }
        return new LinkCheck(LinkCheck::OK, $fields['job'], $fields['user'], $fields['expires'], $kind, $nonce);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $token): ?string
    {
        $raw = base64_decode(strtr($token, '-_', '+/') . '=', true);
        return $raw === false ? null : $raw;
    }
}
