-- Categories are retired: the by-category view was replaced by By Owner and
-- the field no longer appears anywhere in the UI.

DROP INDEX idx_tasks_category ON tasks;
ALTER TABLE tasks DROP COLUMN category;
