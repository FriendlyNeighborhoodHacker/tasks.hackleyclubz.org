-- Customizable per-group email templates (assignment / single reminder /
-- multi-task reminder), per-task email overrides ("Save for scheduled send"),
-- and the new 'assignment' notification type.

CREATE TABLE group_email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  template_type ENUM('assignment','reminder_single','reminder_multi') NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  updated_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_group_template (group_id, template_type),
  CONSTRAINT fk_get_group FOREIGN KEY (group_id) REFERENCES task_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_get_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Owner-edited email for one task's scheduled reminder ("Save for scheduled
-- send" in the email preview modal). NULL = use the group template.
ALTER TABLE tasks
  ADD COLUMN custom_email_subject VARCHAR(255) DEFAULT NULL,
  ADD COLUMN custom_email_body TEXT DEFAULT NULL;

ALTER TABLE notification_log
  MODIFY COLUMN notification_type ENUM('overdue','due_today','reminder','assignment') NOT NULL;
