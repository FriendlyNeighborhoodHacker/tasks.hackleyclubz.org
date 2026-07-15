<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/TaskAccessTokens.php';
require_once __DIR__ . '/EmailTemplates.php';

/**
 * Reminder + assignment email engine for tasks.
 *
 * Policy (see docs/email-notificaitons-spec.md, adapted from obligations to
 * group tasks):
 *  - Reminder emails use the group's customizable templates: one email per
 *    recipient per group per run — the single-task template when exactly one
 *    task triggered, the multi-task template otherwise. A task whose email
 *    was hand-edited in the preview modal (custom_email_subject/body) is sent
 *    as its own email using that saved content.
 *  - An email is TRIGGERED by: an overdue task (daily), a task due today, or a
 *    task_reminders row whose "due_date - days_in_advance" is today.
 *  - Recipients: the assignee (if they have an email); for unassigned tasks
 *    (or assignees without email), the group's owner and admins.
 *  - Deduplicated via notification_log, so re-running is safe (idempotent).
 *  - Task names in the emails link through a task access token so the
 *    recipient can comment / mark complete without logging in.
 *  - Assignment emails (sendAssignmentEmail) go out immediately when a task
 *    is assigned, using the group's assignment template.
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
            'SELECT t.*, g.name AS group_name,
                    cu.first_name AS creator_first_name, cu.last_name AS creator_last_name,
                    ou.first_name AS owner_first_name, ou.last_name AS owner_last_name
             FROM tasks t
             JOIN task_groups g ON g.id = t.group_id
             JOIN users ou ON ou.id = g.owner_user_id
             LEFT JOIN users cu ON cu.id = t.created_by_user_id
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

            // Building the emails issues the per-task access tokens, so only
            // do it for real sends.
            foreach (self::buildReminderEmails($digest) as $email) {
                $ok = false;
                $errorMessage = null;
                try {
                    $ok = (bool)$sendEmail((string)$user['email'], trim($user['first_name'] . ' ' . $user['last_name']), $email['subject'], $email['html']);
                } catch (\Throwable $e) {
                    $errorMessage = $e->getMessage();
                }

                if ($ok) {
                    $stats['emails_sent']++;
                } else {
                    $stats['emails_failed']++;
                    $errorMessage = $errorMessage ?? 'send failed';
                }

                // Record every trigger this email covered. Failed sends are
                // recorded for the audit trail but do not block a retry (dedup
                // only counts delivery_status='sent').
                foreach ($email['triggers'] as [$taskId, $groupId, $type, $daysInAdvance]) {
                    self::recordNotification($taskId, $groupId, (int)$rid, $type, $daysInAdvance, $today, (string)$user['email'], $ok, $errorMessage);
                    $stats['notifications_recorded']++;
                }
            }
        }

        return $stats;
    }

    /**
     * The reminder emails for one recipient's digest, one per group (plus one
     * per task whose email was hand-edited in the preview modal). Each entry:
     * ['subject' =>, 'html' =>, 'triggers' => [the covered trigger rows]].
     * Issues an access token per task, so only call this when really sending.
     */
    public static function buildReminderEmails(array $digest): array {
        $user = $digest['user'];
        $rid = (int)$user['id'];

        $tasksById = [];
        foreach (['overdue', 'due_today', 'upcoming'] as $bucket) {
            foreach ($digest[$bucket] as $t) {
                $tasksById[(int)$t['id']] = $t;
            }
        }

        // Bundle the triggered tasks by group, splitting out tasks with a
        // saved custom email.
        $byGroup = [];
        foreach ($digest['triggers'] as $trigger) {
            $t = $tasksById[(int)$trigger[0]] ?? null;
            if (!$t) continue;
            $gid = (int)$trigger[1];
            $slot = self::hasCustomEmail($t) ? 'custom' : 'templated';
            $byGroup[$gid][$slot][] = ['task' => $t, 'trigger' => $trigger];
        }

        $emails = [];
        foreach ($byGroup as $gid => $bundle) {
            foreach ($bundle['custom'] ?? [] as $entry) {
                $emails[] = self::buildCustomTaskEmail($entry['task'], $user) + ['triggers' => [$entry['trigger']]];
            }
            if (!empty($bundle['templated'])) {
                $tasks = array_column($bundle['templated'], 'task');
                $triggers = array_column($bundle['templated'], 'trigger');
                $email = count($tasks) === 1
                    ? self::buildSingleTaskEmail($gid, $tasks[0], $user)
                    : self::buildMultiTaskEmail($gid, $tasks, $user);
                $emails[] = $email + ['triggers' => $triggers];
            }
        }
        return $emails;
    }

    public static function hasCustomEmail(array $task): bool {
        return trim((string)($task['custom_email_subject'] ?? '')) !== ''
            && trim((string)($task['custom_email_body'] ?? '')) !== '';
    }

    // "[task_assigner]": the task's creator, falling back to the group owner.
    public static function assignerName(array $task): string {
        $creator = trim(($task['creator_first_name'] ?? '') . ' ' . ($task['creator_last_name'] ?? ''));
        if ($creator !== '') return $creator;
        $owner = trim(($task['owner_first_name'] ?? '') . ' ' . ($task['owner_last_name'] ?? ''));
        return $owner !== '' ? $owner : (string)($task['group_name'] ?? '');
    }

    // Plain-text token values for one task's email to one recipient.
    public static function taskTokens(array $task, array $recipientUser): array {
        return [
            'first_name' => (string)$recipientUser['first_name'],
            'task_assigner' => self::assignerName($task),
            'task_name' => (string)$task['title'],
            'task_due_date' => EmailTemplates::dueDateLabel($task['due_date'] ?? null),
            'task_description' => trim((string)($task['description'] ?? '')),
            'group_name' => (string)($task['group_name'] ?? ''),
        ];
    }

    private static function taskLinkHtml(array $task, int $recipientUserId): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $token = TaskAccessTokens::issueForTaskRecipient((int)$task['id'], $recipientUserId);
        return '<a href="' . $e(TaskAccessTokens::taskActionUrl($token)) . '">' . $e($task['title']) . '</a>';
    }

    // One task, group template: [task_name] renders as an access-token link.
    private static function buildSingleTaskEmail(int $groupId, array $task, array $user): array {
        $tpl = EmailTemplates::getTemplate($groupId, EmailTemplates::TYPE_REMINDER_SINGLE);
        $tokens = self::taskTokens($task, $user);
        return [
            'subject' => EmailTemplates::renderText($tpl['subject'], $tokens),
            'html' => EmailTemplates::renderHtml($tpl['body'], $tokens, [
                'task_name' => self::taskLinkHtml($task, (int)$user['id']),
            ]),
        ];
    }

    // Several tasks, group template: [task_list] expands to the numbered tasks.
    private static function buildMultiTaskEmail(int $groupId, array $tasks, array $user): array {
        $tpl = EmailTemplates::getTemplate($groupId, EmailTemplates::TYPE_REMINDER_MULTI);

        // A single sender reads best: the shared creator if there is one,
        // otherwise the group owner.
        $assigners = array_unique(array_map([self::class, 'assignerName'], $tasks));
        $assigner = count($assigners) === 1
            ? $assigners[0]
            : trim(($tasks[0]['owner_first_name'] ?? '') . ' ' . ($tasks[0]['owner_last_name'] ?? ''));

        $items = [];
        foreach ($tasks as $t) {
            $items[] = [
                'title' => (string)$t['title'],
                'title_html' => self::taskLinkHtml($t, (int)$user['id']),
                'due_label' => EmailTemplates::dueDateLabel($t['due_date'] ?? null),
                'description' => (string)($t['description'] ?? ''),
            ];
        }

        $tokens = [
            'first_name' => (string)$user['first_name'],
            'task_assigner' => $assigner,
            'group_name' => (string)($tasks[0]['group_name'] ?? ''),
            'task_count' => count($tasks),
            'task_list' => EmailTemplates::taskListText($items),
        ];
        return [
            'subject' => EmailTemplates::renderText($tpl['subject'], $tokens),
            'html' => EmailTemplates::renderHtml($tpl['body'], $tokens, [
                'task_list' => EmailTemplates::taskListHtml($items),
            ]),
        ];
    }

    // A task whose email was hand-edited in the preview modal. Any tokens the
    // editor kept still render, and a view/complete link is appended since the
    // edited text may not contain one.
    private static function buildCustomTaskEmail(array $task, array $user): array {
        $tokens = self::taskTokens($task, $user);
        $link = self::taskLinkHtml($task, (int)$user['id']);
        $html = EmailTemplates::renderHtml((string)$task['custom_email_body'], $tokens, ['task_name' => $link])
              . '<p>View or complete this task: ' . $link . '</p>';
        return [
            'subject' => EmailTemplates::renderText((string)$task['custom_email_subject'], $tokens),
            'html' => $html,
        ];
    }

    // ===== Assignment emails =====

    /**
     * Send the group's assignment-template email to a task's assignee. Called
     * right after a task is created with an assignee or reassigned. Best-effort:
     * returns false (never throws) when there is nothing to send — no assignee,
     * no assignee email, or self-assignment.
     */
    public static function sendAssignmentEmail(int $taskId, ?UserContext $assignerCtx = null, ?callable $sendEmail = null): bool {
        require_once __DIR__ . '/TaskManagement.php';

        $task = TaskManagement::getTask($taskId);
        if (!$task || empty($task['assigned_to_user_id']) || empty($task['assignee_email'])) return false;
        $assigneeId = (int)$task['assigned_to_user_id'];
        if ($assignerCtx && $assignerCtx->id === $assigneeId) return false;

        // The assigner is whoever performed the action, not the task creator.
        $assignerName = null;
        if ($assignerCtx) {
            $st = self::pdo()->prepare('SELECT first_name, last_name FROM users WHERE id=?');
            $st->execute([$assignerCtx->id]);
            if ($u = $st->fetch()) {
                $assignerName = trim($u['first_name'] . ' ' . $u['last_name']);
            }
        }

        $recipient = ['id' => $assigneeId, 'first_name' => (string)($task['assignee_first_name'] ?? '')];
        $tokens = self::taskTokens($task, $recipient);
        if ($assignerName) {
            $tokens['task_assigner'] = $assignerName;
        }

        $tpl = EmailTemplates::getTemplate((int)$task['group_id'], EmailTemplates::TYPE_ASSIGNMENT);
        $subject = EmailTemplates::renderText($tpl['subject'], $tokens);
        $html = EmailTemplates::renderHtml($tpl['body'], $tokens, [
            'task_name' => self::taskLinkHtml($task, $assigneeId),
        ]);

        if ($sendEmail === null) {
            require_once __DIR__ . '/../mailer.php';
            $sendEmail = function (string $to, string $toName, string $subject, string $html): bool {
                return send_email($to, $subject, $html, $toName);
            };
        }

        $ok = false;
        $errorMessage = null;
        try {
            $toName = trim(($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''));
            $ok = (bool)$sendEmail((string)$task['assignee_email'], $toName, $subject, $html);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
        }

        try {
            self::recordNotification($taskId, (int)$task['group_id'], $assigneeId, 'assignment', null, date('Y-m-d'), (string)$task['assignee_email'], $ok, $ok ? null : ($errorMessage ?? 'send failed'));
        } catch (\Throwable $e) {
            // Logging must never break the task save.
        }
        return $ok;
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
