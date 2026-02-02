-- Database: candytex_dash

-- 1. Users Table (Staff & Admins)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cin` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `role` ENUM('admin', 'manager', 'viewer') DEFAULT 'manager',
    `password` VARCHAR(255) DEFAULT NULL, -- Nullable for CIN/Phone login compatibility
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Departments / Areas
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `manager_id` INT DEFAULT NULL,
    FOREIGN KEY (`manager_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. SQDC Daily Records
CREATE TABLE IF NOT EXISTS `sqdc_daily` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_cin` VARCHAR(20) NOT NULL, -- Linking by CIN for now (easier migration)
    `day_date` DATE NOT NULL,
    `category` ENUM('S', 'Q', 'D', '5S', 'C') NOT NULL,
    `status` ENUM('green', 'orange', 'red', 'blue', 'gray') DEFAULT 'gray',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_entry` (`user_cin`, `day_date`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Countermeasures
CREATE TABLE IF NOT EXISTS `countermeasures` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_cin` VARCHAR(20) NOT NULL,
    `issue` TEXT NOT NULL,
    `action_plan` TEXT NOT NULL,
    `responsible` VARCHAR(100),
    `due_date` DATE,
    `status` ENUM('Open', 'In Progress', 'Done') DEFAULT 'Open',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
