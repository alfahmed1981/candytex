---
description: Master System Documentation (MD) and Architecture Skill for AI Agents
---

# CandyTex ISO Dashboard - Master System Documentation

## 🎯 Project Vision & Core Objective
**"To control factory management, increase the efficiency, transparency, and security of manufacturing operations, with optimal readiness to obtain ISO 9001 certification."**

This document serves as the **Master System Documentation (MD)** and a **Skill** file for AI agents working on the CandyTex ISO Dashboard. It outlines the system's architecture, core functionalities, database schema, security model, and UI/UX approach. All future developments must align with the core objective above.

## 1. System Architecture

The CandyTex Dashboard is a monolithic web application built with:
*   **Backend:** PHP 7.x+ (Procedural and PDO object-oriented).
*   **Database:** MySQL / MariaDB.
*   **Frontend:** HTML5, Vanilla CSS (`style.css`), and Vanilla JavaScript.
*   **Libraries:** SweetAlert2 (for modals and confirmations), Chart.js (for analytics - if applicable).
*   **Architecture Pattern:** Page-Controller pattern. Each module (e.g., `iso_docs.php`, `iso_ncr.php`, `sqdc_board.php`) acts as its own controller handling both the UI rendering and POST request processing (CRUD operations).

## 2. Core Modules & Functionality

The system is designed to manage factory production efficiency and ISO 9001 compliance.

### a. User & Roles Management (`users.php`, `auth.php`, `my_team.php`)
*   **Authentication:** Session-based login using `user_cin` (National ID) and `password`.
*   **Roles (RBAC):**
    *   `admin`: Full access to all modules, can delete records, change statuses, manage users.
    *   `manager` (Team Leader): Can create records (SQDC, NCR, CAR), manage their assigned team (`my_team.php`), but cannot delete critical ISO records.
    *   `viewer`: Read-only access to specific dashboards.
*   **Impersonation:** Admins can "impersonate" managers to debug issues (`auth.php -> handle_impersonation()`).

### b. SQDC Daily Management (`sqdc_board.php`, `sqdc_input.php`)
*   Tracks Safety (S), Quality (Q), Delivery (D), 5S, and Cost/Custom (C) daily metrics.
*   Color-coded statuses (Green = Good, Red = Bad, etc.).
*   Managers log counter-measures (`countermeasures` table) for any red/orange statuses.

### c. ISO Document Control (`iso_docs.php`)
*   Manages the lifecycle of standard operating procedures, manuals, and records.
*   **Fields:** Document Number, Titles (EN/AR), Category (SOP, WI, Policy), Status (Draft, Under Review, Active, Obsolete).
*   **Revisions:** Tracks version history (`doc_revisions` table).

### d. Non-Conformities & Corrective Actions (`iso_ncr.php`)
*   **NCR (Non-Conformity Report):** Logs product/process defects. Includes dual-language descriptions, severity, and root source.
*   **CAR (Corrective Action Report):** Linked to NCRs. Tracks Root Cause Analysis, Corrective Actions, and Preventive Actions. Admin/Quality team verifies effectiveness.
*   Uses a "Smart UI" mapping generic English descriptions to Arabic translations automatically based on the selected defect type.

### e. Human Resources & Payroll (`hr_employees.php`, `hr_attendance.php`, `hr_payroll.php`)
*   **Employee Management:** Tracks matricules, functions, and hourly rates (`Taux`).
*   **Daily Attendance:** Managers/Admins can record daily working hours (e.g., 9, 8.5) or statuses (A for Absent, `****` for Weekend).
*   **Payroll Engine:** Automatically calculates salaries from the 26th of the previous month to the 25th of the current.
    *   **Logic:** `BRUT = Hours * Rate`. `NET = BRUT - CNSS - Advances + Transport`.
    *   **Rounding:** The final `ARROND` Net Salary is automatically rounded to the nearest 10 MAD using `ceil(net / 10) * 10`.

## 3. Database Schema Overview

The database uses InnoDB engine and `utf8mb4` encoding.

*   **Core Tables:**
    *   `users`: Staff records, credentials, roles.
    *   `departments`, `locations`, `shifts`: Factory structure lookups.
*   **HR Tables:**
    *   `hr_employees`: Static employee data and hourly rates.
    *   `hr_attendance`: Daily timesheet hours recorded by managers.
    *   `hr_payroll`: Generated monthly payslips and mathematical adjustments.
*   **SQDC Tables:**
    *   `sqdc_daily`: Daily color status per user per category.
    *   `countermeasures`: Action plans for issues.
*   **ISO Tables:**
    *   `iso_documents` & `doc_revisions`: Document control.
    *   `ncr_reports`: Non-conformity tracking.
    *   `car_reports`: Corrective action tracking.
*   **System Tables:**
    *   `audit_log`: Automatically records critical user actions (Creates, Updates, Deletes, Logins).

## 4. Security & Best Practices

When modifying this system, AI agents MUST adhere to these security rules:

