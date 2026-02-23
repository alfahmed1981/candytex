-- Advanced HR Team Management & Timeline Schema
-- This replaces the old `workers` and `worker_tracking` methods for Team Leaders.
-- Instead, we now link directly to the master `hr_employees` table.

-- 1. Add Manager CIN to HR Employees to link them to a team leader
ALTER TABLE `hr_employees`
ADD COLUMN `manager_cin` VARCHAR(50) NULL DEFAULT NULL AFTER `cnss_number`,
ADD COLUMN `current_shift` ENUM('A', 'B', 'C', 'Normal') NULL DEFAULT NULL AFTER `manager_cin`;

-- 2. Create the Employee Timeline/History Table
CREATE TABLE IF NOT EXISTS `hr_employee_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `change_type` ENUM('TEAM_TRANSFER', 'FUNCTION_CHANGE', 'DEPT_CHANGE', 'STATUS_CHANGE', 'SHIFT_CHANGE') NOT NULL,
  `old_value` VARCHAR(255) NULL,
  `new_value` VARCHAR(255) NULL,
  `changed_by_cin` VARCHAR(50) NOT NULL COMMENT 'CIN of the user who made the change',
  `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `hr_employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create the Daily Team Pointage Table
CREATE TABLE IF NOT EXISTS `hr_team_attendance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `manager_cin` VARCHAR(50) NOT NULL COMMENT 'The Team Leader tracking this attendance',
  `attendance_date` DATE NOT NULL,
  `shift_code` ENUM('A', 'B', 'C', 'Normal') NOT NULL,
  `status` ENUM('Present', 'Absent', 'Sick', 'Transferred', 'Left') NOT NULL DEFAULT 'Present',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `hr_employees`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_daily_pointage` (`employee_id`, `attendance_date`, `shift_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: In the future, we can drop the old `workers` table entirely 
-- once we confirm this new system is fully operational.
