-- 1. Add payment_type column to hr_employees
ALTER TABLE `hr_employees`
ADD COLUMN `payment_type` ENUM('Hourly', 'Monthly') NOT NULL DEFAULT 'Hourly' AFTER `hire_date`;
