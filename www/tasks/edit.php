<?php
// Edit a task — form. Evaluates to tasks/edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/form_fields.php';
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

// One-shot flash + form repopulation from edit_eval.php on error
$err = $_SESSION['error'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

if ($form) {
    $values = $form;
    $values['reminder_days'] = $form['reminder_days'] ?? [];
} else {
    $values = [
        'title' => $task['title'],
        'description' => $task['description'] ?? '',
        'category' => $task['category'] ?? '',
        'due_date' => $task['due_date'] ?? '',
        'assigned_to_user_id' => $task['assigned_to_user_id'] ?? '',
        'reminder_days' => array_column(TaskManagement::listReminders($taskId), 'days_in_advance'),
    ];
}

$opts = [
    'members' => GroupManagement::listMembers($groupId),
    'categories' => TaskManagement::listCategories($groupId),
    'can_add_person' => GroupManagement::canManageGroup($ctx, $groupId),
];

header_html('Edit Task - ' . $task['title']);
?>
<h2>Edit Task</h2>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/tasks/edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="task_id" value="<?=$taskId?>">
    <?php render_task_form_fields($values, $opts); ?>
    <div class="actions">
      <button class="primary" type="submit">Save Task</button>
      <a class="button" href="/tasks/view.php?id=<?=$taskId?>">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Danger Zone</h3>
  <form method="post" action="/tasks/remove_eval.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="task_id" value="<?=$taskId?>">
    <button class="button danger" type="submit" data-confirm="Delete this task and its history? This cannot be undone.">Delete Task</button>
  </form>
</div>

<?php footer_html(); ?>
