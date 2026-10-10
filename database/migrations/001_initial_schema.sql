-- Начальная схема «Автодиспетчер». MySQL 5.7+/8.0 или MariaDB 10.3+, InnoDB, utf8mb4.
-- Время хранится в часовом поясе приложения (Asia/Vladivostok).

CREATE TABLE departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    phone VARCHAR(32) NULL,
    department_id INT UNSIGNED NULL,
    role ENUM('employee','dispatcher','driver','admin') NOT NULL DEFAULT 'employee',
    active TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_login (login),
    KEY ix_users_role (role, active),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    brand VARCHAR(64) NOT NULL,
    model VARCHAR(64) NOT NULL,
    registration_number VARCHAR(20) NOT NULL,
    seat_count TINYINT UNSIGNED NOT NULL DEFAULT 4,
    status ENUM('in_service','repair','decommissioned') NOT NULL DEFAULT 'in_service',
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicles_registration (registration_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drivers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_user (user_id),
    CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE maintenance_periods (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY ix_maintenance_vehicle_time (vehicle_id, starts_at, ends_at),
    CONSTRAINT fk_maintenance_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id),
    CONSTRAINT fk_maintenance_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    requester_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    requested_date DATE NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    pickup_location VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    purpose TEXT NOT NULL,
    passenger_count TINYINT UNSIGNED NULL,
    preferred_vehicle_id INT UNSIGNED NULL,
    comment TEXT NULL,
    contact_phone VARCHAR(32) NULL,
    priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
    status ENUM('draft','submitted','under_review','approved','rejected','cancelled','completed') NOT NULL DEFAULT 'draft',
    decision_reason TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_requests_status_time (status, starts_at),
    KEY ix_requests_requester (requester_id, starts_at),
    CONSTRAINT fk_requests_requester FOREIGN KEY (requester_id) REFERENCES users (id),
    CONSTRAINT fk_requests_department FOREIGN KEY (department_id) REFERENCES departments (id),
    CONSTRAINT fk_requests_vehicle FOREIGN KEY (preferred_vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- starts_at/ends_at копируются из заявки при назначении: по ним идёт проверка пересечений.
CREATE TABLE assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    driver_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('assigned','acknowledged','in_progress','finished','cancelled') NOT NULL DEFAULT 'assigned',
    assigned_by INT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    trip_started_at DATETIME NULL,
    trip_finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_assignments_request (request_id, status),
    KEY ix_assignments_vehicle_time (vehicle_id, status, starts_at, ends_at),
    KEY ix_assignments_driver_time (driver_id, status, starts_at, ends_at),
    CONSTRAINT fk_assignments_request FOREIGN KEY (request_id) REFERENCES requests (id),
    CONSTRAINT fk_assignments_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id),
    CONSTRAINT fk_assignments_driver FOREIGN KEY (driver_id) REFERENCES drivers (id),
    CONSTRAINT fk_assignments_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    link VARCHAR(255) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_notifications_user (user_id, read_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED NULL COMMENT 'NULL — действие системы',
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(64) NOT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_audit_entity (entity_type, entity_id),
    KEY ix_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    login VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_login_attempts_login (login, attempted_at),
    KEY ix_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Задел под бот MAX (интеграция не реализована, требует согласования — вопрос Q06).
CREATE TABLE max_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    max_user_id VARCHAR(64) NOT NULL,
    linked_at DATETIME NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_max_accounts_user (user_id),
    UNIQUE KEY uq_max_accounts_max_user (max_user_id),
    CONSTRAINT fk_max_accounts_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE outbound_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel VARCHAR(16) NOT NULL,
    recipient_user_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_outbound_status (status, created_at),
    CONSTRAINT fk_outbound_user FOREIGN KEY (recipient_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
