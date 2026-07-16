-- Admin-editable send time for a task's scheduled reminder email. When set,
-- it replaces the task's days-in-advance reminders (due-today and overdue
-- reminders still apply); the daily runner sends it on that date.

ALTER TABLE tasks
  ADD COLUMN custom_email_send_at DATETIME DEFAULT NULL COMMENT 'Admin-set reminder send time; NULL = automatic schedule';
