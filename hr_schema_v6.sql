-- HR Schema Update V6 (Moroccan Labor Law)
-- Run this via the Admin SQL tool or `mysql -u root candytex_dash < hr_schema_v6.sql`

-- 1. Update Users Table to include 'hr' Role
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin', 'manager', 'viewer', 'hr') DEFAULT 'manager';

-- 2. Create the Absences Tracking Table (Maladie, Maternité, Accident Travail, Mise a pied, etc.)
CREATE TABLE IF NOT EXISTS `hr_absences` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `absence_type` VARCHAR(20) NOT NULL COMMENT 'M, MAT, AT, MP, CP, AI',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `certificate_number` VARCHAR(100) DEFAULT NULL,
    `is_extension` TINYINT(1) DEFAULT 0,
    `parent_absence_id` INT DEFAULT NULL,
    `comments` TEXT DEFAULT NULL,
    `recorded_by` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`employee_id`) REFERENCES `hr_employees`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`parent_absence_id`) REFERENCES `hr_absences`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create Lateness Tracking Table
CREATE TABLE IF NOT EXISTS `hr_latenesses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `lateness_date` DATE NOT NULL,
    `duration_minutes` INT NOT NULL CHECK (duration_minutes > 0),
    `reason` VARCHAR(255) DEFAULT NULL,
    `deducted_from_pay` TINYINT(1) DEFAULT 1,
    `recorded_by` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`employee_id`) REFERENCES `hr_employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
