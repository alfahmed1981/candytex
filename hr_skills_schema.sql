-- HR Competence & Polyvalence Matrix (ISO 9001)
-- Maps employees to specific machines/skills and tracks their capability level

CREATE TABLE IF NOT EXISTS `skills_dictionary` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `skill_name` VARCHAR(150) UNIQUE NOT NULL,
    `skill_category` VARCHAR(100) DEFAULT 'Sewing',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `worker_skills` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `skill_id` INT NOT NULL,
    `level` TINYINT(1) CHECK (`level` BETWEEN 1 AND 4) DEFAULT 1,
    `evaluated_by_cin` VARCHAR(20) DEFAULT NULL,
    `last_evaluated_date` DATE DEFAULT NULL,
    FOREIGN KEY (`employee_id`) REFERENCES `hr_employees`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`skill_id`) REFERENCES `skills_dictionary`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_skill` (`employee_id`, `skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert Seed Skills based on general textile factory requirements
INSERT IGNORE INTO `skills_dictionary` (`skill_name`, `skill_category`) VALUES
('Juki Single Needle', 'Sewing'),
('Brother Overlock 5-thread', 'Sewing'),
('Pegasus Coverstitch', 'Sewing'),
('Fabric Cutting (Manual)', 'Cutting'),
('Fabric Cutting (Auto CNC)', 'Cutting'),
('Steam Iron Press', 'Finishing'),
('Folding & Packing', 'Packing'),
('Quality Inspection', 'Quality');
