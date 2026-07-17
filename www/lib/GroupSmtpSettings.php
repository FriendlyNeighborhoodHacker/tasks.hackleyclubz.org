<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/GroupManagement.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * Per-group SMTP override (group_smtp_overrides table).
 *
 * By default all email goes out through the global SMTP_* constants in
 * config.local.php. A group can override that on its settings page so its
 * emails (task assignment + scheduled reminders) come from the group's own
 * address — typically a Gmail account with an app password. A row exists only
 * while a group has an override; deleting it restores the site default.
 *
 * The password is stored as-is (same trust level as config.local.php, which
 * also holds an SMTP password in plain text) and must never be echoed into
 * HTML or logged.
 */
class GroupSmtpSettings {
    public const SECURE_OPTIONS = ['tls', 'ssl'];

    private static function pdo(): PDO {
        return pdo();
    }

    // The group's stored override row, or null. Includes smtp_password —
    // callers must never render it into HTML.
    public static function get(int $groupId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM group_smtp_overrides WHERE group_id=? LIMIT 1');
        $st->execute([$groupId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * The override as a mailer config array (see send_email()'s $smtp param),
     * or null when the group has no override and should use the site default:
     *   ['host','port','username','password','secure','from_email','from_name']
     * from_email falls back to the username; from_name to the group name.
     */
    public static function getForSending(int $groupId): ?array {
        $row = self::get($groupId);
        if (!$row) return null;

        $fromName = trim((string)($row['from_name'] ?? ''));
        if ($fromName === '') {
            $group = GroupManagement::getGroup($groupId);
            $fromName = trim((string)($group['name'] ?? ''));
        }

        return [
            'host' => (string)$row['smtp_host'],
            'port' => (int)$row['smtp_port'],
            'username' => (string)$row['smtp_username'],
            'password' => (string)$row['smtp_password'],
            'secure' => (string)$row['smtp_secure'],
            'from_email' => trim((string)($row['from_email'] ?? '')) !== '' ? (string)$row['from_email'] : (string)$row['smtp_username'],
            'from_name' => $fromName,
        ];
    }

    /**
     * Create or update the group's override. $data keys: smtp_host, smtp_port,
     * smtp_username, smtp_password, smtp_secure, from_email, from_name.
     * A blank password keeps the currently stored one (so the settings form
     * never has to display it); blank on first save is an error.
     */
    public static function save(?UserContext $ctx, int $groupId, array $data): void {
        $ctx = self::assertCanManage($ctx, $groupId);

        $host = trim((string)($data['smtp_host'] ?? ''));
        $username = trim((string)($data['smtp_username'] ?? ''));
        $password = (string)($data['smtp_password'] ?? '');
        $secure = strtolower(trim((string)($data['smtp_secure'] ?? '')));
        $fromEmail = trim((string)($data['from_email'] ?? ''));
        $fromName = trim((string)($data['from_name'] ?? ''));

        if ($host === '') {
            throw new InvalidArgumentException('SMTP host is required (e.g. smtp.gmail.com).');
        }
        if ($username === '') {
            throw new InvalidArgumentException('SMTP username is required (e.g. your Gmail address).');
        }
        $portRaw = trim((string)($data['smtp_port'] ?? ''));
        if ($portRaw === '' || !ctype_digit($portRaw) || (int)$portRaw < 1 || (int)$portRaw > 65535) {
            throw new InvalidArgumentException('SMTP port must be a number between 1 and 65535 (587 for TLS, 465 for SSL).');
        }
        $port = (int)$portRaw;
        if (!in_array($secure, self::SECURE_OPTIONS, true)) {
            throw new InvalidArgumentException('Security must be "tls" or "ssl".');
        }
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('From email is not a valid email address.');
        }

        if ($password === '') {
            $existing = self::get($groupId);
            if (!$existing) {
                throw new InvalidArgumentException('Password is required when setting up SMTP for the first time.');
            }
            $password = (string)$existing['smtp_password'];
        }

        $st = self::pdo()->prepare(
            'INSERT INTO group_smtp_overrides
               (group_id, smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure, from_email, from_name, updated_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port),
               smtp_username=VALUES(smtp_username), smtp_password=VALUES(smtp_password),
               smtp_secure=VALUES(smtp_secure), from_email=VALUES(from_email),
               from_name=VALUES(from_name), updated_by_user_id=VALUES(updated_by_user_id)'
        );
        $st->execute([
            $groupId, $host, $port, $username, $password, $secure,
            $fromEmail !== '' ? $fromEmail : null,
            $fromName !== '' ? $fromName : null,
            $ctx->id,
        ]);
        self::log('group_smtp.save', ['group_id' => $groupId]);
    }

    // Remove the override; the group goes back to the site-wide SMTP config.
    public static function remove(?UserContext $ctx, int $groupId): void {
        self::assertCanManage($ctx, $groupId);
        self::pdo()->prepare('DELETE FROM group_smtp_overrides WHERE group_id=?')->execute([$groupId]);
        self::log('group_smtp.remove', ['group_id' => $groupId]);
    }

    // ===== Internals =====

    private static function assertCanManage(?UserContext $ctx, int $groupId): UserContext {
        if (!$ctx || !GroupManagement::canManageGroup($ctx, $groupId)) {
            throw new RuntimeException('Only the group owner or a group admin can edit SMTP settings.');
        }
        return $ctx;
    }

    private static function log(string $action, array $meta): void {
        try {
            ActivityLog::log(UserContext::getLoggedInUserContext(), $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
