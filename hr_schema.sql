-- HR Module Schema (To be appended to schema.sql or run via fix_db approach)

-- 1. Employees Table
CREATE TABLE IF NOT EXISTS `hr_employees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `matricule` VARCHAR(50) NOT NULL UNIQUE,
    `full_name` VARCHAR(150) NOT NULL,
    `function_title` VARCHAR(100) DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `hire_date` DATE DEFAULT NULL,
    `hourly_rate` DECIMAL(10,2) DEFAULT 0.00,
    `cnss_number` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Daily Attendance & Timesheet
CREATE TABLE IF NOT EXISTS `hr_attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `work_date` DATE NOT NULL,
    `hours_worked` DECIMAL(5,2) DEFAULT 0.00, -- e.g., 9.00, 8.50, 5.00
    `status` VARCHAR(20) DEFAULT 'P', -- 'P' = Present, 'A' = Absent, 'W' = Weekend/Holiday
    `recorded_by` VARCHAR(50) DEFAULT NULL, -- Admin or Manager who inputted this
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_emp_date` (`employee_id`, `work_date`),
    FOREIGN KEY (`employee_id`) REFERENCES `hr_employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Monthly Payroll Adjustments & Generated Output
CREATE TABLE IF NOT EXISTS `hr_payroll` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `payroll_month` INT NOT NULL, -- 1 to 12
    `payroll_year` INT NOT NULL,
    `period_start` DATE NOT NULL, -- e.g., 2025-11-26
    `period_end` DATE NOT NULL,   -- e.g., 2025-12-25
    
    -- Inputs / Adjustments
    `cnss_deduction` DECIMAL(10,2) DEFAULT 0.00,
    `transport_allowance` DECIMAL(10,2) DEFAULT 0.00,
    `advances` DECIMAL(10,2) DEFAULT 0.00,
    
    -- Calculated Outputs
    `total_hours` DECIMAL(10,2) DEFAULT 0.00,
    `brut_salary` DECIMAL(10,2) DEFAULT 0.00,
    `net_salary` DECIMAL(10,2) DEFAULT 0.00,
    `rounded_net` DECIMAL(10,2) DEFAULT 0.00,
    
    `status` ENUM('Draft', 'Finalized') DEFAULT 'Draft',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_payroll_period` (`employee_id`, `payroll_month`, `payroll_year`),
    FOREIGN KEY (`employee_id`) REFERENCES `hr_employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
