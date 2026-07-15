<?php
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

$msg = null;
$err = null;

// Handle messages from evaluation script
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['err'])) {
    $err = $_GET['err'];
}

// For repopulating form after errors - get from URL parameters
$form = [];
$formFields = ['first_name', 'last_name', 'email', 'is_admin', 'no_login'];
foreach ($formFields as $field) {
    if (isset($_GET[$field])) {
        $form[$field] = $_GET[$field];
    }
}

header_html('Add User');
?>

<h2>Add User</h2>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/user_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    
    <h3>User Information</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
      <label>First name
        <input type="text" name="first_name" value="<?=h($form['first_name'] ?? '')?>" required>
      </label>
      <label>Last name
        <input type="text" name="last_name" value="<?=h($form['last_name'] ?? '')?>" required>
      </label>
      <label>Email
        <input type="email" name="email" id="emailInput" value="<?=h($form['email'] ?? '')?>" <?= empty($form['no_login']) ? 'required' : '' ?>>
      </label>
    </div>

    <h3>Account Type</h3>
    <div class="stack">
      <label class="inline">
        <input type="radio" name="no_login" value="0" id="canLoginRadio" <?= empty($form['no_login']) ? 'checked' : '' ?>>
        Can sign in — sends an activation email so they can set their password
      </label>
      <label class="inline">
        <input type="radio" name="no_login" value="1" id="noLoginRadio" <?= !empty($form['no_login']) ? 'checked' : '' ?>>
        Family member only — no login (email optional; you can activate an account later)
      </label>
      <label class="inline" id="isAdminLabel">
        <input type="checkbox" name="is_admin" value="1" <?= !empty($form['is_admin']) ? 'checked' : '' ?>>
        Admin user
      </label>
    </div>

    <div class="actions">
      <button class="primary" type="submit">Create User</button>
      <a class="button" href="/admin/users.php">Cancel</a>
    </div>
  </form>
</div>

<script>
  (function(){
    // Email is required only when the person will sign in; admins must sign in.
    var canLogin = document.getElementById('canLoginRadio');
    var noLogin = document.getElementById('noLoginRadio');
    var email = document.getElementById('emailInput');
    var isAdminLabel = document.getElementById('isAdminLabel');
    var isAdminCheckbox = isAdminLabel.querySelector('input');

    function sync() {
      var loginDisabled = noLogin.checked;
      email.required = !loginDisabled;
      isAdminCheckbox.disabled = loginDisabled;
      if (loginDisabled) isAdminCheckbox.checked = false;
      isAdminLabel.style.opacity = loginDisabled ? '0.5' : '1';
    }

    canLogin.addEventListener('change', sync);
    noLogin.addEventListener('change', sync);
    sync();
  })();
</script>

<?php footer_html(); ?>
