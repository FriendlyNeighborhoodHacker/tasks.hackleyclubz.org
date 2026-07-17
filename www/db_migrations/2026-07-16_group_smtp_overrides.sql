-- Per-group SMTP overrides: when a row exists, that group's emails
-- (assignment + scheduled reminders) are sent through this SMTP server
-- instead of the global SMTP_* constants in config.local.php.
CREATE TABLE group_smtp_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  group_id INT NOT NULL,
  smtp_host VARCHAR(255) NOT NULL,
  smtp_port INT NOT NULL,
  smtp_username VARCHAR(255) NOT NULL,
  smtp_password VARCHAR(255) NOT NULL,
  smtp_secure ENUM('tls','ssl') NOT NULL DEFAULT 'tls',
  from_email VARCHAR(255) DEFAULT NULL,
  from_name VARCHAR(255) DEFAULT NULL,
  updated_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_group_smtp (group_id),
  CONSTRAINT fk_gso_group FOREIGN KEY (group_id) REFERENCES task_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_gso_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
