<?php
// Create a group — form. Evaluates to groups/add_eval.php.
require_once __DIR__ . '/../partials.php';
Application::init();
require_login();

// One-shot flash + form repopulation from add_eval.php on error
$err = $_SESSION['error'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

header_html('New Group');
?>
<h2>New Group</h2>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p><span class="prompt-em">Name your group</span> — you will be its owner and can invite people to it.</p>
  <form method="post" action="/groups/add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <label>Group name
      <input type="text" name="name" value="<?=h($form['name'] ?? '')?>" required>
    </label>
    <label>Description (optional)
      <textarea name="description" rows="3"><?=h($form['description'] ?? '')?></textarea>
    </label>
    <div class="actions">
      <button class="primary" type="submit">Create Group</button>
      <a class="button" href="/index.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
