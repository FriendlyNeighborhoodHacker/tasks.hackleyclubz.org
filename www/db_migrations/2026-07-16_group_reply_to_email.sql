-- Per-group Reply-To address for outgoing emails (assignment + reminders).
-- Independent of the SMTP override: a group can keep the site-wide sender and
-- still direct replies to e.g. the group leader's address. NULL = no
-- Reply-To header.
ALTER TABLE task_groups
  ADD COLUMN reply_to_email VARCHAR(255) DEFAULT NULL COMMENT 'Reply-To header for the group''s emails; NULL = none. Works with or without a group_smtp_overrides row.';
