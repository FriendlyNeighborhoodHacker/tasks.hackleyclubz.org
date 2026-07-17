<?php
// Group settings — Email Templates tab: the group's customizable assignment
// and reminder templates. Group admins only. Evaluates to
// groups/settings_templates_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/GroupManagement.php';
require_once __DIR__ . '/../lib/EmailTemplates.php';
Application::init();
require_login();

$groupId = (int)($_GET['group_id'] ?? 0);
$group = $groupId ? GroupManagement::getGroup($groupId) : null;
$ctx = UserContext::getLoggedInUserContext();

if (!$group || !GroupManagement::canManageGroup($ctx, $groupId)) {
    http_response_code(403);
    die('Only the group owner or a group admin can edit email templates.');
}

$err = $_SESSION['error'] ?? null;
$msg = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form_data']);

header_html('Email Templates - ' . $group['name']);
?>
<h2><?=h($group['name'])?> — Settings</h2>
<?=group_settings_tabs_html($groupId, 'templates')?>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <h3>Email Templates</h3>
  <p class="small">These emails go to your group's members: when a task is assigned, and as scheduled
    reminders (one email when a single task is due, a combined email when several are). Placeholders in
    <code>[brackets]</code> are filled in per person and task when the email is sent.</p>
  <?php foreach (EmailTemplates::TYPES as $type): $tpl = EmailTemplates::getTemplate($groupId, $type); $info = EmailTemplates::TYPE_INFO[$type]; ?>
  <details class="email-template" <?=$tpl['is_custom'] ? 'open' : ''?>>
    <summary><?=h($info['label'])?><?php if ($tpl['is_custom']): ?> <span class="badge success">customized</span><?php endif; ?></summary>
    <p class="small"><?=h($info['help'])?> Available placeholders: <code><?=h($info['tokens'])?></code></p>
    <form method="post" action="/groups/settings_templates_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="group_id" value="<?=$groupId?>">
      <input type="hidden" name="action" value="save_email_template">
      <input type="hidden" name="template_type" value="<?=h($type)?>">
      <label>Subject
        <input type="text" name="subject" value="<?=h($tpl['subject'])?>" required>
      </label>
      <label>Body
        <textarea name="body" rows="10" required><?=h($tpl['body'])?></textarea>
      </label>
      <div class="actions">
        <button class="primary" type="submit">Save Template</button>
        <?php if ($tpl['is_custom']): ?>
        <button class="button" type="submit" name="action" value="reset_email_template"
                data-confirm="Discard this customization and go back to the default template?">Reset to default</button>
        <?php endif; ?>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