1.  **Authentication Guard:** At the top of every secured file, include:
    ```php
    session_start();
    require 'db.php';
    require 'includes/auth.php';
    if (!isset($_SESSION['user_cin'])) { header("Location: index.php"); exit; }
    ```
2.  **CSRF Protection:** All forms modifying data MUST include `<?= csrf_token_field() ?>`. All POST handlers MUST begin with `require_csrf();`.
3.  **SQL Injection Prevention:** ALWAYS use PDO prepared statements (`$pdo->prepare() -> execute()`). Never interpolate variables directly into SQL strings.
4.  **Role Checks:** Use `$is_admin = ($_SESSION['role'] === 'admin');` to conditionally show UI buttons or allow backend deletes (`if (isset($_POST['delete']) && $is_admin) { ... }`).
5.  **Audit Logging:** Whenever a critical insert/update/delete occurs, call `audit_log($pdo, 'action_name', 'Details');`.
6.  **PHP Compatibility:** The deployment server runs **PHP 7.x**. Do NOT use PHP 8+ exclusive features such as `match()` expressions, Nullsafe operators (`?->`), or named arguments. Use classic `switch` statements and `if/elseif`.

## 5. UI/UX Guidelines

*   **Responsive Design:** Use CSS Flexbox and CSS Grid.
*   **Color Palette:** Clean, professional. Primary: `#0b3c5d`, Secondary: `#1a6b8a`, Danger: `#dc3545`, Success: `#28a745`.
*   **Modals:** Use the custom `.modal-overlay` and `.modal` classes for creating popups.
*   **Interactions:** Prefer `SweetAlert2` (`Swal.fire`) for delete confirmations or success messages instead of native `window.confirm`.

## 6. How to Build a New Module (Checklist for AI)

1.  Create the database table in `schema.sql` (and add a self-healing `CREATE TABLE IF NOT EXISTS` at the top of the new PHP file).
2.  Create the PHP file (e.g., `new_feature.php`) adopting the Page-Controller pattern.
3.  Include security headers and role checks.
4.  Write the POST handling logic at the top (under security checks), wrapped in `if ($_SERVER['REQUEST_METHOD'] === 'POST')`.
5.  Write the SQL SELECT queries to fetch data for the UI.
6.  Write the HTML structure using the standard `.page-header` and `.container` classes.
7.  Add links to the new module in `includes/nav.php`.

## 7. Complete Project File Structure (PHP Pages)

Here is a comprehensive list of all `.php` files in the project categorized by functionality:

### Auth, Configuration & Components
*   `db.php`: PDO database connection setup and `.env` variable loading.
*   `global.php`: Global constants, utility functions, and shared configs.
*   `includes/auth.php`: Core security file (Session guards, RBAC roles, CSRF protection, Audit logging).
*   `includes/nav.php`: The main responsive sidebar/topbar navigation menu.
*   `includes/smtp_send.php`: Email sending utility script (PHPMailer/native wrapper).

### Public & User Profile
*   `index.php`: The login page and entry point to the system.
*   `edit_profile.php`: Allows logged-in users to update their personal information and passwords.
*   `complete_profile.php`: Forces new/pending users to complete their info upon first login.
*   `guide.php`: System guide, user manuals, and technical help.

### Admin Dashboard & Management Tools
*   `admin.php`: Main admin panel for user management, role assignments, and impersonation.
*   `admin_advanced.php`: Advanced system settings and lookup data management (Locations, Departments).
*   `admin_backup.php`: Interface for triggering and downloading database backups.
*   `admin_daily.php`: Executive overview of daily SQDC inputs across all departments.
*   `admin_discipline.php`: Interface to track HR/Disciplinary actions for staff.
*   `admin_email.php`: Interface to manage SMTP configurations stored in the database.
*   `admin_issues.php`: Centralized management of all countermeasures and pending issues.
*   `admin_reports.php`: General reporting, data export (Excel/CSV), and analytics.

### Human Resources (HR)
*   `hr_employees.php`: Master directory of all employees, their functions, and hourly pay rates.
*   `hr_attendance.php`: Smart grid for managers to log daily hours worked.
*   `hr_payroll.php`: The payroll generator, aggregating hours (26th to 25th) and calculating final rounded NET pay.

### Quality & ISO 9001 Modules (Core)
*   `iso_docs.php`: Main Document Control module (Revisions, Attachments, Status tracking).
*   `iso_doc_print.php`: Clean, printable format of ISO document details.
*   `iso_ncr.php`: Non-Conformity (NCR) & Corrective Action (CAR) tracking system.
*   `iso_risk.php`: Risk Assessment form and mitigation strategy tracking.

### Operations & Team Management
*   `my_team.php`: Dashboard for Managers (Team Leaders) to see and manage their direct workers.
*   `meetings.php`: SQDC Meeting agendas, minutes, and attendance tracking.
*   `meetings_print.php`: Printable format for meeting minutes.

### Utilities & Scripts
*   `api.php`: Backend JSON API endpoints (typically used for Chart.js data or async requests).
*   `import_users.php`: Utility script for bulk-importing users via CSV.
