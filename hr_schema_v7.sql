-- HR Schema Update V7 (CNSS & Moroccan Labor Law Compliance)
-- Run this via the Admin SQL tool or `mysql -u root candytex_dash < hr_schema_v7.sql`

-- Add CNSS and specific legal tracking fields to hr_absences
ALTER TABLE `hr_absences` 
ADD COLUMN `doctor_name` VARCHAR(100) DEFAULT NULL COMMENT 'Nom du médecin',
ADD COLUMN `doctor_inpe` VARCHAR(50) DEFAULT NULL COMMENT 'Identifiant National des Professionnels de la Santé',
ADD COLUMN `certificate_date` DATE DEFAULT NULL COMMENT 'Date the medical certificate was issued',
ADD COLUMN `maternity_expected_date` DATE DEFAULT NULL COMMENT 'Expected date of delivery (DPA)',
ADD COLUMN `accident_date` DATE DEFAULT NULL COMMENT 'Date of the work accident',
ADD COLUMN `accident_location` VARCHAR(255) DEFAULT NULL COMMENT 'Where the accident happened',
ADD COLUMN `extension_reason` TEXT DEFAULT NULL COMMENT 'Justification text for prolongations';
