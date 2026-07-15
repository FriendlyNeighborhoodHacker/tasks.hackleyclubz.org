<?php
// Homepage: resolve the user's current group and show its tasks. Users with no
// groups yet are invited to create their first one.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/GroupManagement.php';

Application::init();
require_login();

$ctx = UserContext::getLoggedInUserContext();
$currentGroupId = GroupManagement::resolveCurrentGroupId($ctx);

if ($currentGroupId !== null) {
    header('Location: /tasks/index.php?group_id=' . $currentGroupId);
    exit;
}

$u = current_user();
header_html('Home');
?>
<h1>Hello, <?=h($u['first_name'] ?? '')?></h1>
<div class="card">
  <h2>Welcome to <?=h(Settings::siteTitle())?></h2>
  <p><span class="prompt-em">Create your first group</span> to start managing tasks with other people.</p>
  <div class="actions">
    <a class="button primary" href="/groups/add.php">Create a Group</a>
  </div>
</div>
<?php footer_html(); ?>
