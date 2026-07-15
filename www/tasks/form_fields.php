<?php
// Shared form fields for the add/edit task forms. The surrounding page owns
// the <form>, the CSRF hidden input, and the submit buttons.

// Map the raw POST of a task form into the array TaskManagement expects.
function task_data_from_post(array $post): array {
    $reminders = [];
    foreach ((array)($post['reminder_days'] ?? []) as $d) {
        if (trim((string)$d) !== '') {
            $reminders[] = (int)$d;
        }
    }
    return [
        'title' => $post['title'] ?? '',
        'description' => $post['description'] ?? '',
        'category' => $post['category'] ?? '',
        'due_date' => $post['due_date'] ?? '',
        'assigned_to_user_id' => $post['assigned_to_user_id'] ?? '',
        'reminders' => $reminders,
    ];
}

/**
 * $values: title, description, category, due_date, assigned_to_user_id,
 *          reminder_days (array of ints)
 * $opts:   members (group member rows), categories (strings),
 *          can_add_person (bool — show the "New person…" assignee option)
 */
function render_task_form_fields(array $values, array $opts = []): void {
    $members = $opts['members'] ?? [];
    $categories = $opts['categories'] ?? [];
    $canAddPerson = !empty($opts['can_add_person']);
    $assignedTo = (string)($values['assigned_to_user_id'] ?? '');
    $reminderDays = $values['reminder_days'] ?? [];
    ?>
    <div class="grid">
      <label>Title
        <input type="text" name="title" value="<?=h($values['title'] ?? '')?>" required>
      </label>
      <label>Category
        <input type="text" name="category" list="categorySuggestions" value="<?=h($values['category'] ?? '')?>" placeholder="e.g. Events">
        <datalist id="categorySuggestions">
          <?php foreach ($categories as $c): ?>
            <option value="<?=h($c)?>"></option>
          <?php endforeach; ?>
        </datalist>
      </label>
      <label>Assigned to
        <select name="assigned_to_user_id" id="assignedToSelect">
          <option value="">— Unassigned —</option>
          <?php foreach ($members as $m): ?>
            <option value="<?=(int)$m['id']?>" <?=$assignedTo !== '' && (int)$assignedTo === (int)$m['id'] ? 'selected' : ''?>>
              <?=h(trim($m['first_name'] . ' ' . $m['last_name']))?>
            </option>
          <?php endforeach; ?>
          <?php if ($canAddPerson): ?>
            <option value="__new__" <?=$assignedTo === '__new__' ? 'selected' : ''?>>New person…</option>
          <?php endif; ?>
        </select>
      </label>
      <label>Due date
        <input type="date" name="due_date" value="<?=h($values['due_date'] ?? '')?>">
      </label>
    </div>

    <?php if ($canAddPerson): ?>
    <div id="newPersonFields" class="grid" style="display:none;">
      <label>New person's first name
        <input type="text" name="new_person_first_name" value="<?=h($values['new_person_first_name'] ?? '')?>">
      </label>
      <label>Last name
        <input type="text" name="new_person_last_name" value="<?=h($values['new_person_last_name'] ?? '')?>">
      </label>
      <label>Email
        <input type="email" name="new_person_email" value="<?=h($values['new_person_email'] ?? '')?>">
      </label>
    </div>
    <?php endif; ?>

    <label>Description / instructions
      <textarea name="description" rows="4"><?=h($values['description'] ?? '')?></textarea>
    </label>

    <fieldset class="reminders">
      <legend>Email reminders (days before the due date)</legend>
      <div id="reminderRows">
        <?php foreach ($reminderDays as $d): ?>
          <div class="reminder-row">
            <input type="number" name="reminder_days[]" min="0" step="1" value="<?=h((string)$d)?>" style="width:90px;"> days before
            <button type="button" class="button remove-reminder">Remove</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="button" id="addReminder">+ Add reminder</button>
    </fieldset>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var select = document.getElementById('assignedToSelect');
      var newFields = document.getElementById('newPersonFields');
      function syncNewPerson() {
        if (!newFields) return;
        newFields.style.display = (select && select.value === '__new__') ? '' : 'none';
      }
      if (select) select.addEventListener('change', syncNewPerson);
      syncNewPerson();

      var rows = document.getElementById('reminderRows');
      var add = document.getElementById('addReminder');
      if (add && rows) {
        add.addEventListener('click', function () {
          var div = document.createElement('div');
          div.className = 'reminder-row';
          div.innerHTML = '<input type="number" name="reminder_days[]" min="0" step="1" value="1" style="width:90px;"> days before ' +
                          '<button type="button" class="button remove-reminder">Remove</button>';
          rows.appendChild(div);
        });
      }
      document.addEventListener('click', function (e) {
        if (e.target && e.target.classList.contains('remove-reminder')) {
          e.target.closest('.reminder-row').remove();
        }
      });
    });
    </script>
    <?php
}
