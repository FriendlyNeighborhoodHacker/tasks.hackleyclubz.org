<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/GroupManagement.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * Per-group customizable email templates (see docs/email-notificaitons-spec.md).
 *
 * Three template types per group:
 *   assignment      — sent when a task is assigned to someone
 *   reminder_single — scheduled reminder covering exactly one task
 *   reminder_multi  — scheduled reminder covering several tasks at once
 *
 * Templates are plain text with [token] placeholders. Defaults live in code;
 * a group_email_templates row exists only once a group customizes a template
 * (deleting the row restores the default). Rendering supports both plain text
 * (the preview modal's textarea) and HTML (the actual emails, where the task
 * name becomes an access-token link).
 */
class EmailTemplates {
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_REMINDER_SINGLE = 'reminder_single';
    public const TYPE_REMINDER_MULTI = 'reminder_multi';

    public const TYPES = [self::TYPE_ASSIGNMENT, self::TYPE_REMINDER_SINGLE, self::TYPE_REMINDER_MULTI];

    // UI metadata for the settings page.
    public const TYPE_INFO = [
        self::TYPE_ASSIGNMENT => [
            'label' => 'Task assigned',
            'help' => 'Sent when a task is assigned to someone.',
            'tokens' => '[first_name] [task_assigner] [task_name] [task_due_date] [task_description] [group_name]',
        ],
        self::TYPE_REMINDER_SINGLE => [
            'label' => 'Reminder — one task',
            'help' => 'Scheduled reminder when someone has exactly one task due.',
            'tokens' => '[first_name] [task_assigner] [task_name] [task_due_date] [task_description] [group_name]',
        ],
        self::TYPE_REMINDER_MULTI => [
            'label' => 'Reminder — multiple tasks',
            'help' => 'Scheduled reminder when someone has several tasks due; [task_list] expands to the numbered tasks.',
            'tokens' => '[first_name] [task_assigner] [task_list] [task_count] [group_name]',
        ],
    ];

    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Defaults =====

    public static function defaultTemplate(string $type): array {
        switch ($type) {
            case self::TYPE_ASSIGNMENT:
                return [
                    'subject' => 'New task for you: [task_name]',
                    'body' => "Hello [first_name],\n\n"
                        . "[task_assigner] has assigned you to [task_name] which is due on [task_due_date].\n"
                        . "[task_description]\n\n"
                        . "We will send out email reminders as the due date gets closer.\n\n"
                        . "We appreciate all that you do!\n\n"
                        . "- the [group_name] Team!",
                ];
            case self::TYPE_REMINDER_SINGLE:
                return [
                    'subject' => 'Reminder: [task_name] is due on [task_due_date]',
                    'body' => "Hello [first_name],\n\n"
                        . "This is your scheduled email reminder from [task_assigner] to complete [task_name] which is due on [task_due_date].\n"
                        . "[task_description]\n\n"
                        . "We appreciate all that you do!\n\n"
                        . "- the [group_name] Team!",
                ];
            case self::TYPE_REMINDER_MULTI:
                return [
                    'subject' => 'Reminder: you have [task_count] tasks in [group_name]',
                    'body' => "Hello [first_name],\n\n"
                        . "This is your scheduled email reminder from [task_assigner] to complete these tasks:\n\n"
                        . "[task_list]\n\n"
                        . "We appreciate all that you do!\n\n"
                        . "- the [group_name] Team!",
                ];
        }
        throw new InvalidArgumentException('Unknown email template type: ' . $type);
    }

    // ===== Storage =====

    // The group's template of $type: the customized row if present, else the
    // default. 'is_custom' says which one you got.
    public static function getTemplate(int $groupId, string $type): array {
        self::assertType($type);
        $st = self::pdo()->prepare('SELECT subject, body FROM group_email_templates WHERE group_id=? AND template_type=? LIMIT 1');
        $st->execute([$groupId, $type]);
        if ($row = $st->fetch()) {
            return ['subject' => (string)$row['subject'], 'body' => (string)$row['body'], 'is_custom' => true];
        }
        return self::defaultTemplate($type) + ['is_custom' => false];
    }

