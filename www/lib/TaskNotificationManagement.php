<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/TaskAccessTokens.php';

/**
 * Daily reminder engine for tasks.
 *
 * Policy (see docs/email-notificaitons-spec.md, adapted from obligations to
 * group tasks):
 *  - One digest email per recipient per run, across all of their groups.
 *  - An email is TRIGGERED by: an overdue task (daily), a task due today, or a
 *    task_reminders row whose "due_date - days_in_advance" is today.
 *  - Recipients: the assignee (if they have an email); for unassigned tasks
 *    (or assignees without email), the group's owner and admins.
 *  - Deduplicated via notification_log, so re-running is safe (idempotent).
 *  - Every task line in the email links through a task access token so the
 *    recipient can comment / mark complete without logging in.
 *
 * Email is the only channel for now; the digest data structure returned by
 * collectRecipientDigests() is channel-agnostic so push/SMS can be added later.
 */
class TaskNotificationManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Collection =====

    /**
     * Build the per-recipient digest for $today. Returns
     *   recipient_user_id => [
     *     'user' => user row,
     *     'overdue' | 'due_today' | 'upcoming' => task rows (with days_until),
     *     'triggers' => [[task_id, group_id, notification_type, days_in_advance|null], ...],
     *   ]
     * plus '_stats' with the evaluated-task count.
     */
    public static function collectRecipientDigests(string $today, bool $ignoreThrottling = false): array {
        $usersById = [];
        foreach (self::pdo()->query('SELECT * FROM users') as $u) {
            $usersById[(int)$u['id']] = $u;
        }

        // Owner + admins per group (the fallback recipients).
        $groupAdmins = [];
        $st = self::pdo()->query(
            "SELECT g.id AS group_id, u.id AS user_id
             FROM task_groups g
             JOIN group_members gm ON gm.group_id = g.id
             JOIN users u ON u.id = gm.user_id
             WHERE (gm.role = 'admin' OR u.id = g.owner_user_id) AND u.email IS NOT NULL AND u.email <> ''"
        );
        foreach ($st as $row) {
            $groupAdmins[(int)$row['group_id']][] = (int)$row['user_id'];
        }

        $st = self::pdo()->prepare(
            'SELECT t.*, g.name AS group_name
             FROM tasks t
             JOIN task_groups g ON g.id = t.group_id
             WHERE t.is_done = 0 AND t.due_date IS NOT NULL
             ORDER BY t.due_date, t.title'
        );
        $st->execute();
        $tasks = $st->fetchAll();

        // Reminder rows for the evaluated tasks, keyed by task id.
        $remindersByTask = [];
        if ($tasks) {
            $ids = array_column($tasks, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rs = self::pdo()->prepare("SELECT task_id, days_in_advance FROM task_reminders WHERE task_id IN ($in)");
            $rs->execute($ids);
            foreach ($rs->fetchAll() as $r) {
                $remindersByTask[(int)$r['task_id']][] = (int)$r['days_in_advance'];
            }
        }

        $digests = [];
        $ensure = function (array $user) use (&$digests): int {
            $id = (int)$user['id'];
            if (!isset($digests[$id])) {
                $digests[$id] = ['user' => $user, 'overdue' => [], 'due_today' => [], 'upcoming' => [], 'triggers' => []];
            }
            return $id;
        };

        foreach ($tasks as $t) {
            $tid = (int)$t['id'];
            $gid = (int)$t['group_id'];
            $daysUntil = self::daysBetween($today, (string)$t['due_date']);

            // Which bucket, if any, does this task land in today? A reminder
            // row puts the task in "upcoming" exactly on its trigger day.
            $reminderHit = null;
            foreach ($remindersByTask[$tid] ?? [] as $days) {
                if ($daysUntil === $days && $days > 0) {
                    $reminderHit = $days;
                    break;
                }
            }

            $bucket = null;
            if ($daysUntil < 0) {
                $bucket = 'overdue';
            } elseif ($daysUntil === 0) {
                $bucket = 'due_today';
            } elseif ($reminderHit !== null) {
                $bucket = 'upcoming';
            }
            if ($bucket === null) continue;

            // Recipient rules: assignee with an email, else the group's owner+admins.
            $recipients = [];
            $assignee = $usersById[(int)($t['assigned_to_user_id'] ?? 0)] ?? null;
            if ($assignee && !empty($assignee['email'])) {
                $recipients = [$assignee];
            } else {
                foreach ($groupAdmins[$gid] ?? [] as $uid) {
                    $recipients[] = $usersById[$uid];
                }
            }
            if (empty($recipients)) continue;

            $t['days_until'] = $daysUntil;
            foreach ($recipients as $recipient) {
                $rid = $ensure($recipient);
                $digests[$rid][$bucket][] = $t;

                // Trigger determination (dedup against the notification log;
                // $ignoreThrottling bypasses the dedup for testing)
                if ($bucket === 'overdue' && ($ignoreThrottling || !self::wasSentOn($tid, $rid, 'overdue', $today))) {
                    $digests[$rid]['triggers'][] = [$tid, $gid, 'overdue', null];
                } elseif ($bucket === 'due_today' && ($ignoreThrottling || !self::wasSentOn($tid, $rid, 'due_today', $today))) {
                    $digests[$rid]['triggers'][] = [$tid, $gid, 'due_today', null];
                } elseif ($bucket === 'upcoming' && ($ignoreThrottling || !self::wasSentOn($tid, $rid, 'reminder', $today))) {
                    $digests[$rid]['triggers'][] = [$tid, $gid, 'reminder', $reminderHit];
                }
            }
        }

        $digests['_stats'] = ['tasks_evaluated' => count($tasks)];
        return $digests;
    }

    // ===== Sending =====

    /**
     * Run the daily notification pass. Safe to run multiple times per day.
     *
     * @param callable|null $sendEmail fn(string $to, string $toName, string $subject, string $html): bool
     *                                 (defaults to the SMTP mailer; tests inject a fake)
     * @param bool $dryRun collect and report, but send nothing and record nothing
     * @param bool $ignoreThrottling re-send even if already sent (for testing)
     * @return array stats
     */
    public static function runDailyNotifications(string $today, ?callable $sendEmail = null, bool $dryRun = false, bool $ignoreThrottling = false): array {
        if ($sendEmail === null) {
            require_once __DIR__ . '/../mailer.php';
            $sendEmail = function (string $to, string $toName, string $subject, string $html): bool {
                return send_email($to, $subject, $html, $toName);
            };
        }

        $digests = self::collectRecipientDigests($today, $ignoreThrottling);
        $stats = [
            'date' => $today,
            'tasks_evaluated' => $digests['_stats']['tasks_evaluated'],
            'recipients_with_triggers' => 0,
            'emails_sent' => 0,
            'emails_failed' => 0,
            'notifications_recorded' => 0,
            'dry_run' => $dryRun,
            'ignore_throttling' => $ignoreThrottling,
        ];
        unset($digests['_stats']);

        foreach ($digests as $rid => $digest) {
            if (empty($digest['triggers'])) continue;
            $stats['recipients_with_triggers']++;

            $user = $digest['user'];

            if ($dryRun) {
                continue;
            }

            // Building the email issues the per-task access tokens, so only do
            // it for real sends.
            [$subject, $html] = self::buildDigestEmail($digest, $today);

            $ok = false;
            $errorMessage = null;
            try {
                $ok = (bool)$sendEmail((string)$user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $subject, $html);
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
            }

            if ($ok) {
                $stats['emails_sent']++;
            } else {
                $stats['emails_failed']++;
                $errorMessage = $errorMessage ?? 'send failed';
            }

            // Record every trigger. Failed sends are recorded for the audit trail
            // but do not block a retry (dedup only counts delivery_status='sent').
            foreach ($digest['triggers'] as [$taskId, $groupId, $type, $daysInAdvance]) {
                self::recordNotification($taskId, $groupId, (int)$rid, $type, $daysInAdvance, $today, (string)$user['email'], $ok, $errorMessage);
                $stats['notifications_recorded']++;
            }
        }

        return $stats;
    }

    // Subject + HTML body for one recipient's digest. Every task line links
    // through a fresh access token for this recipient.
    public static function buildDigestEmail(array $digest, string $today): array {
        $siteTitle = Settings::siteTitle();
        $user = $digest['user'];
        $rid = (int)$user['id'];

        $total = count($digest['overdue']) + count($digest['due_today']) + count($digest['upcoming']);
        $subject = $siteTitle . ': ' . $total . ' reminder' . ($total === 1 ? '' : 's') . ' for today';

        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $item = function (array $t, string $suffix) use ($rid, $e): string {
            $token = TaskAccessTokens::issueForTaskRecipient((int)$t['id'], $rid);
            $url = TaskAccessTokens::taskActionUrl($token);
            $group = !empty($t['group_name']) ? ' (' . $e($t['group_name']) . ')' : '';
            return '<li><a href="' . $e($url) . '">' . $e($t['title']) . '</a>' . $group . ' — ' . $e($suffix) . '</li>';
        };

        $html = '<p>Hello ' . $e($user['first_name']) . ',</p>'
              . '<p>You have ' . $total . ' ' . $e($siteTitle) . ' reminder' . ($total === 1 ? '' : 's') . '.</p>';

        if (!empty($digest['due_today'])) {
            $html .= '<h3 style="color:#b45309;">Due Today</h3><ul>';
            foreach ($digest['due_today'] as $t) {
                $html .= $item($t, 'due today');
            }
            $html .= '</ul>';
        }

        if (!empty($digest['upcoming'])) {
            $html .= '<h3>Upcoming</h3><ul>';
            foreach ($digest['upcoming'] as $t) {
                $n = (int)$t['days_until'];
                $html .= $item($t, 'due in ' . $n . ' day' . ($n === 1 ? '' : 's') . ' (' . date('M j', strtotime($t['due_date'])) . ')');
            }
            $html .= '</ul>';
        }

        if (!empty($digest['overdue'])) {
            $html .= '<h3 style="color:#b91c1c;">Overdue</h3><ul>';
            foreach ($digest['overdue'] as $t) {
                $n = -(int)$t['days_until'];
                $html .= $item($t, $n . ' day' . ($n === 1 ? '' : 's') . ' overdue');
            }
            $html .= '</ul>';
        }

        $baseUrl = rtrim(Settings::get('site_base_url', ''), '/');
        $html .= '<p><a href="' . $e($baseUrl) . '/">View all tasks</a></p>';

        return [$subject, $html];
    }

    // ===== Notification log =====

    // Was a notification of $type successfully sent for exactly $date?
    public static function wasSentOn(int $taskId, int $recipientUserId, string $type, string $date): bool {
        $st = self::pdo()->prepare(
            "SELECT 1 FROM notification_log
             WHERE task_id = ? AND recipient_user_id = ? AND notification_type = ? AND notification_date = ?
               AND delivery_status = 'sent' LIMIT 1"
        );
        $st->execute([$taskId, $recipientUserId, $type, $date]);
        return (bool)$st->fetchColumn();
    }

    public static function recordNotification(?int $taskId, ?int $groupId, int $recipientUserId, string $type, ?int $daysInAdvance, string $date, string $email, bool $sent, ?string $errorMessage = null): void {
        $st = self::pdo()->prepare(
            'INSERT INTO notification_log (task_id, group_id, recipient_user_id, notification_type, days_in_advance, notification_date, email_address, delivery_status, error_message)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([$taskId, $groupId, $recipientUserId, $type, $daysInAdvance, $date, $email, $sent ? 'sent' : 'failed', $sent ? null : $errorMessage]);
    }

    // Whole days from $from to $to (negative when $to is in the past)
    private static function daysBetween(string $from, string $to): int {
        return (int)round(((new DateTimeImmutable($to))->getTimestamp() - (new DateTimeImmutable($from))->getTimestamp()) / 86400);
    }
}
