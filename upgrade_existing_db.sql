USE smartqueue_db;

ALTER TABLE settings
    ADD COLUMN IF NOT EXISTS last_regular_number INT NOT NULL DEFAULT 0 AFTER avg_service_time,
    ADD COLUMN IF NOT EXISTS last_priority_number INT NOT NULL DEFAULT 0 AFTER last_regular_number,
    ADD COLUMN IF NOT EXISTS queue_open_time TIME NOT NULL DEFAULT '06:30:00' AFTER last_priority_number,
    ADD COLUMN IF NOT EXISTS queue_cutoff_time TIME NOT NULL DEFAULT '17:00:00' AFTER queue_open_time,
    ADD COLUMN IF NOT EXISTS daily_reset_time TIME NOT NULL DEFAULT '18:00:00' AFTER queue_cutoff_time,
    ADD COLUMN IF NOT EXISTS last_daily_reset_date DATE NULL AFTER daily_reset_time;

ALTER TABLE queue
    MODIFY priority_type ENUM('regular', 'priority') NOT NULL DEFAULT 'regular',
    MODIFY status ENUM('waiting', 'serving', 'done', 'cancelled') NOT NULL DEFAULT 'waiting',
    MODIFY user_id INT NULL;

ALTER TABLE queue
    ADD COLUMN IF NOT EXISTS assigned_operator_id INT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS served_at DATETIME NULL AFTER created_at,
    ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER served_at,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER completed_at;

INSERT IGNORE INTO settings (id, avg_service_time, last_regular_number, last_priority_number, queue_open_time, queue_cutoff_time, daily_reset_time) VALUES (1, 5, 0, 0, '06:30:00', '17:00:00', '18:00:00');

DELETE FROM services WHERE LOWER(service_name) = 'justin';

UPDATE settings
SET queue_open_time = '06:30:00',
    queue_cutoff_time = '17:00:00',
    daily_reset_time = '18:00:00'
WHERE id = 1;

UPDATE settings
SET last_regular_number = COALESCE((SELECT MAX(queue_number) FROM queue WHERE priority_type = 'regular'), 0)
WHERE id = 1 AND last_regular_number = 0;

UPDATE settings
SET last_priority_number = COALESCE((SELECT MAX(queue_number) FROM queue WHERE priority_type = 'priority'), 0)
WHERE id = 1 AND last_priority_number = 0;
