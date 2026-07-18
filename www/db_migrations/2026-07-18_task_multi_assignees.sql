-- Multi-assignee tasks: a task may be assigned to any number of group
-- members, all equal (docs/app-spec.md). Copies the old single assignee into
-- the new join table, then removes tasks.assigned_to_user_id entirely.

CREATE TABLE task_assignees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_task_user (task_id, user_id),
  CONSTRAINT fk_ta_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_ta_user ON task_assignees(user_id);

INSERT INTO task_assignees (task_id, user_id)
  SELECT id, assigned_to_user_id FROM tasks WHERE assigned_to_user_id IS NOT NULL;

ALTER TABLE tasks DROP FOREIGN KEY fk_tasks_assignee;
DROP INDEX idx_tasks_assignee ON tasks;
ALTER TABLE tasks DROP COLUMN assigned_to_user_id;
