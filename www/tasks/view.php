<?php
// Task detail: board-style header + property card, instructions, update
// composer (comment / attachment / mark complete), and the update feed.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
Application::init();
require_login();

$ctx = UserContext::getLoggedInUserContext();

$taskId = (int)($_GET['id'] ?? 0);
$task = $taskId ? TaskManagement::getTask($taskId) : null;
if (!$task) {
    http_response_code(404);
    die('Task not found');
}
TaskManagement::assertCanViewTask($ctx, $task);

$groupId = (int)$task['group_id'];
$reminders = TaskManagement::listReminders($taskId);
$comments = TaskManagement::listComments($taskId);
$today = date('Y-m-d');
$isDone = !empty($task['is_done']);

$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['form_data']);

$creator = trim(($task['creator_first_name'] ?? '') . ' ' . ($task['creator_last_name'] ?? ''));
$completer = trim(($task['completer_first_name'] ?? '') . ' ' . ($task['completer_last_name'] ?? ''));

// Status pill + the card's rail color, matching the task list's palette.
$due = $task['due_date'] ?? null;
$days = $due ? (int)round((strtotime($due) - strtotime($today)) / 86400) : null;
if ($isDone) {
    $when = $task['completion_date'] ? ' ' . date('M j', strtotime($task['completion_date'])) : '';
    $statusPill = '<span class="pill pill-done">✓ Done' . h($when) . '</span>';
    $railColor = 'var(--board-green)';
} elseif ($due === null) {
    $statusPill = '<span class="pill pill-none">No due date</span>';
    $railColor = 'var(--board-gray)';
} elseif ($days < 0) {
    $statusPill = '<span class="pill pill-overdue">' . h(date('M j', strtotime($due))) . ' · ' . (-$days) . 'd overdue</span>';
    $railColor = 'var(--board-red)';
} elseif ($days === 0) {
    $statusPill = '<span class="pill pill-today">Due today</span>';
    $railColor = 'var(--board-orange)';
} else {
    $statusPill = '<span class="pill ' . ($days <= 30 ? 'pill-soon' : 'pill-later') . '">Due ' . h(date('M j', strtotime($due))) . '</span>';
    $railColor = 'var(--board-blue)';
}

header_html($task['title']);
?>
<a class="back-link" href="/tasks/index.php?group_id=<?=$groupId?>">← <?=h($task['group_name'])?></a>

<div class="page-head task-head">
  <h1><?=h($task['title'])?></h1>
  <div class="actions">
    <?php if ($isDone): ?>
      <form method="post" action="/tasks/reopen_eval.php" style="display:inline">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="task_id" value="<?=$taskId?>">
        <input type="hidden" name="return" value="/tasks/view.php?id=<?=$taskId?>">
        <button class="button" type="submit" data-confirm="Mark this task as incomplete? It will go back to the open tasks.">Mark as incomplete</button>
      </form>
    <?php else: ?>
      <form method="post" action="/tasks/complete_eval.php" style="display:inline">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="task_id" value="<?=$taskId?>">
        <input type="hidden" name="return" value="/tasks/view.php?id=<?=$taskId?>">
        <button class="button primary" type="submit">Mark as complete</button>
      </form>
    <?php endif; ?>
    <a class="button" href="/tasks/edit.php?id=<?=$taskId?>">Edit</a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="task-props" style="--group-color: <?=h($railColor)?>">
  <div class="task-prop">
    <div class="task-prop-label">Status</div>
    <div><?=$statusPill?></div>
    <?php if ($isDone && $completer): ?><div class="small" style="margin-top:4px;">by <?=h($completer)?></div><?php endif; ?>
  </div>
  <div class="task-prop">
    <div class="task-prop-label">Owner</div>
    <div><?=person_chip_html($task['assignee_first_name'] ?? '', $task['assignee_last_name'] ?? '')?></div>
  </div>
  <div class="task-prop">
    <div class="task-prop-label">Reminders</div>
    <div class="task-prop-value">
      <?php if ($reminders): ?>
        <?=h(implode(', ', array_map(fn($r) => $r['days_in_advance'] . ' day' . ((int)$r['days_in_advance'] === 1 ? '' : 's') . ' before', $reminders)))?>
      <?php else: ?><span class="small">None</span><?php endif; ?>
    </div>
  </div>
  <div class="task-prop">
    <div class="task-prop-label">Created by</div>
    <div><?=person_chip_html($task['creator_first_name'] ?? '', $task['creator_last_name'] ?? '', '—')?></div>
  </div>
</div>

<?php if (!empty($task['description'])): ?>
<div class="card task-instructions">
  <h3>Instructions</h3>
  <p><?=nl2br(h($task['description']))?></p>
</div>
<?php endif; ?>

<div class="card">
  <h3>Add an Update</h3>
  <form method="post" action="/tasks/comment_add_eval.php" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="task_id" value="<?=$taskId?>">
    <textarea name="comment" rows="3" placeholder="Write an update — progress note, question, or result…"><?=h($form['comment'] ?? '')?></textarea>
    <label class="inline update-attach">📎 Attach a file
      <input type="file" name="attachment">
    </label>
    <?php if (!$isDone): ?>
      <label class="inline"><input type="checkbox" name="mark_complete" value="1" id="markCompleteBox"> Mark this task <span class="prompt-em">complete</span></label>
      <label id="completedOnRow" style="display:none;">Completed on
        <input type="date" name="completed_on" value="<?=h($today)?>">
      </label>
      <script>
      document.addEventListener('DOMContentLoaded', function () {
        var box = document.getElementById('markCompleteBox');
        var row = document.getElementById('completedOnRow');
        function sync() { row.style.display = box.checked ? '' : 'none'; }
        box.addEventListener('change', sync);
        sync();
      });
      </script>
    <?php endif; ?>
    <div class="actions">
      <button class="primary" type="submit">Post Update</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>History</h3>
  <?php if (!$comments): ?>
    <p class="small">No updates yet.</p>
  <?php else: ?>
    <?php foreach ($comments as $c): ?>
      <?php $author = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: 'Someone'; ?>
      <div class="update">
        <span class="assignee-avatar" style="background:<?=h(person_avatar_color($author))?>"><?=h(strtoupper(mb_substr($c['first_name'] ?? mb_substr($author, 0, 1), 0, 1) . mb_substr($c['last_name'] ?? '', 0, 1)))?></span>
        <div class="update-body">
          <div class="update-head">
            <strong><?=h($author)?></strong>
            <span class="small"><?=h(date('M j, g:ia', strtotime($c['created_at'])))?></span>
            <?php if (!empty($c['marked_complete'])): ?><span class="badge success">✓ Marked complete</span><?php endif; ?>
            <form method="post" action="/tasks/comment_remove_eval.php" class="update-delete">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="comment_id" value="<?=(int)$c['id']?>">
              <button class="link-button" type="submit" data-confirm="Delete this update?">Delete</button>
            </form>
          </div>
          <?php if (!empty($c['comment'])): ?><p><?=nl2br(h($c['comment']))?></p><?php endif; ?>
          <?php if (!empty($c['private_file_id'])): ?>
            <p><a class="attachment-chip" href="/tasks/attachment.php?comment_id=<?=(int)$c['id']?>">📎 <?=h($c['attachment_filename'] ?? 'Attachment')?></a></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
