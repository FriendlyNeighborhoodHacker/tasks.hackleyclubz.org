<?php
// Token-authenticated task page (linked from notification emails). Shows the
// task with its history and lets the recipient add a comment or mark it
// complete — nothing else. No sidebar/menu; the token threads through every
// action in this flow and grants no access outside /t/.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../lib/Application.php';
require_once __DIR__ . '/../lib/ApplicationUI.php';
require_once __DIR__ . '/../lib/TaskAccessTokens.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../partials.php';
Application::init();

$rawToken = (string)($_GET['token'] ?? '');
$auth = TaskAccessTokens::verify($rawToken);

if (!$auth) {
    ApplicationUI::minimalHeaderHtml('Link Expired');
    ?>
    <div class="card">
      <h2>This link is no longer valid</h2>
      <p>The link may have expired. <span class="prompt-em">Sign in</span> to see your tasks, or use the forgot-password page to set up your account.</p>
      <div class="actions">
        <a class="button primary" href="/login.php">Sign In</a>
        <a class="button" href="/forgot_password.php">Forgot / set password</a>
      </div>
    </div>
    <?php
    ApplicationUI::minimalFooterHtml();
    exit;
}

$task = TaskManagement::getTask($auth['task_id']);
$viewer = UserManagement::findById($auth['user_id']);
if (!$task || !$viewer) {
    http_response_code(404);
    die('Task not found');
}

$comments = TaskManagement::listComments((int)$task['id']);
$today = date('Y-m-d');

$msg = $_SESSION['t_success'] ?? null;
$err = $_SESSION['t_error'] ?? null;
unset($_SESSION['t_success'], $_SESSION['t_error']);

$assignee = implode(', ', array_filter(array_map(
    fn($a) => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')),
    $task['assignees'] ?? []
)));

ApplicationUI::minimalHeaderHtml($task['title']);
?>
<p class="small">Hello <?=h($viewer['first_name'])?> — you can update this task below.</p>

<div class="card">
  <h2><?=h($task['title'])?></h2>
  <?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
  <?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

  <?php if (!empty($task['is_done'])): ?>
    <p class="flash">Completed <?=h($task['completion_date'] ? date('M j, Y', strtotime($task['completion_date'])) : '')?></p>
  <?php else: ?>
    <p><?=task_due_html($task['due_date'] ?? null, $today)?></p>
  <?php endif; ?>

  <table class="list">
    <tr><th>Group</th><td><?=h($task['group_name'])?></td></tr>
    <tr><th>Assigned to</th><td><?=h($assignee ?: '—')?></td></tr>
  </table>

  <?php if (!empty($task['description'])): ?>
    <h3>Instructions</h3>
    <p><?=nl2br(h($task['description']))?></p>
  <?php endif; ?>
</div>

<?php if (empty($task['is_done'])): ?>
<div class="card">
  <form method="post" action="/t/complete_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="token" value="<?=h($rawToken)?>">
    <input type="hidden" name="task_id" value="<?=(int)$task['id']?>">
    <p><span class="prompt-em">All done?</span> Mark the task complete.</p>
    <button class="primary" type="submit">Mark Complete</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Add a Comment</h3>
  <form method="post" action="/t/comment_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="token" value="<?=h($rawToken)?>">
    <input type="hidden" name="task_id" value="<?=(int)$task['id']?>">
    <label>Comment
      <textarea name="comment" rows="3" required></textarea>
    </label>
    <div class="actions">
      <button class="primary" type="submit">Post Comment</button>
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
        <?php if (!empty($c['private_file_id'])): ?><p class="small">📎 <?=h($c['attachment_filename'] ?? 'Attachment')?> (sign in to download)</p><?php endif; ?>
      </div>
      <hr>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php ApplicationUI::minimalFooterHtml(); ?>
