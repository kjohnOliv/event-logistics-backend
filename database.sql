CREATE DATABASE IF NOT EXISTS smartqueue_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartqueue_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'operator', 'user') NOT NULL DEFAULT 'user',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_token VARCHAR(128) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_token (verification_token)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(120) NOT NULL UNIQUE,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY,
    avg_service_time INT NOT NULL DEFAULT 5,
    last_regular_number INT NOT NULL DEFAULT 0,
    last_priority_number INT NOT NULL DEFAULT 0,
    queue_open_time TIME NOT NULL DEFAULT '06:30:00',
    queue_cutoff_time TIME NOT NULL DEFAULT '17:00:00',
    daily_reset_time TIME NOT NULL DEFAULT '18:00:00',
    last_daily_reset_date DATE NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    service_id INT NULL,
    name VARCHAR(120) NOT NULL,
    queue_number INT NOT NULL,
    priority_type ENUM('regular', 'priority') NOT NULL DEFAULT 'regular',
    status ENUM('waiting', 'serving', 'done', 'cancelled') NOT NULL DEFAULT 'waiting',
    assigned_operator_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    served_at DATETIME NULL,
    completed_at DATETIME NULL,
    archived_at DATETIME NULL,
    INDEX idx_queue_status (status, archived_at),
    INDEX idx_queue_number (queue_number),
    INDEX idx_queue_user_status (user_id, status),
    CONSTRAINT fk_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_queue_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    CONSTRAINT fk_queue_operator FOREIGN KEY (assigned_operator_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (id, avg_service_time, last_regular_number, last_priority_number, queue_open_time, queue_cutoff_time, daily_reset_time) VALUES (1, 5, 0, 0, '06:30:00', '17:00:00', '18:00:00');

INSERT IGNORE INTO services (service_name, status) VALUES
('General Inquiry', 'active'),
('Billing', 'active'),
('Registration', 'active'),
('Technical Support', 'active');

-- Default accounts for local testing. Change these passwords after import.
-- Password for all seeded accounts: password123
INSERT IGNORE INTO users (full_name, email, password, role, status, is_verified) VALUES
('SmartQueue Admin', 'admin@smartqueue.local', '$2y$10$qLQDwDQa9jZWuZWea0ETT.pu9wgZtV65XnH5ACy.1JnFoHdBAI34a', 'admin', 'active', 1),
('SmartQueue Operator', 'operator@smartqueue.local', '$2y$10$qLQDwDQa9jZWuZWea0ETT.pu9wgZtV65XnH5ACy.1JnFoHdBAI34a', 'operator', 'active', 1),
('SmartQueue User', 'user@smartqueue.local', '$2y$10$qLQDwDQa9jZWuZWea0ETT.pu9wgZtV65XnH5ACy.1JnFoHdBAI34a', 'user', 'active', 1);




