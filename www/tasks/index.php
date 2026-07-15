<?php
// A group's tasks: by-week view (default) with an alternate by-category view,
// plus an "only my tasks" toggle. Group admins default to all tasks; regular
// members default to just their own (see docs/app-spec.md).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/TaskManagement.php';
Application::init();
require_login();

$ctx = UserContext::getLoggedInUserContext();

$groupId = (int)($_GET['group_id'] ?? 0);
if (!$groupId) {
    $groupId = (int)(GroupManagement::resolveCurrentGroupId($ctx) ?? 0);
    if (!$groupId) {
        header('Location: /index.php');
        exit;
    }
}

$group = GroupManagement::getGroup($groupId);
if (!$group || !GroupManagement::canViewGroup($ctx, $groupId)) {
    http_response_code(403);
    die('You are not a member of this group.');
}

// Visiting a group makes it the current one (first slot in the sidebar).
if (GroupManagement::isMember($ctx->id, $groupId)) {
    GroupManagement::setCurrentGroup($ctx, $groupId);
}

$isGroupAdmin = $ctx->admin || GroupManagement::isGroupAdmin($ctx->id, $groupId);

$view = ($_GET['view'] ?? 'week') === 'category' ? 'category' : 'week';
$mine = isset($_GET['mine']) ? !empty($_GET['mine']) : !$isGroupAdmin;
$showDone = !empty($_GET['show_done']);
$search = trim((string)($_GET['q'] ?? ''));

$filters = ['include_done' => $showDone];
if ($search !== '') $filters['search'] = $search;
if ($mine) $filters['assigned_to_user_id'] = $ctx->id;

$tasks = TaskManagement::listTasks($groupId, $filters);
$today = date('Y-m-d');
$groups = $view === 'category'
    ? TaskManagement::groupTasksByCategory($tasks)
    : TaskManagement::groupTasksByWeek($tasks, $today);

$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

function tasks_view_url(int $groupId, string $view, bool $mine, bool $showDone, string $search): string {
    $params = ['group_id' => $groupId, 'view' => $view, 'mine' => $mine ? 1 : 0];
    if ($showDone) $params['show_done'] = 1;
    if ($search !== '') $params['q'] = $search;
    return '/tasks/index.php?' . http_build_query($params);
}

function tasks_table(array $rows, string $today, bool $showCategory): void {
    ?>
    <table class="list">
      <thead><tr><th>Task</th><th>Assigned to</th><?php if ($showCategory): ?><th>Category</th><?php endif; ?><th>Due</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $t): ?>
        <tr>
          <td><a href="/tasks/view.php?id=<?=(int)$t['id']?>"><?=h($t['title'])?></a></td>
          <td><?=h(trim(($t['assignee_first_name'] ?? '') . ' ' . ($t['assignee_last_name'] ?? '')) ?: '—')?></td>
          <?php if ($showCategory): ?><td><?=h($t['category'] ?? '')?></td><?php endif; ?>
          <td>
            <?php if (!empty($t['is_done'])): ?>
              <span class="small">Completed <?=h($t['completion_date'] ? date('M j, Y', strtotime($t['completion_date'])) : '')?></span>
            <?php else: ?>
              <?=task_due_html($t['due_date'] ?? null, $today)?>
            <?php endif; ?>
          </td>
          <td class="actions">
            <?php if (empty($t['is_done'])): ?>
            <form method="post" action="/tasks/complete_eval.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="task_id" value="<?=(int)$t['id']?>">
              <input type="hidden" name="return" value="<?=h($_SERVER['REQUEST_URI'] ?? '/tasks/index.php')?>">
              <button class="button" type="submit">Done</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

header_html($group['name']);
?>
<div class="page-head">
  <h1><?=h($group['name'])?></h1>
  <div class="actions">
    <a class="button primary" href="/tasks/add.php?group_id=<?=$groupId?>">Add Task</a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="toolbar">
  <div class="view-toggle">
    <a class="button <?=$view==='week'?'primary':''?>" href="<?=h(tasks_view_url($groupId, 'week', $mine, $showDone, $search))?>">By Week</a>
    <a class="button <?=$view==='category'?'primary':''?>" href="<?=h(tasks_view_url($groupId, 'category', $mine, $showDone, $search))?>">By Category</a>
  </div>
  <div class="view-toggle">
    <a class="button <?=$mine?'primary':''?>" href="<?=h(tasks_view_url($groupId, $view, !$mine, $showDone, $search))?>"><?=$mine ? 'Show all tasks' : 'Only my tasks'?></a>
    <a class="button" href="<?=h(tasks_view_url($groupId, $view, $mine, !$showDone, $search))?>"><?=$showDone ? 'Hide completed' : 'Show completed'?></a>
  </div>
  <form method="get" action="/tasks/index.php" data-auto-submit>
    <input type="hidden" name="group_id" value="<?=$groupId?>">
    <input type="hidden" name="view" value="<?=h($view)?>">
    <input type="hidden" name="mine" value="<?=$mine?1:0?>">
    <?php if ($showDone): ?><input type="hidden" name="show_done" value="1"><?php endif; ?>
    <input type="search" name="q" value="<?=h($search)?>" placeholder="Search tasks…">
  </form>
</div>

<?php if (!$groups): ?>
  <div class="card">
    <p>All caught up 🎉<?php if ($mine): ?> — no tasks assigned to you.<?php endif; ?></p>
  </div>
<?php else: ?>
  <?php foreach ($groups as $section): ?>
    <div class="card">
      <h3><?=h($section['label'])?></h3>
      <?php tasks_table($section['tasks'], $today, $view !== 'category'); ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php footer_html(); ?>
