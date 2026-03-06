<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// HR Module access: Admins or dedicated HR role.
require_hr_or_admin();

$is_hr_only = is_hr();
$is_hr_admin_user = is_hr_admin();
$is_restricted_hr = $is_hr_only || $is_hr_admin_user; // Both HR and HR_Admin get location filtering
$hr_location_id = null;
if ($is_restricted_hr) {
    $stmt = $pdo->prepare("SELECT l.id FROM users u JOIN locations l ON u.location = l.name WHERE u.cin = ?");
    $stmt->execute([$_SESSION['user_cin']]);
    $hr_location_id = $stmt->fetchColumn();
    if (!$hr_location_id) {
        die("⛔ Your account is not properly assigned to a factory location. Please contact Admin.");
    }
}

// --- AJAX: Kinship Check ---
if (isset($_GET['action']) && $_GET['action'] === 'check_kinship') {
    header('Content-Type: application/json');
    $lastName = trim($_POST['last_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $empId = intval($_POST['emp_id'] ?? 0);

    $warnings = [];

    if (mb_strlen($lastName) > 2) {
        $stmt = $pdo->prepare("SELECT full_name FROM hr_employees WHERE last_name = ? AND id != ?");
        $stmt->execute([$lastName, $empId]);
        $matches = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($matches) {
            $warnings[] = "⚠️ Familial kinship detected (same surname): " . implode(', ', $matches);
        }
    }

    if (mb_strlen($address) > 10) {
        $stmt = $pdo->prepare("SELECT full_name FROM hr_employees WHERE address LIKE ? AND address != '' AND id != ?");
        $stmt->execute(['%' . $address . '%', $empId]);
        $matches = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($matches) {
            $warnings[] = "⚠️ Possible same residence as: " . implode(', ', $matches);
        }
    }

    echo json_encode(['warnings' => $warnings]);
    exit;
}

// --- SELF-HEALING DATABASE MIGRATION ---
try {
    $pdo->exec("ALTER TABLE hr_employees ADD COLUMN id_card_front VARCHAR(255) DEFAULT NULL, ADD COLUMN id_card_back VARCHAR(255) DEFAULT NULL, ADD COLUMN photo VARCHAR(255) DEFAULT NULL;");
} catch (PDOException $e) {
}

function processBase64Upload($b64_string, $prefix)
{
    if (empty($b64_string))
        return null;
    $parts = explode(';', $b64_string);
    if (count($parts) < 2)
        return null;
    $dataParts = explode(',', $parts[1]);
    if (count($dataParts) < 2)
        return null;
    $data = base64_decode($dataParts[1]);
    $filename = $prefix . '_' . uniqid() . '.jpg';
    $path = 'uploads/employees/' . $filename;
    if (!is_dir('uploads/employees'))
        mkdir('uploads/employees', 0777, true);
    file_put_contents($path, $data);
    return $path;
}

try {
    if (!function_exists('run_sql_file')) {
        function run_sql_file($pdo, $filename)
        {
            if (!file_exists($filename))
                return;
            $sql = file_get_contents($filename);
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $cleaned = trim($query);
                if (!empty($cleaned)) {
                    try {
                        $pdo->exec($cleaned);
                    } catch (PDOException $e) {
                    }
                }
            }
        }
    }
    run_sql_file($pdo, 'hr_schema_v2.sql');
    run_sql_file($pdo, 'hr_schema_v3.sql');
    run_sql_file($pdo, 'hr_schema_v4.sql');
    run_sql_file($pdo, 'hr_schema_v5.sql');
    run_sql_file($pdo, 'update_payment_types.sql'); // Auto-update payment types from Excel
} catch (Exception $e) {
}

// --- HANDLE POST REQUESTS (CRUD) ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Add Employee
    if (isset($_POST['add_emp'])) {
        $mat = trim($_POST['matricule']);
        $fname = trim($_POST['first_name']);
        $lname = trim($_POST['last_name']);
        $full_name = $fname . ' ' . $lname;
        $cin = trim($_POST['cin']);
        $dob = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $gender = $_POST['gender'];
        $marital = $_POST['marital_status'];
        $children = intval($_POST['children_count']);

        $phone = trim($_POST['phone_number']);
        $address = trim($_POST['address']);

        $func = trim($_POST['function_title']);
        $dept = trim($_POST['department']);
        $location_id = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
        if ($is_restricted_hr) {
            $location_id = $hr_location_id; // Force HR/HR_Admin to add to their own factory
        }
        $h_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $payment_type = $_POST['payment_type'] ?? 'Hourly';
        $rate = floatval($_POST['hourly_rate']);
        $cnss = trim($_POST['cnss_number']);
        $contract = trim($_POST['contract_type']);
        $manager_cin = !empty($_POST['manager_cin']) ? trim($_POST['manager_cin']) : null;
        $current_shift = !empty($_POST['current_shift']) ? trim($_POST['current_shift']) : null;

        $blood = trim($_POST['blood_group']);
        $em_contact = trim($_POST['emergency_contact']);
        $em_phone = trim($_POST['emergency_phone']);

        $id_card_front = processBase64Upload($_POST['id_front_b64'] ?? '', 'id_front_' . $cin);
        $id_card_back = processBase64Upload($_POST['id_back_b64'] ?? '', 'id_back_' . $cin);
        $photo = processBase64Upload($_POST['photo_b64'] ?? '', 'photo_' . $cin);

        try {
            // HR_Admin adds employees as pending_approval
            $emp_status = $is_hr_admin_user ? 'pending_approval' : 'Active';

            $stmt = $pdo->prepare("INSERT INTO hr_employees 
                (location_id, matricule, first_name, last_name, full_name, cin, date_of_birth, gender, marital_status, children_count, 
                 phone_number, address, function_title, department, manager_cin, current_shift, hire_date, payment_type, hourly_rate, cnss_number, contract_type, 
                 blood_group, emergency_contact, emergency_phone, id_card_front, id_card_back, photo, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $location_id,
                $mat,
                $fname,
                $lname,
                $full_name,
                $cin,
                $dob,
                $gender,
                $marital,
                $children,
                $phone,
                $address,
                $func,
                $dept,
                $manager_cin,
                $current_shift,
                $h_date,
                $payment_type,
                $rate,
                $cnss,
                $contract,
                $blood,
                $em_contact,
                $em_phone,
                $id_card_front,
                $id_card_back,
                $photo,
                $emp_status
            ]);

            $new_emp_id = $pdo->lastInsertId();

            // History Logging for Initial Assignment
            if ($manager_cin) {
                $pdo->prepare("INSERT INTO hr_employee_history (employee_id, change_type, new_value, changed_by_cin) VALUES (?, 'TEAM_TRANSFER', ?, ?)")
                    ->execute([$new_emp_id, $manager_cin, $_SESSION['user_cin']]);
            }
            if ($func) {
                $pdo->prepare("INSERT INTO hr_employee_history (employee_id, change_type, new_value, changed_by_cin) VALUES (?, 'FUNCTION_CHANGE', ?, ?)")
                    ->execute([$new_emp_id, $func, $_SESSION['user_cin']]);
            }

            audit_log($pdo, 'hr_add_employee', "Added Employee: $full_name ($mat)" . ($is_hr_admin_user ? ' [pending_approval]' : ''));
            $success_msg = $is_hr_admin_user ? 'Employee added as pending approval. Admin must approve before activation.' : 'Employee profile created successfully';
            $msg = "<script>Swal.fire('Success', '$success_msg', 'success');</script>";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Integrity constraint violation (Duplicate Matricule)
                $msg = "<script>Swal.fire('Error', 'Matricule/ID already exists!', 'error');</script>";
            } else {
                $msg = "<script>Swal.fire('Error', 'Database error: " . addslashes($e->getMessage()) . "', 'error');</script>";
            }
        }
    }
    // Edit Employee
    elseif (isset($_POST['edit_emp'])) {
        if ($is_restricted_hr)
            die("Unauthorized Action: HR Managers cannot edit employees.");
        $id = intval($_POST['emp_id']);
        $mat = trim($_POST['matricule']);
        $fname = trim($_POST['first_name']);
        $lname = trim($_POST['last_name']);
        $full_name = $fname . ' ' . $lname;
        $cin = trim($_POST['cin']);
        $dob = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $gender = $_POST['gender'];
        $marital = $_POST['marital_status'];
        $children = intval($_POST['children_count']);

        $phone = trim($_POST['phone_number']);
        $address = trim($_POST['address']);

        $func = trim($_POST['function_title']);
        $dept = trim($_POST['department']);
        $location_id = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
        $manager_cin = !empty($_POST['manager_cin']) ? trim($_POST['manager_cin']) : null;
        $current_shift = !empty($_POST['current_shift']) ? trim($_POST['current_shift']) : null;

        $h_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $payment_type = $_POST['payment_type'] ?? 'Hourly';
        $rate = floatval($_POST['hourly_rate']);
        $cnss = trim($_POST['cnss_number']);
        $contract = trim($_POST['contract_type']);

        $blood = trim($_POST['blood_group']);
        $em_contact = trim($_POST['emergency_contact']);
        $em_phone = trim($_POST['emergency_phone']);

        $status = $_POST['status'];

        try {
            // Fetch old metrics to compare for history logging
            $stmt_old = $pdo->prepare("SELECT function_title, department, manager_cin, current_shift, id_card_front, id_card_back, photo FROM hr_employees WHERE id=?");
            $stmt_old->execute([$id]);
            $old_emp = $stmt_old->fetch();

            $new_id_front = processBase64Upload($_POST['id_front_b64'] ?? '', 'id_front_' . $cin);
            $new_id_back = processBase64Upload($_POST['id_back_b64'] ?? '', 'id_back_' . $cin);
            $new_photo = processBase64Upload($_POST['photo_b64'] ?? '', 'photo_' . $cin);
            
            $id_card_front = $new_id_front ? $new_id_front : $old_emp['id_card_front'];
            $id_card_back = $new_id_back ? $new_id_back : $old_emp['id_card_back'];
            $photo = $new_photo ? $new_photo : $old_emp['photo'];

            $stmt = $pdo->prepare("UPDATE hr_employees SET 
                location_id=?, matricule=?, first_name=?, last_name=?, full_name=?, cin=?, date_of_birth=?, gender=?, marital_status=?, children_count=?, 
                phone_number=?, address=?, function_title=?, department=?, manager_cin=?, current_shift=?, hire_date=?, payment_type=?, hourly_rate=?, cnss_number=?, contract_type=?, 
                blood_group=?, emergency_contact=?, emergency_phone=?, status=?, id_card_front=?, id_card_back=?, photo=? 
                WHERE id=?");

            $stmt->execute([
                $location_id,
                $mat,
                $fname,
                $lname,
                $full_name,
                $cin,
                $dob,
                $gender,
                $marital,
                $children,
                $phone,
                $address,
                $func,
                $dept,
                $manager_cin,
                $current_shift,
                $h_date,
                $payment_type,
                $rate,
                $cnss,
                $contract,
                $blood,
                $em_contact,
                $em_phone,
                $status,
                $id_card_front,
                $id_card_back,
                $photo,
                $id
            ]);

            // Track Historial Changes
            if ($old_emp) {
                if ($old_emp['manager_cin'] !== $manager_cin) {
                    $pdo->prepare("INSERT INTO hr_employee_history (employee_id, change_type, old_value, new_value, changed_by_cin) VALUES (?, 'TEAM_TRANSFER', ?, ?, ?)")
                        ->execute([$id, $old_emp['manager_cin'], $manager_cin, $_SESSION['user_cin']]);
                }
                if ($old_emp['function_title'] !== $func) {
                    $pdo->prepare("INSERT INTO hr_employee_history (employee_id, change_type, old_value, new_value, changed_by_cin) VALUES (?, 'FUNCTION_CHANGE', ?, ?, ?)")
                        ->execute([$id, $old_emp['function_title'], $func, $_SESSION['user_cin']]);
                }
                if ($old_emp['department'] !== $dept) {
                    $pdo->prepare("INSERT INTO hr_employee_history (employee_id, change_type, old_value, new_value, changed_by_cin) VALUES (?, 'DEPT_CHANGE', ?, ?, ?)")
                        ->execute([$id, $old_emp['department'], $dept, $_SESSION['user_cin']]);
                }
                if ($old_emp['current_shift'] !== $current_shift) {
                    $pdo->prepare("INSERT INTO hr_employee_history (employee_id, change_type, old_value, new_value, changed_by_cin) VALUES (?, 'SHIFT_CHANGE', ?, ?, ?)")
                        ->execute([$id, $old_emp['current_shift'], $current_shift, $_SESSION['user_cin']]);
                }
            }

            audit_log($pdo, 'hr_edit_employee', "Updated Employee ID: $id ($full_name)");
            $msg = "<script>Swal.fire('Success', 'Employee profile updated successfully', 'success');</script>";
        } catch (PDOException $e) {
            $msg = "<script>Swal.fire('Error', 'Update failed: " . addslashes($e->getMessage()) . "', 'error');</script>";
        }
    }
    // Delete Employee
    elseif (isset($_POST['delete_emp'])) {
        if ($is_restricted_hr)
            die("Unauthorized Action: HR Managers cannot delete employees.");
        $id = intval($_POST['emp_id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM hr_employees WHERE id=?");
            $stmt->execute([$id]);
            audit_log($pdo, 'hr_delete_employee', "Deleted Employee ID: $id");
            $msg = "<script>Swal.fire('Deleted', 'Employee removed completely', 'info');</script>";
        } catch (PDOException $e) {
            $msg = "<script>Swal.fire('Error', 'Cannot delete employee (might have attendance records)', 'error');</script>";
        }
    }
}

// --- FETCH EMPLOYEES ---
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'Active';
$dept_filter = $_GET['department'] ?? '';
$func_filter = $_GET['function_title'] ?? '';
$location_filter = $_GET['location_id'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'id_desc';

$query = "SELECT * FROM hr_employees WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (full_name LIKE ? OR matricule LIKE ? OR cin LIKE ? OR phone_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter !== 'All') {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}
if ($dept_filter) {
    $query .= " AND department = ?";
    $params[] = $dept_filter;
}
if ($func_filter) {
    $query .= " AND function_title = ?";
    $params[] = $func_filter;
}

// Enforce Location Filtering
if ($is_restricted_hr) {
    $query .= " AND location_id = ?";
    $params[] = $hr_location_id;
} else if ($location_filter) {
    $query .= " AND location_id = ?";
    $params[] = $location_filter;
}

switch ($sort_by) {
    case 'name_asc':
        $query .= " ORDER BY full_name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY full_name DESC";
        break;
    case 'rate_desc':
        $query .= " ORDER BY hourly_rate DESC";
        break;
    case 'rate_asc':
        $query .= " ORDER BY hourly_rate ASC";
        break;
    case 'id_asc':
        $query .= " ORDER BY id ASC";
        break;
    default:
        $query .= " ORDER BY id DESC";
        break;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct departments and functions for the dropdowns
$depts = $pdo->query("SELECT DISTINCT department FROM hr_employees WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
$funcs = $pdo->query("SELECT DISTINCT function_title FROM hr_employees WHERE function_title IS NOT NULL AND function_title != '' ORDER BY function_title")->fetchAll(PDO::FETCH_COLUMN);

// Fetch admin advanced dropdown data
$all_locations = $pdo->query("SELECT * FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$all_shifts = $pdo->query("SELECT * FROM shifts ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$all_managers = $pdo->query("SELECT * FROM users WHERE role IN ('manager', 'admin') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Batch Historical Logs
$histories = [];
try {
    $hist = $pdo->query("SELECT h.*, u.name as acting_user 
                         FROM hr_employee_history h 
                         LEFT JOIN users u ON h.changed_by_cin = u.cin 
                         ORDER BY h.changed_at DESC");
    foreach ($hist->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $histories[$row['employee_id']][] = $row;
    }
} catch (Exception $e) {
}


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR - Advanced Employee Directory</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .hr-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .employee-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #0b3c5d;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .employee-card.inactive {
            border-left-color: #dc3545;
            opacity: 0.8;
        }

        .emp-info h4 {
            margin: 0 0 5px 0;
            color: #0b3c5d;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .emp-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85em;
            color: #666;
            margin-top: 5px;
            flex-wrap: wrap;
        }

        .emp-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
            background: #f1f8ff;
            padding: 2px 6px;
            border-radius: 4px;
        }

        /* Classes for view toggling */
        .field-cnss,
        .field-dept,
        .field-cin,
        .field-phone {
            display: none;
        }

        /* Hidden by default to avoid clutter */

        .toggle-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            background: #eef2f5;
            border-radius: 4px;
            flex-wrap: wrap;
            font-size: 0.9em;
        }

        .toggle-controls label {
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .emp-actions {
            display: flex;
            gap: 8px;
            flex-direction: column;
            align-items: flex-end;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.85em;
            border-radius: 4px;
        }

        .badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: bold;
        }

        .badge.active {
            background: #d4edda;
            color: #155724;
        }

        .badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        /* Modal Tabs */
        .tab-buttons {
            display: flex;
            border-bottom: 2px solid #ddd;
            margin-bottom: 20px;
        }

        .tab-btn {
            background: none;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: bold;
            color: #666;
            font-size: 1em;
            outline: none;
        }

        .tab-btn.active-tab {
            border-bottom: 3px solid #0984e3;
            color: #0984e3;
            margin-bottom: -2px;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-row.three {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .form-group label {
            font-size: 0.9em;
            font-weight: bold;
            color: #444;
            margin-bottom: 5px;
            display: block;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }

        .history-timeline {
            position: relative;
            padding-left: 20px;
            margin-top: 20px;
        }

        .history-timeline::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 2px;
            background: #0984e3;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            top: 5px;
            left: -25px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid #0984e3;
        }

        .timeline-date {
            font-size: 0.8em;
            color: #666;
            margin-bottom: 3px;
        }

        .timeline-content {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            font-size: 0.9em;
            border: 1px solid #ddd;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
</head>

<body>
    <?php include 'includes/nav.php'; ?>
    <?= $msg ?>

    <div class="main-content">
        <div class="hr-header">
            <div>
                <h2>👥 Human Resources / Ressources Humaines / الموارد البشرية</h2>
                <p>Employee Profiles, ISO & CNSS / Profils des Employés / ملفات الموظفين المفصلة</p>
            </div>
            <div style="display:flex; gap:10px;">
                <button class="btn-save" style="background:#28a745;" onclick="exportEmployeesToExcel()">📥 Export
                    Excel</button>
                <button class="btn-save" onclick="openAddModal()">➕ Add / Ajouter</button>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card"
            style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">

                <div style="flex:1; min-width:200px;">
                    <label style="font-size:0.85em; font-weight:bold;">Search / Recherche</label>
                    <input type="text" name="search" placeholder="Name, ID, CIN, Phone..."
                        value="<?= htmlspecialchars($search) ?>"
                        style="width:100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>

                <div>
                    <label style="font-size:0.85em; font-weight:bold;">Factory / المصنع</label>
                    <select name="location_id" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">-- All Factories --</option>
                        <?php foreach ($all_locations as $loc): ?>
                            <option value="<?= $loc['id'] ?>" <?= $location_filter == $loc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-size:0.85em; font-weight:bold;">Department / القسم</label>
                    <select name="department" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">-- All Depts --</option>
                        <?php foreach ($depts as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $dept_filter === $d ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-size:0.85em; font-weight:bold;">Function / الوظيفة</label>
                    <select name="function_title" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">-- All Functions --</option>
                        <?php foreach ($funcs as $f): ?>
                            <option value="<?= htmlspecialchars($f) ?>" <?= $func_filter === $f ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="font-size:0.85em; font-weight:bold;">Status</label>
                    <select name="status" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?>>🟢 Active</option>
                        <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?>>🔴 Inactive</option>
                        <option value="All" <?= $status_filter == 'All' ? 'selected' : '' ?>>🌍 All</option>
                    </select>
                </div>

                <div>
                    <label style="font-size:0.85em; font-weight:bold;">Sort By / ترتيب</label>
                    <select name="sort_by" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="id_desc" <?= $sort_by == 'id_desc' ? 'selected' : '' ?>>Newest First</option>
                        <option value="id_asc" <?= $sort_by == 'id_asc' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="name_asc" <?= $sort_by == 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                        <option value="name_desc" <?= $sort_by == 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                        <option value="rate_desc" <?= $sort_by == 'rate_desc' ? 'selected' : '' ?>>Highest Salary</option>
                        <option value="rate_asc" <?= $sort_by == 'rate_asc' ? 'selected' : '' ?>>Lowest Salary</option>
                    </select>
                </div>

                <div style="padding-bottom:1px;">
                    <button type="submit" class="btn-save" style="background: #1a6b8a; height: 35px; line-height: 1;">🔍
                        Filter</button>
                    <a href="hr_employees.php" class="btn-details"
                        style="height: 35px; line-height: 1.2; display: inline-block; text-align: center;">Reset</a>
                </div>
            </form>
        </div>

        <!-- Field Visibility Toggles -->
        <div class="toggle-controls">
            <strong>👁️ Show/Hide Columns:</strong>
            <label><input type="checkbox" onchange="toggleField('cin', this)"> CIN</label>
            <label><input type="checkbox" onchange="toggleField('phone', this)"> Phone</label>
            <label><input type="checkbox" onchange="toggleField('dept', this)"> Department</label>
            <label><input type="checkbox" onchange="toggleField('cnss', this)"> CNSS</label>
        </div>

        <!-- Employee List -->
        <div class="employee-list">
            <?php if (empty($employees)): ?>
                <div class="empty-state" style="text-align: center; padding: 40px; color: #666;">
                    No employees found matching your criteria.
                </div>
            <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                    <div class="employee-card <?= strtolower($emp['status']) ?>">
                        <div class="emp-info">
                            <h4>
                                <?= htmlspecialchars($emp['full_name']) ?>
                                <span class="badge <?= strtolower($emp['status']) ?>"><?= $emp['status'] ?></span>
                            </h4>
                            <div class="emp-meta">
                                <span title="Matricule" class="field-mat">🆔 <?= htmlspecialchars($emp['matricule']) ?></span>
                                <span title="Function" class="field-func">💼
                                    <?= htmlspecialchars($emp['function_title'] ?: 'N/A') ?></span>
                                <span title="Department" class="field-dept">🏢
                                    <?= htmlspecialchars($emp['department'] ?: 'N/A') ?></span>
                                <span title="Salary" class="field-rate">💰 <?= number_format($emp['hourly_rate'], 2) ?>
                                    <?= $emp['payment_type'] === 'Monthly' ? 'MAD/month' : 'MAD/h' ?></span>

                                <?php if ($emp['cin']): ?><span title="CIN" class="field-cin">💳
                                        <?= htmlspecialchars($emp['cin']) ?></span><?php endif; ?>
                                <?php if ($emp['phone_number']): ?><span title="Phone" class="field-phone">📞
                                        <?= htmlspecialchars($emp['phone_number']) ?></span><?php endif; ?>
                                <?php if ($emp['cnss_number']): ?><span title="CNSS" class="field-cnss">🛡️
                                        <?= htmlspecialchars($emp['cnss_number']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="emp-actions">
                            <?php if (!$is_restricted_hr): ?>
                            <button class="btn-save btn-sm" style="background:#0984e3;"
                                onclick='openEditModal(<?= json_encode($emp) ?>)'>✏️ Edit Profile</button>
                            <form method="POST"
                                onsubmit="return confirm('Are you sure you want to completely delete this employee?');"
                                style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                <button type="submit" name="delete_emp" class="btn-save btn-sm" style="background:#d63031;">🗑️
                                    Delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ADVANCED ADD/EDIT MODAL -->
    <div id="empModal" class="modal-overlay"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
        <div class="modal"
            style="background:white; padding:25px; border-radius:8px; width:95%; max-width:800px; max-height:95vh; overflow-y:auto;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 id="modalTitle" style="margin-top:0; color:#0b3c5d;">Add Profile / Ajouter / إضافة موظف</h3>
                <button type="button" onclick="closeModal()"
                    style="background:none; border:none; font-size:1.5em; cursor:pointer;">&times;</button>
            </div>

            <form method="POST" id="empForm">
                <?= csrf_field() ?>
                <input type="hidden" name="emp_id" id="emp_id">
                <input type="hidden" name="add_emp" id="form_action" value="1">

                <!-- Tabs Navigation -->
                <div class="tab-buttons">
                    <button type="button" class="tab-btn active-tab" onclick="openTab('tab-personal', this)">👤 Personal
                        / Personnel</button>
                    <button type="button" class="tab-btn" onclick="openTab('tab-work', this)">💼 Work / Travail</button>
                    <button type="button" class="tab-btn" onclick="openTab('tab-safety', this)">🚑 ISO 45001</button>
                    <button type="button" class="tab-btn" onclick="openTab('tab-documents', this)">🪪 Documents / الوثائق</button>
                    <button type="button" class="tab-btn" onclick="openTab('tab-history', this)" id="historyTabBtn"
                        style="display:none;">📜 History / سجل الحركات</button>
                </div>

                <!-- TAB 1: Personal & Contact -->
                <div id="tab-personal" class="tab-content active">
                    <input type="hidden" name="photo_b64" id="photo_b64">
                    
                    <div style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #ddd; text-align:center; margin-bottom: 15px;">
                        <label style="color:#0984e3;">Profile Photo / صورة شخصية</label>
                        
                        <div id="view_photo_container" style="display:none; margin-bottom: 10px;">
                            <img id="view_photo_img" src="" style="width:120px; height:120px; object-fit:cover; border-radius:50%; border:2px solid #1565c0; display:block; margin:0 auto 10px;">
                        </div>

                        <video id="video-photo" autoplay playsinline muted style="width:100%; max-width:200px; display:none; border:1px solid #ccc; border-radius:8px; margin:0 auto;"></video>
                        <canvas id="canvas-photo" style="display:none;"></canvas>
                        <img id="preview-photo" style="width:100%; max-width:200px; display:none; border:1px solid #ccc; border-radius:8px; margin:5px auto;" />
                        
                        <div style="margin-top:10px; display:flex; gap:5px; justify-content:center; flex-wrap:wrap;">
                            <button type="button" id="btn-start-photo" class="btn-primary" style="background:#0288d1; padding:6px 12px; font-size:0.85em;" onclick="startIdCamera('photo')">📸 Start Camera</button>
                            <button type="button" id="btn-cap-photo" class="btn-primary" style="background:#28a745; padding:6px 12px; font-size:0.85em; display:none;" onclick="captureIdImage('photo')">✅ Capture</button>
                            <button type="button" id="btn-retake-photo" class="btn-primary" style="background:#f57c00; padding:6px 12px; font-size:0.85em; display:none;" onclick="retakeIdImage('photo')">🔄 Retake</button>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Matricule / ID / الرقم الاستدلالي (Required)*</label>
                            <input type="text" name="matricule" id="m_matricule" required>
                        </div>
                        <div class="form-group">
                            <label>CIN / Carte d'Identité / رقم البطاقة</label>
                            <input type="text" name="cin" id="m_cin">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name / Prénom / الاسم الشخصي*</label>
                            <input type="text" name="first_name" id="m_first" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name / Nom / الاسم العائلي*</label>
                            <input type="text" name="last_name" id="m_last" required>
                        </div>
                    </div>

                    <div id="kinshipWarning"
                        style="display:none; color:#d63031; background:#fadbd8; padding:10px; border-radius:4px; margin-bottom:15px; font-weight:bold; font-size:0.9em;">
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label>Date of Birth / Date de Naissance / تاريخ الازدياد</label>
                            <input type="date" name="date_of_birth" id="m_dob">
                        </div>
                        <div class="form-group">
                            <label>Gender / Sexe / الجنس</label>
                            <select name="gender" id="m_gender">
                                <option value="Male">Male / Homme / ذكر 👨</option>
                                <option value="Female">Female / Femme / أنثى 👩</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Marital Status / Situation / عائلي</label>
                            <select name="marital_status" id="m_marital">
                                <option value="Single">Single / Célibataire / أعزب</option>
                                <option value="Married">Married / Marié(e) / متزوج</option>
                                <option value="Divorced">Divorced / Divorcé(e) / مطلق</option>
                                <option value="Widowed">Widowed / Veuf(ve) / أرمل</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Children / Enfants / عدد الأطفال (CNSS)</label>
                            <input type="number" name="children_count" id="m_children" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label>Phone Number / N° Téléphone / رقم الهاتف</label>
                            <input type="text" name="phone_number" id="m_phone">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Home Address / Adresse / العنوان</label>
                        <textarea name="address" id="m_address" rows="2"></textarea>
                    </div>
                </div>

                <!-- TAB 2: Work & Payroll -->
                <div id="tab-work" class="tab-content">

                    <div class="form-row">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Location / Factory / المصنع</label>
                            <select name="location_id" id="m_location">
                                <option value="">-- Select Factory --</option>
                                <?php foreach ($all_locations as $loc): ?>
                                    <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Function / Fonction / الوظيفة (Optional)</label>
                            <input type="text" name="function_title" id="m_function" list="funcList"
                                placeholder="Select or type a function...">
                            <datalist id="funcList">
                                <?php foreach ($funcs as $f): ?>
                                    <option value="<?= htmlspecialchars($f) ?>">
                                    <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="form-group">
                            <label>Department / Département / القسم</label>
                            <select name="department" id="m_dept">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($all_departments as $ad): ?>
                                    <option value="<?= htmlspecialchars($ad['name']) ?>">
                                        <?= htmlspecialchars($ad['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Team Leader / رئيس الفريق</label>
                            <select name="manager_cin" id="m_manager">
                                <option value="">-- No Direct Leader --</option>
                                <?php foreach ($all_managers as $mgr): ?>
                                    <option value="<?= $mgr['cin'] ?>"><?= htmlspecialchars($mgr['name']) ?>
                                        (<?= $mgr['cin'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Shift / الفترة</label>
                            <select name="current_shift" id="m_shift">
                                <option value="">-- No Shift assigned --</option>
                                <?php foreach ($all_shifts as $sh): ?>
                                    <option value="<?= $sh['code'] ?>"><?= htmlspecialchars($sh['name']) ?>
                                        (<?= $sh['code'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label>Hire Date / D. embauche / التوظيف</label>
                            <input type="date" name="hire_date" id="m_hire">
                        </div>
                        <div class="form-group">
                            <label>Contract Type / Contrat / نوع العقد</label>
                            <select name="contract_type" id="m_contract">
                                <option value="CDI">CDI</option>
                                <option value="CDD">CDD</option>
                                <option value="ANAPEC">ANAPEC</option>
                                <option value="Interim">Interim / موقت</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>CNSS / Identifiant / رقم الضمان</label>
                            <input type="text" name="cnss_number" id="m_cnss">
                        </div>
                    </div>

                    <div class="form-row"
                        style="background:#f8f9fa; padding:15px; border-radius:4px; border:1px solid #ddd;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="color:#28a745;">Payment Type / نوع الدفع*</label>
                            <select name="payment_type" id="m_payment_type" required
                                style="font-size:1.1em; font-weight:bold; color:#28a745;">
                                <option value="Hourly">Hourly / الأجر بالساعة</option>
                                <option value="Monthly">Monthly / الراتب الشهري</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="color:#28a745;">Rate / Salary / الراتب (MAD)*</label>
                            <input type="number" step="0.01" name="hourly_rate" id="m_rate" value="9.00" required
                                style="font-size:1.2em; font-weight:bold; color:#28a745;">
                        </div>
                        <div class="form-group" id="statusGroup" style="display:none; margin-bottom:0;">
                            <label>Status / Statut / الحالة</label>
                            <select name="status" id="m_status" style="font-weight:bold;">
                                <option value="Active">Active / Actif / نشط 🟢</option>
                                <option value="Inactive">Inactive / Inactif / غير نشط 🔴</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: ISO 45001 Safety -->
                <div id="tab-safety" class="tab-content">
                    <div
                        style="background:#e8f5e9; padding:15px; border-radius:4px; margin-bottom:15px; color:#2e7d32; font-size:0.9em;">
                        <strong>ISO 45001 Compliance:</strong> Required fields for occupational health and safety
                        emergency protocols.
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Blood Group / Groupe Sanguin / فصيلة الدم</label>
                            <select name="blood_group" id="m_blood">
                                <option value="">Unknown</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Emergency Contact / Contact d'Urgence / اتصال الطوارئ</label>
                            <input type="text" name="emergency_contact" id="m_em_contact">
                        </div>
                        <div class="form-group">
                            <label>Emergency Phone / Tél. d'Urgence / هاتف الطوارئ</label>
                            <input type="text" name="emergency_phone" id="m_em_phone">
                        </div>
                    </div>
                </div>

                <!-- TAB 5: Documents / ID Cards -->
                <div id="tab-documents" class="tab-content">
                    <div style="background:#e3f2fd; padding:15px; border-radius:4px; margin-bottom:15px; color:#1565c0; font-size:0.9em;">
                        <strong>ID Cards / البطاقة الوطنية:</strong> Please capture the front and back of the employee's ID card for verification.
                    </div>
                    
                    <input type="hidden" name="id_front_b64" id="id_front_b64">
                    <input type="hidden" name="id_back_b64" id="id_back_b64">

                    <div class="form-row">
                        <!-- ID Front -->
                        <div class="form-group" style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #ddd; text-align:center;">
                            <label style="color:#0984e3;">Front ID Card / الواجهة الأمامية</label>
                            
                            <div id="view_id_front_container" style="display:none; margin-bottom: 10px;">
                                <a id="view_id_front_link" href="#" target="_blank" class="btn-primary" style="background:#1565c0; text-decoration:none; padding:5px 10px; display:inline-block; border-radius:4px;">👁️ View Saved Front ID</a>
                            </div>

                            <video id="video-front" autoplay playsinline muted style="width:100%; max-width:300px; display:none; border:1px solid #ccc; border-radius:4px; margin:0 auto;"></video>
                            <canvas id="canvas-front" style="display:none;"></canvas>
                            <img id="preview-front" style="width:100%; max-width:300px; display:none; border:1px solid #ccc; border-radius:4px; margin:5px auto;" />
                            
                            <div style="margin-top:10px; display:flex; gap:5px; justify-content:center; flex-wrap:wrap;">
                                <button type="button" id="btn-start-front" class="btn-primary" style="background:#0288d1; padding:6px 12px; font-size:0.85em;" onclick="startIdCamera('front')">📸 Start Camera</button>
                                <button type="button" id="btn-cap-front" class="btn-primary" style="background:#28a745; padding:6px 12px; font-size:0.85em; display:none;" onclick="captureIdImage('front')">✅ Capture</button>
                                <button type="button" id="btn-retake-front" class="btn-primary" style="background:#f57c00; padding:6px 12px; font-size:0.85em; display:none;" onclick="retakeIdImage('front')">🔄 Retake</button>
                            </div>
                        </div>

                        <!-- ID Back -->
                        <div class="form-group" style="background:#f8f9fa; padding:15px; border-radius:8px; border:1px solid #ddd; text-align:center;">
                            <label style="color:#0984e3;">Back ID Card / الواجهة الخلفية</label>
                            
                            <div id="view_id_back_container" style="display:none; margin-bottom: 10px;">
                                <a id="view_id_back_link" href="#" target="_blank" class="btn-primary" style="background:#1565c0; text-decoration:none; padding:5px 10px; display:inline-block; border-radius:4px;">👁️ View Saved Back ID</a>
                            </div>

                            <video id="video-back" autoplay playsinline muted style="width:100%; max-width:300px; display:none; border:1px solid #ccc; border-radius:4px; margin:0 auto;"></video>
                            <canvas id="canvas-back" style="display:none;"></canvas>
                            <img id="preview-back" style="width:100%; max-width:300px; display:none; border:1px solid #ccc; border-radius:4px; margin:5px auto;" />
                            
                            <div style="margin-top:10px; display:flex; gap:5px; justify-content:center; flex-wrap:wrap;">
                                <button type="button" id="btn-start-back" class="btn-primary" style="background:#0288d1; padding:6px 12px; font-size:0.85em;" onclick="startIdCamera('back')">📸 Start Camera</button>
                                <button type="button" id="btn-cap-back" class="btn-primary" style="background:#28a745; padding:6px 12px; font-size:0.85em; display:none;" onclick="captureIdImage('back')">✅ Capture</button>
                                <button type="button" id="btn-retake-back" class="btn-primary" style="background:#f57c00; padding:6px 12px; font-size:0.85em; display:none;" onclick="retakeIdImage('back')">🔄 Retake</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: History / Ségil -->
                <div id="tab-history" class="tab-content">
                    <div
                        style="background:#fff3cd; padding:15px; border-radius:4px; margin-bottom:15px; color:#856404; font-size:0.9em;">
                        <strong>Historical Log:</strong> This tab records function changes, team transfers, and shift
                        alterations chronologically.
                    </div>

                    <div id="employeeHistoryContainer" class="history-timeline">
                        <!-- Populated by JS -->
                    </div>
                </div>

                <!-- Form Submit -->
                <div
                    style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #ddd; padding-top:15px;">
                    <span style="font-size:0.85em; color:#666;">* Required Fields</span>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn-details" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn-save" id="modalSubmitBtn"
                            style="padding:10px 25px; font-size:1.1em;">💾 Save / Enregistrer / حفظ</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openTab(tabId, btnElem) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active-tab'));
            document.getElementById(tabId).classList.add('active');
            btnElem.classList.add('active-tab');
        }

        // Camera ID logic
        let activeStreams = { front: null, back: null, photo: null };

        async function startIdCamera(side) {
            try {
                // Stop others
                ['front', 'back', 'photo'].forEach(s => {
                    if (s !== side) stopCamera(s);
                });

                const constraints = { video: { facingMode: (side === 'photo' ? "user" : "environment") }, audio: false };
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                
                activeStreams[side] = stream;

                const video = document.getElementById(`video-${side}`);
                video.srcObject = stream;
                video.style.display = 'block';
                
                document.getElementById(`preview-${side}`).style.display = 'none';
                document.getElementById(`btn-start-${side}`).style.display = 'none';
                document.getElementById(`btn-cap-${side}`).style.display = 'inline-block';
                document.getElementById(`btn-retake-${side}`).style.display = 'none';
            } catch (err) {
                console.error("Camera error:", err);
                alert('Camera access denied or not available. / الكاميرا غير متاحة.');
            }
        }

        function stopCamera(side) {
            let stream = activeStreams[side];
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                activeStreams[side] = null;
            }
            const video = document.getElementById(`video-${side}`);
            if (video) video.style.display = 'none';
        }

        function captureIdImage(side) {
            const video = document.getElementById(`video-${side}`);
            const canvas = document.getElementById(`canvas-${side}`);
            const preview = document.getElementById(`preview-${side}`);
            const stream = activeStreams[side];
            
            if (!stream) return;

            let targetWidth = video.videoWidth;
            let targetHeight = video.videoHeight;
            const MAX_WIDTH = side === 'photo' ? 600 : 1024;
            
            if (targetWidth > MAX_WIDTH) {
                targetHeight = Math.round(targetHeight * (MAX_WIDTH / targetWidth));
                targetWidth = MAX_WIDTH;
            }
            canvas.width = targetWidth || MAX_WIDTH;
            canvas.height = targetHeight || (MAX_WIDTH * 0.75);
            
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const b64 = canvas.toDataURL('image/jpeg', 0.8);
            document.getElementById(side === 'photo' ? `photo_b64` : `id_${side}_b64`).value = b64;
            
            preview.src = b64;
            preview.style.display = 'block';
            
            stopCamera(side);
            document.getElementById(`btn-cap-${side}`).style.display = 'none';
            document.getElementById(`btn-retake-${side}`).style.display = 'inline-block';
        }

        function retakeIdImage(side) {
            document.getElementById(side === 'photo' ? `photo_b64` : `id_${side}_b64`).value = '';
            document.getElementById(`preview-${side}`).src = '';
            document.getElementById(`preview-${side}`).style.display = 'none';
            startIdCamera(side);
        }

        function stopAllCameras() {
            ['front', 'back', 'photo'].forEach(s => stopCamera(s));
        }

        function resetCameraUI(side) {
            document.getElementById(side === 'photo' ? `photo_b64` : `id_${side}_b64`).value = '';
            document.getElementById(`preview-${side}`).src = '';
            document.getElementById(`preview-${side}`).style.display = 'none';
            document.getElementById(`btn-start-${side}`).style.display = 'inline-block';
            document.getElementById(`btn-cap-${side}`).style.display = 'none';
            document.getElementById(`btn-retake-${side}`).style.display = 'none';
            let container = document.getElementById(`view_${side === 'photo' ? 'photo' : 'id_' + side}_container`);
            if(container) container.style.display = 'none';
        }

        // Full History Data embedded for JS
        const employeeHistories = <?= json_encode($histories) ?>;

        function openAddModal() {
            document.getElementById('modalTitle').innerText = '➕ Add Profile / Ajouter / إضافة موظف';
            document.getElementById('empForm').reset();
            document.getElementById('emp_id').value = '';
            document.getElementById('form_action').name = 'add_emp';
            document.getElementById('statusGroup').style.display = 'none';
            document.getElementById('modalSubmitBtn').innerText = '💾 Save / Enregistrer / حفظ';
            document.getElementById('historyTabBtn').style.display = 'none'; // Hide history when adding

            resetCameraUI('front');
            resetCameraUI('back');
            resetCameraUI('photo');

            // Go to first tab
            openTab('tab-personal', document.querySelector('.tab-buttons .tab-btn:first-child'));
            document.getElementById('empModal').style.display = 'flex';
        }

        function openEditModal(emp) {
            document.getElementById('modalTitle').innerText = '✏️ Edit / Modifier / تعديل: ' + emp.full_name;

            // Hidden data
            document.getElementById('emp_id').value = emp.id;
            document.getElementById('form_action').name = 'edit_emp';

            // Personal
            document.getElementById('m_matricule').value = emp.matricule;
            document.getElementById('m_cin').value = emp.cin || '';
            document.getElementById('m_first').value = emp.first_name || '';
            document.getElementById('m_last').value = emp.last_name || '';
            document.getElementById('m_dob').value = emp.date_of_birth || '';
            document.getElementById('m_gender').value = emp.gender || 'Male';
            document.getElementById('m_marital').value = emp.marital_status || 'Single';
            document.getElementById('m_children').value = emp.children_count || 0;
            document.getElementById('m_phone').value = emp.phone_number || '';
            document.getElementById('m_address').value = emp.address || '';

            // Work
            document.getElementById('m_location').value = emp.location_id || '';
            document.getElementById('m_function').value = emp.function_title || '';
            document.getElementById('m_dept').value = emp.department || '';
            document.getElementById('m_manager').value = emp.manager_cin || '';
            document.getElementById('m_shift').value = emp.current_shift || '';
            document.getElementById('m_hire').value = emp.hire_date || '';
            document.getElementById('m_contract').value = emp.contract_type || 'CDI';
            document.getElementById('m_cnss').value = emp.cnss_number || '';
            document.getElementById('m_payment_type').value = emp.payment_type || 'Hourly';
            document.getElementById('m_rate').value = emp.hourly_rate;

            // ISO 45001
            document.getElementById('m_blood').value = emp.blood_group || '';
            document.getElementById('m_em_contact').value = emp.emergency_contact || '';
            document.getElementById('m_em_phone').value = emp.emergency_phone || '';

            // Extras
            document.getElementById('m_status').value = emp.status || 'Active';
            document.getElementById('statusGroup').style.display = 'block';

            document.getElementById('modalSubmitBtn').innerText = '💾 Update / Modifier / تحديث';

            resetCameraUI('front');
            resetCameraUI('back');
            resetCameraUI('photo');

            if (emp.photo) {
                document.getElementById('view_photo_container').style.display = 'block';
                document.getElementById('view_photo_img').src = emp.photo;
            }
            if (emp.id_card_front) {
                document.getElementById('view_id_front_container').style.display = 'block';
                document.getElementById('view_id_front_link').href = emp.id_card_front;
            }
            if (emp.id_card_back) {
                document.getElementById('view_id_back_container').style.display = 'block';
                document.getElementById('view_id_back_link').href = emp.id_card_back;
            }

            // Build History Timeline
            document.getElementById('historyTabBtn').style.display = 'inline-block';
            const histContainer = document.getElementById('employeeHistoryContainer');
            histContainer.innerHTML = '';

            if (employeeHistories[emp.id] && employeeHistories[emp.id].length > 0) {
                employeeHistories[emp.id].forEach(h => {
                    let text = `Changed <b>${h.change_type}</b>`;
                    if (h.old_value) text += ` from <i>${h.old_value}</i>`;
                    if (h.new_value) text += ` to <b>${h.new_value}</b>`;

                    histContainer.innerHTML += `
                        <div class="timeline-item">
                            <div class="timeline-date">🗓️ ${h.changed_at} - <small>By: ${h.acting_user}</small></div>
                            <div class="timeline-content">${text}</div>
                        </div>
                    `;
                });
            } else {
                histContainer.innerHTML = '<p style="color:#999;font-style:italic;">No historical records found for this employee yet.</p>';
            }

            // Go to first tab
            openTab('tab-personal', document.querySelector('.tab-buttons .tab-btn:first-child'));
            document.getElementById('empModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('empModal').style.display = 'none';
            stopAllCameras();
        }

        // Field Toggle Logic
        function toggleField(fieldClass, checkbox) {
            const elements = document.querySelectorAll('.field-' + fieldClass);
            elements.forEach(el => {
                el.style.display = checkbox.checked ? 'flex' : 'none';
            });
            // Save preference to localStorage so it persists across refreshes
            localStorage.setItem('hr_pref_' + fieldClass, checkbox.checked);
        }

        // Load preferences on page load
        window.addEventListener('DOMContentLoaded', () => {
            const toggles = ['cin', 'phone', 'dept', 'cnss'];
            toggles.forEach(t => {
                const isChecked = localStorage.getItem('hr_pref_' + t) === 'true';
                const cb = document.querySelector(`input[onchange*="toggleField('${t}'"]`);
                if (cb) {
                    cb.checked = isChecked;
                    if (isChecked) toggleField(t, cb); // apply state
                }
            });

            // Kinship Check Triggers
            const lnameInput = document.getElementById('m_last');
            const addressInput = document.getElementById('m_address');

            let kinshipTimeout;
            function runKinshipCheck() {
                clearTimeout(kinshipTimeout);
                kinshipTimeout = setTimeout(() => {
                    const lname = lnameInput.value;
                    const addr = addressInput.value;
                    const empId = document.getElementById('emp_id').value;

                    if (lname.trim().length > 2 || addr.trim().length > 10) {
                        const formData = new FormData();
                        formData.append('last_name', lname);
                        formData.append('address', addr);
                        formData.append('emp_id', empId);

                        fetch('hr_employees.php?action=check_kinship', {
                            method: 'POST',
                            body: formData
                        })
                            .then(r => r.json())
                            .then(data => {
                                const warningDiv = document.getElementById('kinshipWarning');
                                if (data.warnings && data.warnings.length > 0) {
                                    warningDiv.style.display = 'block';
                                    warningDiv.innerHTML = data.warnings.join('<br>');
                                } else {
                                    warningDiv.style.display = 'none';
                                    warningDiv.innerHTML = '';
                                }
                            })
                            .catch(err => console.error('Kinship check error:', err));
                    } else {
                        document.getElementById('kinshipWarning').style.display = 'none';
                    }
                }, 800);
            }

            lnameInput.addEventListener('input', runKinshipCheck);
            addressInput.addEventListener('input', runKinshipCheck);
        });

        // --- EXCEL EXPORT LOGIC ---
        function exportEmployeesToExcel() {
            Swal.fire({
                title: 'Exporting...',
                text: 'Fetching employee data, please wait.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('api_export_employees.php')
                .then(response => {
                    if (!response.ok) throw new Error("API Network response was not ok");
                    return response.json();
                })
                .then(res => {
                    if (res.error) {
                        Swal.fire('Error', res.error, 'error');
                        return;
                    }

                    if (!res.data || res.data.length === 0) {
                        Swal.fire('Info', 'No active employees to export.', 'info');
                        return;
                    }

                    // Create SheetJS Worksheet
                    const ws = XLSX.utils.json_to_sheet(res.data);

                    // Create an empty Workbook
                    const wb = XLSX.utils.book_new();

                    // Append the Worksheet to the Workbook
                    XLSX.utils.book_append_sheet(wb, ws, "Employees");

                    // Generate Excel file and trigger download
                    const today = new Date().toISOString().split('T')[0];
                    XLSX.writeFile(wb, `CandyTex_Employees_${today}.xlsx`);

                    Swal.fire('Success!', `Exported ${res.count} employees to Excel.`, 'success');
                })
                .catch(err => {
                    Swal.fire('Error', 'Failed to export data: ' + err.message, 'error');
                });
        }
    </script>
</body>

</html>