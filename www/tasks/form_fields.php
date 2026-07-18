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
        'due_date' => $post['due_date'] ?? '',
        'assigned_user_ids' => array_map('intval', (array)($post['assigned_user_ids'] ?? [])),
        'reminders' => $reminders,
    ];
}

/**
 * $values: title, description, due_date, assigned_user_ids (array),
 *          assign_new_person, reminder_days (array of ints)
 * $opts:   members (group member rows),
 *          can_add_person (bool — show the "New person…" assignee option)
 */
function render_task_form_fields(array $values, array $opts = []): void {
    $members = $opts['members'] ?? [];
    $canAddPerson = !empty($opts['can_add_person']);
    $assignedIds = array_map('intval', (array)($values['assigned_user_ids'] ?? []));
    $assignNewPerson = !empty($values['assign_new_person']);
    $reminderDays = $values['reminder_days'] ?? [];
    ?>
    <div class="grid">
      <label>Title
        <input type="text" name="title" value="<?=h($values['title'] ?? '')?>" required>
      </label>
      <label>Due date
        <input type="date" name="due_date" value="<?=h($values['due_date'] ?? '')?>">
      </label>
    </div>

    <fieldset class="assignees">
      <legend>Assigned to (any number of people)</legend>
      <label class="inline assignee-select-all">
        <input type="checkbox" id="assignSelectAll"> <strong>Select all</strong>
      </label>
      <div class="assignee-checklist" id="assigneeChecklist">
        <?php foreach ($members as $m): ?>
          <label class="inline">
            <input type="checkbox" name="assigned_user_ids[]" value="<?=(int)$m['id']?>"
                   <?=in_array((int)$m['id'], $assignedIds, true) ? 'checked' : ''?>>
            <?=h(trim($m['first_name'] . ' ' . $m['last_name']))?>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if ($canAddPerson): ?>
        <label class="inline">
          <input type="checkbox" id="assignNewPerson" name="assign_new_person" value="1" <?=$assignNewPerson ? 'checked' : ''?>>
          New person…
        </label>
      <?php endif; ?>
    </fieldset>

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
      // "New person…" checkbox reveals the name/email fields.
      var newBox = document.getElementById('assignNewPerson');
      var newFields = document.getElementById('newPersonFields');
      function syncNewPerson() {
        if (!newFields) return;
        newFields.style.display = (newBox && newBox.checked) ? '' : 'none';
      }
      if (newBox) newBox.addEventListener('change', syncNewPerson);
      syncNewPerson();

      // "Select all" checks/unchecks every member; reflects manual changes.
      var selectAll = document.getElementById('assignSelectAll');
      var memberBoxes = Array.prototype.slice.call(
        document.querySelectorAll('#assigneeChecklist input[name="assigned_user_ids[]"]'));
      function syncSelectAll() {
        if (selectAll) selectAll.checked = memberBoxes.length > 0 && memberBoxes.every(function (b) { return b.checked; });
      }
      if (selectAll) {
        selectAll.addEventListener('change', function () {
          memberBoxes.forEach(function (b) { b.checked = selectAll.checked; });
        });
        memberBoxes.forEach(function (b) { b.addEventListener('change', syncSelectAll); });
        syncSelectAll();
      }

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
