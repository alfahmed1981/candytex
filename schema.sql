-- ============================================
-- Database: candytex_dash
-- Updated: 2026-02-08 — reflects actual schema
-- ============================================

-- 1. Users (Staff & Admins)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cin` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `whatsapp` VARCHAR(20) DEFAULT NULL,
    `role` ENUM('admin', 'manager', 'viewer') DEFAULT 'manager',
    `password` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('active', 'pending') DEFAULT 'active',
    `department` VARCHAR(100) DEFAULT NULL,
    `location` VARCHAR(100) DEFAULT NULL,
    `birth_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Departments
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Locations
CREATE TABLE IF NOT EXISTS `locations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Shifts
CREATE TABLE IF NOT EXISTS `shifts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(20) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. System Roles (lookup)
CREATE TABLE IF NOT EXISTS `system_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(50) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. SQDC Daily Records
CREATE TABLE IF NOT EXISTS `sqdc_daily` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_cin` VARCHAR(20) NOT NULL,
    `day_date` DATE NOT NULL,
    `category` ENUM('S', 'Q', 'D', '5S', 'C') NOT NULL,
    `status` ENUM('green', 'orange', 'red', 'blue', 'gray') DEFAULT 'gray',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_entry` (`user_cin`, `day_date`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Countermeasures (with soft-delete)
CREATE TABLE IF NOT EXISTS `countermeasures` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_cin` VARCHAR(20) NOT NULL,
    `category` ENUM('S', 'Q', 'D', '5S', 'C') DEFAULT 'S',
    `issue` TEXT NOT NULL,
    `action_plan` TEXT NOT NULL,
    `responsible` VARCHAR(100),
    `due_date` DATE,
    `status` ENUM('Open', 'In Progress', 'Done') DEFAULT 'Open',
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Workers (managed by team leads)
CREATE TABLE IF NOT EXISTS `workers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cin` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `manager_cin` VARCHAR(20) NOT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `location` VARCHAR(100) DEFAULT NULL,
    `shift` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Audit Log (auto-created by auth.php)
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_cin` VARCHAR(20) DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- Seed Data
-- ============================================

INSERT IGNORE INTO locations (name) VALUES ('Candy 1'), ('Candy 2'), ('Flora 1');

INSERT IGNORE INTO departments (name) VALUES
    ('Sewing'), ('Cutting'), ('Finishing'), ('Packing'),
    ('Warehouse'), ('Maintenance'), ('Quality'), ('HR_Admin');

INSERT IGNORE INTO shifts (code, name) VALUES
    ('A', 'Shift A (Matin)'),
    ('B', 'Shift B (Après-midi)'),
    ('C', 'Shift C (Nuit)'),
    ('Normal', 'Normal Day');

INSERT IGNORE INTO system_roles (slug, name) VALUES
    ('admin', 'Administrator'),
    ('manager', 'Manager / Chef d''équipe');
