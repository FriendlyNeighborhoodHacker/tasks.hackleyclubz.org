<?php
// Task detail: fields, reminders, update form (comment / attachment / mark
// complete), and the comment history.
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

$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['form_data']);

$assignee = trim(($task['assignee_first_name'] ?? '') . ' ' . ($task['assignee_last_name'] ?? ''));
$creator = trim(($task['creator_first_name'] ?? '') . ' ' . ($task['creator_last_name'] ?? ''));

header_html($task['title']);
?>
<div class="page-head">
  <h1><?=h($task['title'])?></h1>
  <div class="actions">
    <a class="button" href="/tasks/edit.php?id=<?=$taskId?>">Edit</a>
    <a class="button" href="/tasks/index.php?group_id=<?=$groupId?>">Back to <?=h($task['group_name'])?></a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?php if (!empty($task['is_done'])): ?>
    <p class="flash">Completed <?=h($task['completion_date'] ? date('M j, Y', strtotime($task['completion_date'])) : '')?>
      <?php $completer = trim(($task['completer_first_name'] ?? '') . ' ' . ($task['completer_last_name'] ?? '')); ?>
      <?php if ($completer): ?>by <?=h($completer)?><?php endif; ?>
    </p>
  <?php else: ?>
    <p><?=task_due_html($task['due_date'] ?? null, $today)?></p>
  <?php endif; ?>

  <table class="list">
    <tr><th>Group</th><td><?=h($task['group_name'])?></td></tr>
    <tr><th>Assigned to</th><td><?=h($assignee ?: '—')?></td></tr>
    <tr><th>Category</th><td><?=h($task['category'] ?? '—')?></td></tr>
    <tr><th>Created by</th><td><?=h($creator ?: '—')?></td></tr>
    <tr><th>Reminders</th><td>
      <?php if ($reminders): ?>
        <?=h(implode(', ', array_map(fn($r) => $r['days_in_advance'] . ' day' . ((int)$r['days_in_advance'] === 1 ? '' : 's') . ' before', $reminders)))?>
      <?php else: ?>—<?php endif; ?>
    </td></tr>
  </table>

  <?php if (!empty($task['description'])): ?>
    <h3>Instructions</h3>
    <p><?=nl2br(h($task['description']))?></p>
  <?php endif; ?>

  <?php if (!empty($task['is_done'])): ?>
    <form method="post" action="/tasks/reopen_eval.php">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="task_id" value="<?=$taskId?>">
      <button class="button" type="submit">Reopen Task</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Add an Update</h3>
  <form method="post" action="/tasks/comment_add_eval.php" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="task_id" value="<?=$taskId?>">
    <label>Comment
      <textarea name="comment" rows="3" placeholder="Progress note, question, or result…"><?=h($form['comment'] ?? '')?></textarea>
    </label>
    <label>Attachment (optional)
      <input type="file" name="attachment">
    </label>
    <?php if (empty($task['is_done'])): ?>
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
      <div class="comment">
        <div class="small">
          <strong><?=h(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?: 'Someone')?></strong>
          — <?=h(date('M j, Y g:ia', strtotime($c['created_at'])))?>
          <?php if (!empty($c['marked_complete'])): ?><span class="badge">Marked complete</span><?php endif; ?>
        </div>
        <?php if (!empty($c['comment'])): ?><p><?=nl2br(h($c['comment']))?></p><?php endif; ?>
        <?php if (!empty($c['private_file_id'])): ?>
          <p><a href="/tasks/attachment.php?comment_id=<?=(int)$c['id']?>">📎 <?=h($c['attachment_filename'] ?? 'Attachment')?></a></p>
        <?php endif; ?>
        <form method="post" action="/tasks/comment_remove_eval.php" style="display:inline">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="comment_id" value="<?=(int)$c['id']?>">
          <button class="button small-button" type="submit" data-confirm="Delete this update?">Delete</button>
        </form>
      </div>
      <hr>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