    public static function saveTemplate(?UserContext $ctx, int $groupId, string $type, string $subject, string $body): void {
        self::assertType($type);
        $ctx = self::assertCanManage($ctx, $groupId);

        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new InvalidArgumentException('Subject and body are both required (use "Reset to default" to discard a customization).');
        }

        $st = self::pdo()->prepare(
            'INSERT INTO group_email_templates (group_id, template_type, subject, body, updated_by_user_id)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE subject=VALUES(subject), body=VALUES(body), updated_by_user_id=VALUES(updated_by_user_id)'
        );
        $st->execute([$groupId, $type, $subject, $body, $ctx->id]);
        self::log('email_template.save', ['group_id' => $groupId, 'template_type' => $type]);
    }

    public static function resetTemplate(?UserContext $ctx, int $groupId, string $type): void {
        self::assertType($type);
        self::assertCanManage($ctx, $groupId);
        self::pdo()->prepare('DELETE FROM group_email_templates WHERE group_id=? AND template_type=?')->execute([$groupId, $type]);
        self::log('email_template.reset', ['group_id' => $groupId, 'template_type' => $type]);
    }

    // ===== Rendering =====

    // Subjects and preview bodies are plain text: straight token substitution.
    public static function renderText(string $template, array $tokens): string {
        $repl = [];
        foreach ($tokens as $k => $v) {
            $repl['[' . $k . ']'] = (string)$v;
        }
        return strtr($template, $repl);
    }

    /**
     * Render a template body to email HTML. The template text is escaped and
     * newlines become <br>, then tokens are substituted: $tokens as escaped
     * plain text, $htmlTokens verbatim (for links / the multi-task list).
     */
    public static function renderHtml(string $template, array $tokens, array $htmlTokens = []): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $repl = [];
        foreach ($tokens as $k => $v) {
            $repl['[' . $k . ']'] = $e($v);
        }
        foreach ($htmlTokens as $k => $v) {
            $repl['[' . $k . ']'] = (string)$v;
        }
        $html = strtr(nl2br($e($template), false), $repl);
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1a1a2e;">' . $html . '</div>';
    }

    // "July 22, 2026", or a readable fallback for tasks without a due date.
    public static function dueDateLabel(?string $dueDate): string {
        if (!$dueDate) return 'no set due date';
        return date('F j, Y', strtotime($dueDate));
    }

    /**
     * The [task_list] block of the multi-task reminder, as plain text.
     * $items: ['title' =>, 'due_label' =>, 'description' =>]
     */
    public static function taskListText(array $items): string {
        $lines = [];
        foreach (array_values($items) as $i => $item) {
            $line = ($i + 1) . '. ' . $item['title'] . ' which is due on ' . $item['due_label'] . '.';
            if (trim((string)($item['description'] ?? '')) !== '') {
                $line .= "\n" . trim((string)$item['description']);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * The [task_list] block as HTML. Same items as taskListText, plus an
     * optional 'title_html' (e.g. an access-token link) that wins over 'title'.
     */
    public static function taskListHtml(array $items): string {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $lines = [];
        foreach (array_values($items) as $i => $item) {
            $title = $item['title_html'] ?? $e($item['title']);
            $line = ($i + 1) . '. ' . $title . ' which is due on ' . $e($item['due_label']) . '.';
            if (trim((string)($item['description'] ?? '')) !== '') {
                $line .= '<br>' . $e(trim((string)$item['description']));
            }
            $lines[] = $line;
        }
        return implode('<br>', $lines);
    }

    // ===== Internals =====

    private static function assertType(string $type): void {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown email template type: ' . $type);
        }
    }

    private static function assertCanManage(?UserContext $ctx, int $groupId): UserContext {
        if (!$ctx || !GroupManagement::canManageGroup($ctx, $groupId)) {
            throw new RuntimeException('Only the group owner or a group admin can edit email templates.');
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
