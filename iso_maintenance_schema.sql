-- ISO 9001 Maintenance & TPM Module Schema (CandyTex)
-- Includes Machines inventory and breakdown ticket tracking for Downtime (D in SQDC)

CREATE TABLE IF NOT EXISTS `machines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `machine_code` VARCHAR(50) UNIQUE NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `type` VARCHAR(50) DEFAULT 'Sewing',
    `location_id` INT DEFAULT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('Running', 'Down', 'Under Maintenance') DEFAULT 'Running',
    `last_maintenance_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maintenance_tickets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_number` VARCHAR(30) UNIQUE NOT NULL,
    `machine_id` INT NOT NULL,
    `reported_by_cin` VARCHAR(20) NOT NULL,
    `issue_description` TEXT NOT NULL,
    `priority` ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
    `status` ENUM('Open', 'In Progress', 'Resolved', 'Closed') DEFAULT 'Open',
    `downtime_minutes` INT DEFAULT 0,
    `resolved_by_cin` VARCHAR(20) DEFAULT NULL,
    `resolution_notes` TEXT DEFAULT NULL,
    `parts_replaced` TEXT DEFAULT NULL,
    `reported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME DEFAULT NULL,
    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert some dummy/seed machines if the table is empty
INSERT IGNORE INTO `machines` (`machine_code`, `name`, `type`, `department`, `status`) VALUES
('JUKI-001', 'Juki Basic Sewing', 'Sewing Machine', 'Sewing', 'Running'),
('JUKI-002', 'Juki Basic Sewing', 'Sewing Machine', 'Sewing', 'Running'),
('BRO-001', 'Brother Overlock', 'Overlock', 'Sewing', 'Running'),
('CUT-001', 'Automatic Fabric Cutter', 'Cutting', 'Cutting', 'Running'),
('IRON-001', 'Steam Press Machine', 'Finishing', 'Finishing', 'Running');
