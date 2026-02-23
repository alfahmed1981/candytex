-- Advanced HR Schema Migration (Adding Missing ISO & CNSS Fields)

-- We use ALTER TABLE to safely add columns.
-- Using `IGNORE` or catching errors in PHP if columns already exist.

ALTER TABLE `hr_employees`
ADD COLUMN `first_name` VARCHAR(100) AFTER `matricule`,
ADD COLUMN `last_name` VARCHAR(100) AFTER `first_name`,
ADD COLUMN `cin` VARCHAR(50) AFTER `full_name`,
ADD COLUMN `date_of_birth` DATE NULL AFTER `cin`,
ADD COLUMN `phone_number` VARCHAR(50) NULL AFTER `date_of_birth`,
ADD COLUMN `address` TEXT NULL AFTER `phone_number`,
ADD COLUMN `gender` ENUM('Male', 'Female') DEFAULT 'Male' AFTER `address`,
ADD COLUMN `marital_status` ENUM('Single', 'Married', 'Divorced', 'Widowed') DEFAULT 'Single' AFTER `gender`,
ADD COLUMN `children_count` INT DEFAULT 0 AFTER `marital_status`,
ADD COLUMN `contract_type` VARCHAR(50) NULL AFTER `cnss_number`,
ADD COLUMN `blood_group` VARCHAR(10) NULL AFTER `contract_type`,
ADD COLUMN `emergency_contact` VARCHAR(150) NULL AFTER `blood_group`,
ADD COLUMN `emergency_phone` VARCHAR(50) NULL AFTER `emergency_contact`;
