ALTER TABLE `hr_employees` ADD COLUMN `location_id` INT NULL DEFAULT NULL AFTER `id`;
UPDATE `hr_employees` SET `location_id` = (SELECT id FROM `locations` WHERE `name` LIKE '%Flora%' LIMIT 1) WHERE `location_id` IS NULL;
