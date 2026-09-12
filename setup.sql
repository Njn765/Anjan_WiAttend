-- ============================================================
--  Wi-Attend: Wi-Fi Based Attendance System
--  Database Setup Script
--  Run this once in phpMyAdmin or MySQL terminal
-- ============================================================

CREATE DATABASE IF NOT EXISTS wi_attend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wi_attend;

-- ── Departments ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS departments (
    department_id INT PRIMARY KEY AUTO_INCREMENT,
    dept_name     VARCHAR(100) NOT NULL,
    description   TEXT,
    location      VARCHAR(100),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Staff ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS staff (
    staff_id      INT PRIMARY KEY AUTO_INCREMENT,
    full_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(150) UNIQUE,
    phone         VARCHAR(20),
    department_id INT,
    job_title     VARCHAR(100),
    is_active     TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL
);

-- ── MAC Addresses (one staff can have multiple devices) ───────
CREATE TABLE IF NOT EXISTS mac_addresses (
    mac_id        INT PRIMARY KEY AUTO_INCREMENT,
    staff_id      INT NOT NULL,
    mac_address   VARCHAR(17) UNIQUE NOT NULL,   -- format: aa:bb:cc:dd:ee:ff
    device_label  VARCHAR(100),                  -- e.g. "Work Laptop", "iPhone"
    is_active     TINYINT(1) DEFAULT 1,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff(staff_id) ON DELETE CASCADE
);

-- ── Wi-Fi Zones (one per access point / router) ───────────────
CREATE TABLE IF NOT EXISTS wifi_zones (
    wifi_zone_id  INT PRIMARY KEY AUTO_INCREMENT,
    zone_name     VARCHAR(100) NOT NULL,
    router_mac    VARCHAR(17) UNIQUE NOT NULL,   -- router's own MAC
    ssid          VARCHAR(100) NOT NULL,
    location_desc VARCHAR(200),
    is_active     TINYINT(1) DEFAULT 1
);

-- ── Attendance Logs ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance_logs (
    log_id           INT PRIMARY KEY AUTO_INCREMENT,
    staff_id         INT NOT NULL,
    mac_id           INT NOT NULL,
    wifi_zone_id     INT NOT NULL,
    check_in         DATETIME NOT NULL,
    check_out        DATETIME,
    duration_minutes INT,
    status           ENUM('present','left','absent') DEFAULT 'present',
    FOREIGN KEY (staff_id)     REFERENCES staff(staff_id),
    FOREIGN KEY (mac_id)       REFERENCES mac_addresses(mac_id),
    FOREIGN KEY (wifi_zone_id) REFERENCES wifi_zones(wifi_zone_id)
);

-- ── Admin Users ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_users (
    admin_id      INT PRIMARY KEY AUTO_INCREMENT,
    username      VARCHAR(80) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email         VARCHAR(150) UNIQUE NOT NULL,
    role          ENUM('superadmin','admin') DEFAULT 'admin',
    last_login    TIMESTAMP NULL
);

-- ── Seed: default admin (username: admin  /  password: admin123) ──
-- Password hash for "admin123" — change this after first login!
INSERT IGNORE INTO admin_users (username, password_hash, email, role) VALUES
('admin',
 '$2y$10$TKh8H1.PfQ0A32/erkzTau2fNiYrgSwxOKJ0buvEM9F2S2BGFP37.',
 'admin@wiattend.local',
 'superadmin');

-- ── Seed: sample departments ──────────────────────────────────
INSERT IGNORE INTO departments (dept_name, description, location) VALUES
('IT Department',  'Information Technology team', 'Floor 2'),
('HR Department',  'Human Resources',             'Floor 1'),
('Finance',        'Finance and Accounts',         'Floor 1');

-- ── Seed: sample Wi-Fi zone ───────────────────────────────────
INSERT IGNORE INTO wifi_zones (zone_name, router_mac, ssid, location_desc) VALUES
('Main Office', '00:00:00:00:00:01', 'Office-WiFi', 'Main office floor');
