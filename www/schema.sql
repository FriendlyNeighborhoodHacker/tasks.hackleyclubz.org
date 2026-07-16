-- Tasks (tasks.hackleyclubz.org) application schema
-- Create the database, then load this file. This file always represents the
-- complete current schema; migrations in db_migrations/ exist only to upgrade
-- older production installations.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===== Users =====
-- Email is the login identifier. An empty password_hash means the user cannot
-- sign in yet (e.g. someone who was only assigned a task by email); they can
-- gain a password at any time via the forgot-password flow or an invite.
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) DEFAULT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'App administrator',
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  password_reset_token_hash CHAR(64) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_users_email_verify_token ON users(email_verify_token);
CREATE INDEX idx_users_pwreset_expires ON users(password_reset_expires_at);

-- ===== Settings key-value table =====
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(191) NOT NULL UNIQUE,
  value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'Tasks'),
  ('announcement', ''),
  ('timezone', 'America/New_York'),
  ('login_image_file_id', ''),
  ('site_base_url', 'https://tasks.hackleyclubz.org'),
  ('task_token_expiry_days', '30')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- ===== Files Storage (DB-backed uploads) =====

-- Public files (profile photos, login logo). Public by design, immutable once
-- stored; served via public_file_download.php or the on-disk cache.
CREATE TABLE public_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_pf_sha256 ON public_files(sha256);
CREATE INDEX idx_pf_created_by ON public_files(created_by_user_id);
CREATE INDEX idx_pf_created_at ON public_files(created_at);

-- Private files (task comment attachments). Served only through a
-- membership-checked download endpoint and never written to the disk cache.
CREATE TABLE private_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  data LONGBLOB NOT NULL,
  content_type VARCHAR(100) DEFAULT NULL,
  original_filename VARCHAR(255) DEFAULT NULL,
  byte_length INT UNSIGNED DEFAULT NULL,
  sha256 CHAR(64) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prf_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_prf_sha256 ON private_files(sha256);
CREATE INDEX idx_prf_created_by ON private_files(created_by_user_id);

ALTER TABLE users
  ADD COLUMN photo_public_file_id INT NULL;

ALTER TABLE users
  ADD CONSTRAINT fk_users_photo_public_file
    FOREIGN KEY (photo_public_file_id) REFERENCES public_files(id) ON DELETE SET NULL;

-- ===== Activity Log =====
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action_type VARCHAR(64) NOT NULL,
  json_metadata LONGTEXT NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_al_created_at ON activity_log(created_at);
CREATE INDEX idx_al_user_id ON activity_log(user_id);
CREATE INDEX idx_al_action_type ON activity_log(action_type);

-- ===== Email Log =====
CREATE TABLE emails_sent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by_user_id INT NULL,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) DEFAULT NULL,
  cc_email VARCHAR(255) DEFAULT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html LONGTEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_emails_sent_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_emails_sent_created_at ON emails_sent(created_at);
CREATE INDEX idx_emails_sent_user_id ON emails_sent(sent_by_user_id);
CREATE INDEX idx_emails_sent_to_email ON emails_sent(to_email);
CREATE INDEX idx_emails_sent_success ON emails_sent(success);

-- ===== Groups =====
-- Named task_groups because GROUPS is a reserved word in MySQL 8+.
-- A group has exactly one owner. The owner always also has a group_members
-- row with role='admin' (enforced by GroupManagement); only the owner may
-- appoint or demote group admins.
CREATE TABLE task_groups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  owner_user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tg_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_tg_owner ON task_groups(owner_user_id);
CREATE INDEX idx_tg_name ON task_groups(name);

CREATE TABLE group_members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('member','admin') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_group_user (group_id, user_id),
  CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES task_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_gm_user ON group_members(user_id);

-- The last group the user had as context; renders first in the sidebar nav.
ALTER TABLE users
  ADD COLUMN last_group_id INT NULL;

ALTER TABLE users
  ADD CONSTRAINT fk_users_last_group
    FOREIGN KEY (last_group_id) REFERENCES task_groups(id) ON DELETE SET NULL;

