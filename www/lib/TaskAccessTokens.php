<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';

// Limited-authentication tokens for acting on a task from an email link.
//
// A token authenticates one user for exactly one task, and only within the
// /t/ pages (view, comment, mark complete). The raw 64-hex-char token exists
// only inside the email; the database stores its sha256. Tokens expire (see
// the task_token_expiry_days setting) but are not revoked on completion, so a
// recipient can still add a follow-up comment.
class TaskAccessTokens {

    public const DEFAULT_TTL_DAYS = 30;

    private static function pdo(): PDO {
        return pdo();
    }

    private static function ttlDays(): int {
        $days = (int)Settings::get('task_token_expiry_days', (string)self::DEFAULT_TTL_DAYS);
        return $days > 0 ? $days : self::DEFAULT_TTL_DAYS;
    }

    // Returns the RAW token (the only time it exists in plaintext). Reuses the
    // newest active token for this (task, user) so repeated digests keep the
    // same link — but that requires the raw token, so reuse works by keeping a
    // per-run cache; across runs a fresh token row is created.
    private static array $issuedThisRun = [];

    public static function issueForTaskRecipient(int $taskId, int $userId, ?int $ttlDays = null): string {
        $cacheKey = $taskId . ':' . $userId;
        if (isset(self::$issuedThisRun[$cacheKey])) {
            return self::$issuedThisRun[$cacheKey];
        }

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $days = $ttlDays ?? self::ttlDays();
        $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);

        $st = self::pdo()->prepare(
            'INSERT INTO task_access_tokens (task_id, user_id, token_hash, expires_at) VALUES (?,?,?,?)'
        );
        $st->execute([$taskId, $userId, $hash, $expiresAt]);

        self::$issuedThisRun[$cacheKey] = $raw;
        return $raw;
    }

    // Verifies a raw token. Returns ['token_id','task_id','user_id'] or null
    // if the token is unknown, expired, or revoked. Bumps last_used_at.
    public static function verify(string $rawToken): ?array {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
            return null;
        }

        $hash = hash('sha256', $rawToken);
        $st = self::pdo()->prepare(
            'SELECT id, task_id, user_id FROM task_access_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }

        self::pdo()->prepare('UPDATE task_access_tokens SET last_used_at = NOW() WHERE id = ?')
            ->execute([(int)$row['id']]);

        return [
            'token_id' => (int)$row['id'],
            'task_id' => (int)$row['task_id'],
            'user_id' => (int)$row['user_id'],
        ];
    }

    public static function revokeForTask(int $taskId): void {
        self::pdo()->prepare('UPDATE task_access_tokens SET revoked_at = NOW() WHERE task_id = ? AND revoked_at IS NULL')
            ->execute([$taskId]);
    }

    // Absolute URL for the token-authenticated task page, used in emails.
    public static function taskActionUrl(string $rawToken): string {
        $base = rtrim(Settings::get('site_base_url', ''), '/');
        return $base . '/t/index.php?token=' . $rawToken;
    }
}
