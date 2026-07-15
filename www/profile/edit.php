<?php
// Edit my profile — form. Evaluates to profile/edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_login();

$me = current_user();

// One-shot flash + form repopulation from edit_eval.php on error
$err = $_SESSION['error'] ?? null;
$form = $_SESSION['form_data'] ?? [];
unset($_SESSION['error'], $_SESSION['form_data']);

$firstName = $form['first_name'] ?? $me['first_name'];
$lastName = $form['last_name'] ?? $me['last_name'];
$email = $form['email'] ?? $me['email'];

header_html('Edit Profile');
?>
<h2>Edit Profile</h2>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/profile/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($firstName)?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($lastName)?>" required>
      </label>
      <label>Email
        <input type="email" name="email" value="<?=h($email)?>" required>
      </label>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Save Profile</button>
      <a class="button" href="/profile/">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
