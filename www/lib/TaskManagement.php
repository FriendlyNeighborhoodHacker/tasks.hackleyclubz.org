<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/GroupManagement.php';
require_once __DIR__ . '/Files.php';

// Tasks within a group.
//
// Permission model:
//   - Any group member may create tasks and comment on them.
//   - A task may be edited/completed by its creator, its assignee, a group
//     admin, the group owner, or an app admin.
//   - Deleting a task requires creator, group admin, owner, or app admin.
class TaskManagement {

    // Upload constraints for comment attachments
    public const ATTACHMENT_MAX_BYTES = 20 * 1024 * 1024; // 20 MB
    public const ATTACHMENT_MIME_TYPES = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'text/csv',
    ];

    // Days-in-advance for the reminder automatically added to a new task that
    // has a due date but no explicit reminders.
    public const DEFAULT_REMINDER_DAYS = 1;

    private static function pdo(): PDO {
        return pdo();
    }

    private static function log(string $action, ?int $taskId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($taskId !== null && !array_key_exists('task_id', $meta)) {
                $meta['task_id'] = $taskId;
            }
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertLoggedIn(?UserContext $ctx): UserContext {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        return $ctx;
    }

    private static function assertMemberOfGroup(?UserContext $ctx, int $groupId): UserContext {
        $ctx = self::assertLoggedIn($ctx);
        if (!GroupManagement::canViewGroup($ctx, $groupId)) {
            throw new RuntimeException('You are not a member of this group.');
        }
        return $ctx;
    }

    public static function assertCanViewTask(?UserContext $ctx, array $task): void {
        self::assertMemberOfGroup($ctx, (int)$task['group_id']);
    }

    // Creator, any assignee, group admin, owner, or app admin.
    private static function canEditTask(UserContext $ctx, array $task): bool {
        if ($ctx->admin) return true;
        if ((int)($task['created_by_user_id'] ?? 0) === $ctx->id) return true;
        $assigneeIds = array_map('intval', array_column($task['assignees'] ?? [], 'user_id'));
        if (in_array($ctx->id, $assigneeIds, true)) return true;
        return GroupManagement::isGroupAdmin($ctx->id, (int)$task['group_id']);
    }

    private static function assertCanEditTask(?UserContext $ctx, array $task): UserContext {
        $ctx = self::assertLoggedIn($ctx);
        if (!self::canEditTask($ctx, $task)) {
            throw new RuntimeException('Only the task creator, its assignee, or a group admin can change this task.');
        }
        return $ctx;
    }

    private static function normalizeFields(int $groupId, array $data): array {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Title is required.');
        }

        $description = trim((string)($data['description'] ?? ''));
        $category = trim((string)($data['category'] ?? ''));

        $dueDate = trim((string)($data['due_date'] ?? ''));
        if ($dueDate !== '') {
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);
            if (!$d || $d->format('Y-m-d') !== $dueDate) {
                throw new InvalidArgumentException('Due date must be a valid date (YYYY-MM-DD).');
            }
        }

        // Any number of assignees; each must belong to the group.
        $assignedIds = [];
        foreach ((array)($data['assigned_user_ids'] ?? []) as $id) {
            if ($id === '' || $id === null) continue;
            $id = (int)$id;
            if ($id <= 0) continue;
            $assignedIds[$id] = true;
        }
        $assignedIds = array_keys($assignedIds);
        foreach ($assignedIds as $id) {
            if (!GroupManagement::isMember($id, $groupId)) {
                throw new InvalidArgumentException('The assignee must be a member of the group.');
            }
        }

        return [
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'category' => $category !== '' ? $category : null,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'assigned_user_ids' => $assignedIds,
        ];
    }

    // ===== Task CRUD =====

    // $data: title, description, category, due_date, assigned_user_ids
    // (array — a task may have any number of assignees), reminders (optional
    // array of days-in-advance ints; when omitted and the task has a due
    // date, a default reminder is added).
    public static function createTask(?UserContext $ctx, int $groupId, array $data): int {
        $ctx = self::assertMemberOfGroup($ctx, $groupId);
        $f = self::normalizeFields($groupId, $data);

        $st = self::pdo()->prepare(
            'INSERT INTO tasks (group_id, title, description, category, due_date, created_by_user_id)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$groupId, $f['title'], $f['description'], $f['category'], $f['due_date'], $ctx->id]);
        $taskId = (int)self::pdo()->lastInsertId();

        self::replaceAssignees($taskId, $f['assigned_user_ids']);

        if (array_key_exists('reminders', $data)) {
            self::replaceReminders($taskId, (array)$data['reminders']);
        } elseif ($f['due_date'] !== null) {
            self::replaceReminders($taskId, [self::DEFAULT_REMINDER_DAYS]);
        }

        self::log('task.create', $taskId, ['group_id' => $groupId, 'title' => $f['title']]);
        return $taskId;
    }

    public static function updateTask(?UserContext $ctx, int $taskId, array $data): bool {
        $task = self::getTask($taskId);
        if (!$task) {
            throw new InvalidArgumentException('Task not found.');
        }
        self::assertCanEditTask($ctx, $task);

        $f = self::normalizeFields((int)$task['group_id'], $data);

        $st = self::pdo()->prepare(
            'UPDATE tasks SET title=?, description=?, category=?, due_date=? WHERE id=?'
        );
        $ok = $st->execute([$f['title'], $f['description'], $f['category'], $f['due_date'], $taskId]);

        self::replaceAssignees($taskId, $f['assigned_user_ids']);

        if (array_key_exists('reminders', $data)) {
            self::replaceReminders($taskId, (array)$data['reminders']);
        }

        if ($ok) {
            self::log('task.update', $taskId, ['title' => $f['title']]);
        }
        return $ok;
    }

    public static function deleteTask(?UserContext $ctx, int $taskId): bool {
        $task = self::getTask($taskId);
        if (!$task) {
            throw new InvalidArgumentException('Task not found.');
        }
        $ctx = self::assertLoggedIn($ctx);
        $isCreator = (int)($task['created_by_user_id'] ?? 0) === $ctx->id;
        if (!$ctx->admin && !$isCreator && !GroupManagement::isGroupAdmin($ctx->id, (int)$task['group_id'])) {
            throw new RuntimeException('Only the task creator or a group admin can delete this task.');
        }

        // Delete attachment bytes before the cascade removes the comment rows.
        $st = self::pdo()->prepare('SELECT private_file_id FROM task_comments WHERE task_id=? AND private_file_id IS NOT NULL');
        $st->execute([$taskId]);
        foreach ($st->fetchAll() as $row) {
            Files::deletePrivateFile((int)$row['private_file_id']);
        }

        $ok = self::pdo()->prepare('DELETE FROM tasks WHERE id=?')->execute([$taskId]);

        if ($ok) {
            self::log('task.delete', $taskId, ['title' => $task['title']]);
        }
        return $ok;
    }

    public static function getTask(int $taskId): ?array {
        $st = self::pdo()->prepare(
            'SELECT t.*, g.name AS group_name,
                    c.first_name AS creator_first_name, c.last_name AS creator_last_name,
                    d.first_name AS completer_first_name, d.last_name AS completer_last_name
             FROM tasks t
             JOIN task_groups g ON g.id = t.group_id
             LEFT JOIN users c ON c.id = t.created_by_user_id
             LEFT JOIN users d ON d.id = t.completed_by_user_id
             WHERE t.id = ? LIMIT 1'
        );
        $st->execute([$taskId]);
        $row = $st->fetch();
        if (!$row) return null;
        return self::attachAssignees([$row])[0];
    }

    // $filters: search, assigned_to_user_id (matches tasks where that user is
    // ANY of the assignees), category, include_done (bool)
    public static function listTasks(int $groupId, array $filters = []): array {
        $sql = 'SELECT t.* FROM tasks t WHERE t.group_id = ?';
        $params = [$groupId];

        if (!empty($filters['search'])) {
            $sql .= ' AND (t.title LIKE ? OR t.description LIKE ? OR t.category LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term);
        }
        if (!empty($filters['assigned_to_user_id'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)';
            $params[] = (int)$filters['assigned_to_user_id'];
        }
        if (!empty($filters['category'])) {
            $sql .= ' AND t.category = ?';
            $params[] = $filters['category'];
        }
        if (empty($filters['include_done'])) {
            $sql .= ' AND t.is_done = 0';
        }

        $sql .= ' ORDER BY t.is_done, t.due_date IS NULL, t.due_date, t.title';

        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return self::attachAssignees($st->fetchAll());
    }

    // Distinct categories previously used in the group (for the form datalist).
    public static function listCategories(int $groupId): array {
        $st = self::pdo()->prepare(
            "SELECT DISTINCT category FROM tasks
             WHERE group_id = ? AND category IS NOT NULL AND category <> ''
             ORDER BY category"
        );
        $st->execute([$groupId]);
        return array_column($st->fetchAll(), 'category');
    }

    // ===== Completion =====

    public static function markComplete(?UserContext $ctx, int $taskId, ?string $completedOn = null, ?string $comment = null): void {
        $task = self::getTask($taskId);
        if (!$task) {
            throw new InvalidArgumentException('Task not found.');
        }
        $ctx = self::assertCanEditTask($ctx, $task);

        $completedOn = $completedOn ?: date('Y-m-d');
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $completedOn);
        if (!$d || $d->format('Y-m-d') !== $completedOn) {
            throw new InvalidArgumentException('Completion date must be a valid date (YYYY-MM-DD).');
        }

        self::pdo()->prepare('UPDATE tasks SET is_done=1, completion_date=?, completed_by_user_id=? WHERE id=?')
            ->execute([$completedOn, $ctx->id, $taskId]);

        // Record the completion in the task's history.
        $comment = $comment !== null ? trim($comment) : '';
        $st = self::pdo()->prepare(
            'INSERT INTO task_comments (task_id, created_by_user_id, comment, marked_complete) VALUES (?,?,?,1)'
        );
        $st->execute([$taskId, $ctx->id, $comment !== '' ? $comment : null]);

        self::log('task.complete', $taskId, ['completed_on' => $completedOn]);
    }

    public static function reopenTask(?UserContext $ctx, int $taskId): void {
        $task = self::getTask($taskId);
        if (!$task) {
            throw new InvalidArgumentException('Task not found.');
        }
        self::assertCanEditTask($ctx, $task);

        self::pdo()->prepare('UPDATE tasks SET is_done=0, completion_date=NULL, completed_by_user_id=NULL WHERE id=?')
            ->execute([$taskId]);

        self::log('task.reopen', $taskId);
    }

    // ===== Comments =====

    public static function addComment(?UserContext $ctx, int $taskId, ?string $comment, ?int $privateFileId = null, bool $markedComplete = false): int {
        $task = self::getTask($taskId);
        if (!$task) {
            throw new InvalidArgumentException('Task not found.');
        }
        $ctx = self::assertMemberOfGroup($ctx, (int)$task['group_id']);

        $comment = $comment !== null ? trim($comment) : '';
        if ($comment === '' && $privateFileId === null && !$markedComplete) {
            throw new InvalidArgumentException('An update needs a comment or an attachment.');
        }

        $st = self::pdo()->prepare(
            'INSERT INTO task_comments (task_id, created_by_user_id, comment, private_file_id, marked_complete)
             VALUES (?,?,?,?,?)'
        );
        $st->execute([$taskId, $ctx->id, $comment !== '' ? $comment : null, $privateFileId, $markedComplete ? 1 : 0]);
        $id = (int)self::pdo()->lastInsertId();

        self::log('task.comment', $taskId, [
            'comment_id' => $id,
            'has_attachment' => $privateFileId !== null,
        ]);
        return $id;
    }

    public static function getComment(int $commentId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM task_comments WHERE id=? LIMIT 1');
        $st->execute([$commentId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function deleteComment(?UserContext $ctx, int $commentId): bool {
        $comment = self::getComment($commentId);
        if (!$comment) {
            throw new InvalidArgumentException('Comment not found.');
        }
        $task = self::getTask((int)$comment['task_id']);
        $ctx = self::assertLoggedIn($ctx);

        $isAuthor = (int)($comment['created_by_user_id'] ?? 0) === $ctx->id;
        if (!$ctx->admin && !$isAuthor && !GroupManagement::isGroupAdmin($ctx->id, (int)$task['group_id'])) {
            throw new RuntimeException('Only the comment author or a group admin can delete a comment.');
        }

        if (!empty($comment['private_file_id'])) {
            Files::deletePrivateFile((int)$comment['private_file_id']);
        }

        $ok = self::pdo()->prepare('DELETE FROM task_comments WHERE id=?')->execute([$commentId]);
        if ($ok) {
            self::log('task.comment_delete', (int)$comment['task_id'], ['comment_id' => $commentId]);
        }
        return $ok;
    }

    // Newest-first history of comments/updates on a task.
    public static function listComments(int $taskId): array {
        $st = self::pdo()->prepare(
            'SELECT tc.*, u.first_name, u.last_name,
                    pf.original_filename AS attachment_filename, pf.byte_length AS attachment_bytes
             FROM task_comments tc
             LEFT JOIN users u ON u.id = tc.created_by_user_id
             LEFT JOIN private_files pf ON pf.id = tc.private_file_id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at DESC, tc.id DESC'
        );
        $st->execute([$taskId]);
        return $st->fetchAll();
    }

    public static function storeUploadedAttachment(?UserContext $ctx, array $file): int {
        $ctx = self::assertLoggedIn($ctx);
        return Files::storeUploadedPrivateFile($ctx->id, $file, self::ATTACHMENT_MAX_BYTES, self::ATTACHMENT_MIME_TYPES);
    }

    // ===== Assignees =====

    // Assignee rows for many tasks at once:
    // [task_id => [['user_id','first_name','last_name','email'], ...]]
    public static function assigneesByTask(array $taskIds): array {
        if (!$taskIds) return [];
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        $st = self::pdo()->prepare(
            "SELECT ta.task_id, u.id AS user_id, u.first_name, u.last_name, u.email
             FROM task_assignees ta
             JOIN users u ON u.id = ta.user_id
             WHERE ta.task_id IN ($in)
             ORDER BY u.first_name, u.last_name"
        );
        $st->execute(array_map('intval', $taskIds));
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['task_id']][] = [
                'user_id' => (int)$row['user_id'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
            ];
        }
        return $out;
    }

    // Attach an 'assignees' array to each task row (one bulk query).
    private static function attachAssignees(array $rows): array {
        $byTask = self::assigneesByTask(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['assignees'] = $byTask[(int)$row['id']] ?? [];
        }
        return $rows;
    }

    private static function replaceAssignees(int $taskId, array $userIds): void {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$taskId]);
        if (!$userIds) return;
        $st = $pdo->prepare('INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES (?,?)');
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            $st->execute([$taskId, $uid]);
        }
    }

    // ===== Reminders =====

    public static function setReminders(?UserContext $ctx, int $taskId, array $daysList): void {
        $task = self::getTask($taskId);
        if (!$task) {
            throw new InvalidArgumentException('Task not found.');
        }
        self::assertCanEditTask($ctx, $task);
        self::replaceReminders($taskId, $daysList);
        self::log('task.reminders_set', $taskId, ['days' => array_values($daysList)]);
    }

    private static function replaceReminders(int $taskId, array $daysList): void {
        $days = [];
        foreach ($daysList as $d) {
            if ($d === '' || $d === null) continue;
            $n = (int)$d;
            if ($n < 0) {
                throw new InvalidArgumentException('Reminder days in advance cannot be negative.');
            }
            $days[$n] = true;
        }

        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM task_reminders WHERE task_id=?')->execute([$taskId]);
        $st = $pdo->prepare('INSERT INTO task_reminders (task_id, days_in_advance) VALUES (?,?)');
        foreach (array_keys($days) as $n) {
            $st->execute([$taskId, $n]);
        }
    }

    public static function listReminders(int $taskId): array {
        $st = self::pdo()->prepare('SELECT * FROM task_reminders WHERE task_id=? ORDER BY days_in_advance');
        $st->execute([$taskId]);
        return $st->fetchAll();
    }

    // Days-in-advance lists for many tasks at once: [task_id => [days, ...]]
    public static function remindersByTask(array $taskIds): array {
        if (!$taskIds) return [];
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        $st = self::pdo()->prepare("SELECT task_id, days_in_advance FROM task_reminders WHERE task_id IN ($in) ORDER BY days_in_advance");
        $st->execute(array_map('intval', $taskIds));
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['task_id']][] = (int)$row['days_in_advance'];
        }
        return $out;
    }

    // ===== Pure grouping helpers for the index views (no DB access) =====

    // Buckets open tasks by the Monday of their due week. Returns an ordered
    // array of ['label' => ..., 'tasks' => [...]]: "Overdue" first, then
    // chronological weeks ("This Week", "Week of Mon Jul 20", ...), then
    // "No due date", then (if any) "Completed".
    public static function groupTasksByWeek(array $tasks, string $today): array {
        $todayTs = strtotime($today);
        $thisMonday = strtotime('monday this week', $todayTs);

        $overdue = [];
        $weeks = [];
        $noDue = [];
        $done = [];

        foreach ($tasks as $t) {
            if (!empty($t['is_done'])) {
                $done[] = $t;
                continue;
            }
            if (empty($t['due_date'])) {
                $noDue[] = $t;
                continue;
            }
            $dueTs = strtotime((string)$t['due_date']);
            if ($dueTs < $todayTs && $t['due_date'] < $today) {
                $overdue[] = $t;
                continue;
            }
            $monday = strtotime('monday this week', $dueTs);
            $key = date('Y-m-d', $monday);
            $weeks[$key][] = $t;
        }

        ksort($weeks);

        $groups = [];
        if ($overdue) {
            $groups[] = ['label' => 'Overdue', 'tasks' => $overdue];
        }
        foreach ($weeks as $mondayKey => $rows) {
            $mondayTs = strtotime($mondayKey);
            if ($mondayTs == $thisMonday) {
                $label = 'This Week';
            } else {
                $label = 'Week of ' . date('D M j', $mondayTs);
            }
            $groups[] = ['label' => $label, 'tasks' => $rows];
        }
        if ($noDue) {
            $groups[] = ['label' => 'No due date', 'tasks' => $noDue];
        }
        if ($done) {
            usort($done, fn($a, $b) => strcmp((string)($b['completion_date'] ?? ''), (string)($a['completion_date'] ?? '')));
            $groups[] = ['label' => 'Completed', 'tasks' => $done];
        }
        return $groups;
    }

    // Buckets tasks by category, alphabetical with "Uncategorized" last (and
    // "Completed" after that when done tasks are included).
    public static function groupTasksByCategory(array $tasks): array {
        $byCategory = [];
        $uncategorized = [];
        $done = [];

        foreach ($tasks as $t) {
            if (!empty($t['is_done'])) {
                $done[] = $t;
            } elseif (!empty($t['category'])) {
                $byCategory[(string)$t['category']][] = $t;
            } else {
                $uncategorized[] = $t;
            }
        }

        uksort($byCategory, 'strnatcasecmp');

        $groups = [];
        foreach ($byCategory as $category => $rows) {
            $groups[] = ['label' => $category, 'tasks' => $rows];
        }
        if ($uncategorized) {
            $groups[] = ['label' => 'Uncategorized', 'tasks' => $uncategorized];
        }
        if ($done) {
            $groups[] = ['label' => 'Completed', 'tasks' => $done];
        }
        return $groups;
    }

    // Buckets tasks by assignee, alphabetical with "Unassigned" last (and
    // "Completed" after that when done tasks are included). Tasks carry an
    // 'assignees' array; a task with several assignees appears under EACH
    // of their buckets.
    public static function groupTasksByOwner(array $tasks): array {
        $byOwner = [];
        $unassigned = [];
        $done = [];

        foreach ($tasks as $t) {
            if (!empty($t['is_done'])) {
                $done[] = $t;
                continue;
            }
            $names = [];
            foreach ($t['assignees'] ?? [] as $a) {
                $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
                if ($name !== '') $names[$name] = true;
            }
            if ($names) {
                foreach (array_keys($names) as $name) {
                    $byOwner[$name][] = $t;
                }
            } else {
                $unassigned[] = $t;
            }
        }

        uksort($byOwner, 'strnatcasecmp');

        $groups = [];
        foreach ($byOwner as $name => $rows) {
            $groups[] = ['label' => $name, 'tasks' => $rows];
        }
        if ($unassigned) {
            $groups[] = ['label' => 'Unassigned', 'tasks' => $unassigned];
        }
        if ($done) {
            usort($done, fn($a, $b) => strcmp((string)($b['completion_date'] ?? ''), (string)($a['completion_date'] ?? '')));
            $groups[] = ['label' => 'Completed', 'tasks' => $done];
        }
        return $groups;
    }
}
