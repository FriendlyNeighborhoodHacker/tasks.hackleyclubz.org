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
$canManage = GroupManagement::canManageGroup($ctx, $groupId);

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
    <div class="task-prop-label"><?=count($task['assignees'] ?? []) > 1 ? 'Owners' : 'Owner'?></div>
    <div><?=person_chips_html($task['assignees'] ?? [])?></div>
  </div>
  <div class="task-prop">
    <div class="task-prop-label">Reminders
      <?php if ($canManage): ?><button type="button" class="prop-edit-link" id="remindersEditBtn">Edit</button><?php endif; ?>
    </div>
    <div class="task-prop-value" id="remindersText">
      <?php if ($reminders): ?>
        <?=h(implode(', ', array_map(fn($r) => $r['days_in_advance'] . ' day' . ((int)$r['days_in_advance'] === 1 ? '' : 's') . ' before', $reminders)))?>
      <?php else: ?><span class="small">None</span><?php endif; ?>
    </div>
    <?php if ($canManage): ?>
    <form method="post" action="/tasks/reminders_eval.php" class="reminders-form hidden" id="remindersForm">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="task_id" value="<?=$taskId?>">
      <div id="viewReminderRows">
        <?php foreach ($reminders as $r): ?>
          <div class="reminder-row">
            <input type="number" name="reminder_days[]" min="0" step="1" value="<?=h((string)$r['days_in_advance'])?>"> days before
            <button type="button" class="button remove-reminder">Remove</button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="reminder-actions">
        <button type="button" class="button" id="viewAddReminder">+ Add reminder</button>
        <button class="button primary" type="submit">Save</button>
      </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var btn = document.getElementById('remindersEditBtn');
      var form = document.getElementById('remindersForm');
      btn.addEventListener('click', function () {
        var formHidden = form.classList.toggle('hidden');
        document.getElementById('remindersText').classList.toggle('hidden', !formHidden);
        btn.textContent = formHidden ? 'Edit' : 'Cancel';
      });
      document.getElementById('viewAddReminder').addEventListener('click', function () {
        var div = document.createElement('div');
        div.className = 'reminder-row';
        div.innerHTML = '<input type="number" name="reminder_days[]" min="0" step="1" value="1"> days before ' +
                        '<button type="button" class="button remove-reminder">Remove</button>';
        document.getElementById('viewReminderRows').appendChild(div);
        div.querySelector('input').focus();
      });
      form.addEventListener('click', function (e) {
        if (e.target && e.target.classList.contains('remove-reminder')) {
          e.target.closest('.reminder-row').remove();
        }
      });
    });
    </script>
    <?php endif; ?>
  </div>
  <div class="task-prop">
    <div class="task-prop-label">Created by</div>
    <div><?=person_chip_html($task['creator_first_name'] ?? '', $task['creator_last_name'] ?? '', '—')?></div>
  </div>
</div>

<?php if (!empty($task['description']) || $canManage): ?>
<div class="card task-instructions">
  <div class="card-head-row">
    <h3>Instructions</h3>
    <?php if ($canManage): ?>
      <button type="button" class="edit-btn" id="instructionsEditBtn">✏️ Edit</button>
    <?php endif; ?>
  </div>
  <p id="instructionsText"><?php if (!empty($task['description'])): ?><?=nl2br(h($task['description']))?><?php else: ?><span class="small">No instructions yet.</span><?php endif; ?></p>
  <?php if ($canManage): ?>
    <form method="post" action="/tasks/instructions_eval.php" class="stack hidden" id="instructionsForm">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="task_id" value="<?=$taskId?>">
      <textarea name="description" rows="5" placeholder="How to complete this task…"><?=h($task['description'] ?? '')?></textarea>
      <div class="actions">
        <button class="primary" type="submit">Save Instructions</button>
        <button class="button" type="button" id="instructionsCancelBtn">Cancel</button>
      </div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var text = document.getElementById('instructionsText');
      var form = document.getElementById('instructionsForm');
      var editBtn = document.getElementById('instructionsEditBtn');
      function toggle(editing) {
        form.classList.toggle('hidden', !editing);
        text.classList.toggle('hidden', editing);
        editBtn.classList.toggle('hidden', editing);
        if (editing) form.querySelector('textarea').focus();
      }
      editBtn.addEventListener('click', function () { toggle(true); });
      document.getElementById('instructionsCancelBtn').addEventListener('click', function () { toggle(false); });
    });
    </script>
  <?php endif; ?>
</div>
<?php endif; ?>

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
