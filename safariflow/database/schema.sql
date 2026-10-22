CREATE DATABASE IF NOT EXISTS safariflow
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE safariflow;

CREATE TABLE IF NOT EXISTS saccos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sacco_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('platform_owner', 'sacco_admin', 'dispatcher', 'marshal', 'passenger') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY users_email_unique (email),
    KEY users_sacco_role_index (sacco_id, role),
    CONSTRAINT users_sacco_fk FOREIGN KEY (sacco_id) REFERENCES saccos (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sacco_id BIGINT UNSIGNED NOT NULL,
    registration VARCHAR(20) NOT NULL,
    make_model VARCHAR(100) NOT NULL,
    capacity TINYINT UNSIGNED NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY vehicles_registration_unique (registration),
    KEY vehicles_sacco_status_index (sacco_id, status),
    CONSTRAINT vehicles_sacco_fk FOREIGN KEY (sacco_id) REFERENCES saccos (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sacco_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    origin VARCHAR(120) NOT NULL,
    destination VARCHAR(120) NOT NULL,
    fare DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY routes_sacco_name_unique (sacco_id, name),
    KEY routes_sacco_status_index (sacco_id, status),
    CONSTRAINT routes_sacco_fk FOREIGN KEY (sacco_id) REFERENCES saccos (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sacco_id BIGINT UNSIGNED NOT NULL,
    route_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    departure_at DATETIME NOT NULL,
    status ENUM('scheduled', 'boarding', 'departed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY departures_vehicle_time_unique (vehicle_id, departure_at),
    KEY departures_sacco_status_time_index (sacco_id, status, departure_at),
    CONSTRAINT departures_sacco_fk FOREIGN KEY (sacco_id) REFERENCES saccos (id) ON DELETE RESTRICT,
    CONSTRAINT departures_route_fk FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE RESTRICT,
    CONSTRAINT departures_vehicle_fk FOREIGN KEY (vehicle_id) REFERENCES vehicles (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS passenger_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    departure_id BIGINT UNSIGNED NOT NULL,
    passenger_id BIGINT UNSIGNED NOT NULL,
    status ENUM('queued', 'cancelled') NOT NULL DEFAULT 'queued',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY passenger_queue_departure_passenger_unique (departure_id, passenger_id),
    KEY passenger_queue_departure_status_joined_index (departure_id, status, joined_at, id),
    CONSTRAINT passenger_queue_departure_fk FOREIGN KEY (departure_id) REFERENCES departures (id) ON DELETE RESTRICT,
    CONSTRAINT passenger_queue_passenger_fk FOREIGN KEY (passenger_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