-- ===== Tasks =====
CREATE TABLE tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL COMMENT 'Instructions for completing the task',
  category VARCHAR(100) DEFAULT NULL COMMENT 'Free text; UI suggests the group''s previous categories',
  due_date DATE DEFAULT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  completion_date DATE DEFAULT NULL,
  completed_by_user_id INT DEFAULT NULL,
  assigned_to_user_id INT DEFAULT NULL COMMENT 'NULL = unassigned (notifications fall back to group owner/admins)',
  created_by_user_id INT DEFAULT NULL,
  custom_email_subject VARCHAR(255) DEFAULT NULL COMMENT 'Owner-edited scheduled reminder email; NULL = use group template',
  custom_email_body TEXT DEFAULT NULL,
  custom_email_send_at DATETIME DEFAULT NULL COMMENT 'Admin-set reminder send time; NULL = automatic schedule',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tasks_group FOREIGN KEY (group_id) REFERENCES task_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tasks_completer FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_tasks_group_due ON tasks(group_id, due_date);
CREATE INDEX idx_tasks_assignee ON tasks(assigned_to_user_id);
CREATE INDEX idx_tasks_category ON tasks(group_id, category);
CREATE INDEX idx_tasks_done ON tasks(is_done);

-- Per-group customized email templates. Rows exist only for templates the
-- group has customized; defaults live in code (lib/EmailTemplates.php).
-- template_type:
--   assignment      — sent when a task is assigned to someone
--   reminder_single — scheduled reminder covering exactly one task
--   reminder_multi  — scheduled reminder covering several tasks at once
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

-- Email reminders: one row per "N days before the due date". A task may have
-- several. The daily notification runner also always covers due-today and
-- overdue tasks regardless of these rows.
CREATE TABLE task_reminders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  days_in_advance INT NOT NULL,
  channel ENUM('email') NOT NULL DEFAULT 'email' COMMENT 'Future: push, sms, in_app',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_task_days_channel (task_id, days_in_advance, channel),
  CONSTRAINT fk_tr_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Progress updates on a task: a comment, an optional private-file attachment,
-- and a flag recording that the update marked the task complete.
CREATE TABLE task_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  private_file_id INT DEFAULT NULL,
  marked_complete TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tc_file FOREIGN KEY (private_file_id) REFERENCES private_files(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_tc_task ON task_comments(task_id);

-- ===== Task access tokens (email limited-auth flow) =====
-- A token in a notification email authenticates its recipient for exactly one
-- task, only within the /t/ pages (view, comment, mark complete). The raw
-- 64-hex-char token exists only inside the email; the DB stores its sha256.
CREATE TABLE task_access_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  user_id INT NOT NULL COMMENT 'The user this token authenticates as',
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tat_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_tat_task_user ON task_access_tokens(task_id, user_id);

-- ===== Notification Log =====
-- Every emailed reminder is recorded here; the daily runner checks it before
-- sending so it is safe to run multiple times per day (idempotent).
-- notification_type:
--   overdue    — re-sent daily while overdue
--   due_today  — sent on the due date
--   reminder   — sent when due_date - days_in_advance == today (per task_reminders row)
--   assignment — sent when a task is assigned to someone
CREATE TABLE notification_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT DEFAULT NULL,
  task_id INT DEFAULT NULL,
  recipient_user_id INT NOT NULL,
  notification_type ENUM('overdue','due_today','reminder','assignment') NOT NULL,
  days_in_advance INT DEFAULT NULL COMMENT 'Set for notification_type=reminder',
  notification_date DATE NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  email_address VARCHAR(255) DEFAULT NULL,
  delivery_status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_nl_group FOREIGN KEY (group_id) REFERENCES task_groups(id) ON DELETE SET NULL,
  CONSTRAINT fk_nl_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_nl_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_nl_dedup ON notification_log(task_id, recipient_user_id, notification_type, notification_date);
CREATE INDEX idx_nl_date ON notification_log(notification_date);

-- Seed user per docs/app-spec.md: sign in with email "lilly" / password "lilly".
-- Regenerate the hash with: php -r "echo password_hash('lilly', PASSWORD_DEFAULT);"
INSERT INTO users (first_name,last_name,email,password_hash,is_admin,email_verified_at)
VALUES ('Lilly','Rosenthal','lilly','$2y$12$2IMMsNZ3pwUpTPmcXKQFr.P2grgudYlZZ/m2Y4jTxV1tjGDI9bX7.',1,NOW());
