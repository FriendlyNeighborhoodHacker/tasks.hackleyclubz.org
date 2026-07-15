<?php
// Add a task — form. Evaluates to tasks/add_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
require_once __DIR__ . '/form_fields.php';
Application::init();
require_login();

$ctx = UserContext::getLoggedInUserContext();

$groupId = (int)($_GET['group_id'] ?? 0);
if (!$groupId) {
    $groupId = (int)(GroupManagement::resolveCurrentGroupId($ctx) ?? 0);
}
$group = $groupId ? GroupManagement::getGroup($groupId) : null;
if (!$group || !GroupManagement::canViewGroup($ctx, $groupId)) {
    http_response_code(403);
    die('You are not a member of this group.');
}

// One-shot flash + form repopulation from add_eval.php on error
$err = $_SESSION['error'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

$values = $form + [
    'reminder_days' => [TaskManagement::DEFAULT_REMINDER_DAYS],
];
if (!empty($form) && !isset($form['reminder_days'])) {
    $values['reminder_days'] = $form['reminder_days'] ?? [];
}

$opts = [
    'members' => GroupManagement::listMembers($groupId),
    'categories' => TaskManagement::listCategories($groupId),
    'can_add_person' => GroupManagement::canManageGroup($ctx, $groupId),
];

header_html('Add Task - ' . $group['name']);
?>
<h2>Add Task to <?=h($group['name'])?></h2>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/tasks/add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <?php render_task_form_fields($values, $opts); ?>
    <div class="actions">
      <button class="primary" type="submit">Create Task</button>
      <a class="button" href="/tasks/index.php?group_id=<?=$groupId?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
