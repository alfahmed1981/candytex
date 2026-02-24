<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Attendance can be done by Admin OR Manager for their own team
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];

// Handle POST request to save attendance
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    require_csrf();
    $work_date = $_POST['work_date'];
    
    // Begin transaction
    try {
        $pdo->beginTransaction();
        
        $stmt_del = $pdo->prepare("DELETE FROM hr_attendance WHERE work_date = ? AND employee_id = ?");
        $stmt_ins = $pdo->prepare("INSERT INTO hr_attendance (employee_id, work_date, hours_worked, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($_POST['attendance'] as $emp_id => $data) {
            $hours = $data['hours'] !== '' ? floatval($data['hours']) : 0;
            $status = $data['status']; // 'P', 'A', 'W' (Weekend), etc.
            
            // Delete existing record for this date/employee to avoid duplicates
            $stmt_del->execute([$work_date, $emp_id]);
            
            // Insert new
            $stmt_ins->execute([$emp_id, $work_date, $hours, $status, $user_cin]);
        }
        
        $pdo->commit();
        audit_log($pdo, 'hr_save_attendance', "Saved attendance for date $work_date by $user_cin");
        $msg = "<script>Swal.fire('Success', 'Attendance saved successfully', 'success');</script>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<script>Swal.fire('Error', 'Failed to save attendance: " . addslashes($e->getMessage()) . "', 'error');</script>";
    }
}

// Filters
$selected_date = $_GET['date'] ?? date('Y-m-d');
$department_filter = $_GET['department'] ?? 'All';
$location_filter = $_GET['location_id'] ?? '';
$function_filter = $_GET['function_title'] ?? '';
$manager_filter = $_GET['manager_cin'] ?? '';

// Fetch Filter Lookup Data
$dept_stmt = $pdo->query("SELECT DISTINCT department FROM hr_employees WHERE department IS NOT NULL AND status='Active' ORDER BY department");
$departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

$loc_stmt = $pdo->query("SELECT * FROM locations ORDER BY name");
$all_locations = $loc_stmt->fetchAll(PDO::FETCH_ASSOC);

$func_stmt = $pdo->query("SELECT DISTINCT function_title FROM hr_employees WHERE function_title IS NOT NULL AND status='Active' ORDER BY function_title");
$all_functions = $func_stmt->fetchAll(PDO::FETCH_COLUMN);

$mgr_stmt = $pdo->query("SELECT cin, name FROM users WHERE role IN ('manager', 'admin') ORDER BY name");
$all_managers = $mgr_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Employees based on role and filters
$query = "SELECT e.id, e.matricule, e.full_name, e.function_title, e.department, 
                 COALESCE(a.hours_worked, '') as hours_worked, 
                 COALESCE(a.status, 'P') as att_status
          FROM hr_employees e 
          LEFT JOIN hr_attendance a ON e.id = a.employee_id AND a.work_date = ? 
          WHERE e.status = 'Active'";
$params = [$selected_date];

if (!$is_admin) {
    // Manager only sees their department (Assuming manager's department is in users table)
    $stmt_mgr = $pdo->prepare("SELECT department FROM users WHERE cin = ?");
    $stmt_mgr->execute([$user_cin]);
    $mgr_dept = $stmt_mgr->fetchColumn();
    
    if ($mgr_dept) {
        $query .= " AND e.department = ?";
        $params[] = $mgr_dept;
    } else {
        // Fallback: If manager has no dept set, they see nobody.
        $query .= " AND 1=0"; 
    }
} else {
    // Admin specific filters
    if ($department_filter !== 'All') {
        $query .= " AND e.department = ?";
        $params[] = $department_filter;
    }
    if ($location_filter) {
        $query .= " AND e.location_id = ?";
        $params[] = $location_filter;
    }
    if ($function_filter) {
        $query .= " AND e.function_title = ?";
        $params[] = $function_filter;
    }
    if ($manager_filter) {
        $query .= " AND e.manager_cin = ?";
        $params[] = $manager_filter;
    }
}

$query .= " ORDER BY e.department, e.full_name";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR - Daily Attendance</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f9fa; font-weight: bold; color: #333; }
        tr:hover { background-color: #f1f8ff; }
        .input-hours { width: 70px; padding: 5px; text-align: center; border: 1px solid #ccc; border-radius: 4px; }
        .select-status { padding: 5px; border: 1px solid #ccc; border-radius: 4px; }
        .status-P { background: #e8f5e9; color: #2e7d32; font-weight: bold; }
        .status-A { background: #ffebee; color: #c62828; font-weight: bold; }
        .status-W { background: #fff3e0; color: #ef6c00; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'includes/nav.php'; ?>
    <?= $msg ?>
    
    <div class="main-content">
        <div class="page-header">
            <div>
                <h2>🕒 Daily Timesheet / سجل الحضور اليومي</h2>
                <p>Record working hours for your team / تسجيل ساعات العمل</p>
            </div>
        </div>

        <!-- Date & Department Filter -->
        <div class="filter-card" style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="margin: 0;">
                    <label>Work Date / التاريخ</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" required style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                
                <?php if ($is_admin): ?>
                <div class="form-group" style="margin: 0;">
                    <label>Factory / المصنع</label>
                    <select name="location_id" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; min-width: 150px;">
                        <option value="">-- All Factories --</option>
                        <?php foreach ($all_locations as $loc): ?>
                            <option value="<?= $loc['id'] ?>" <?= $location_filter == $loc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label>Department / القسم</label>
                    <select name="department" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; min-width: 150px;">
                        <option value="All">-- All Depts --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= htmlspecialchars($dept) ?>" <?= $department_filter === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label>Function / الوظيفة</label>
                    <select name="function_title" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; min-width: 150px;">
                        <option value="">-- All Functions --</option>
                        <?php foreach ($all_functions as $func): ?>
                            <option value="<?= htmlspecialchars($func) ?>" <?= $function_filter === $func ? 'selected' : '' ?>><?= htmlspecialchars($func) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label>Team Leader / مسؤول الفريق</label>
                    <select name="manager_cin" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; min-width: 150px;">
                        <option value="">-- All Leaders --</option>
                        <?php foreach ($all_managers as $mgr): ?>
                            <option value="<?= htmlspecialchars($mgr['cin']) ?>" <?= $manager_filter === $mgr['cin'] ? 'selected' : '' ?>><?= htmlspecialchars($mgr['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn-save" style="background:#0b3c5d;">🔍 Load / عرض</button>
            </form>
        </div>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="work_date" value="<?= htmlspecialchars($selected_date) ?>">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <div style="font-weight: 600; color: #444;">
                    Employees found: <?= count($employees) ?>
                </div>
                <div>
                    <button type="button" class="btn-details" onclick="fillAll(9, 'P')">Fill All (9h) ✅</button>
                    <button type="button" class="btn-details" onclick="fillAll(0, 'W')" style="color: #ef6c00;">Weekend (****)</button>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Matricule</th>
                            <th>Full Name</th>
                            <th>Function</th>
                            <th>Department</th>
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:center;">Hours (e.g. 9 / 8.5)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): 
                            $row_class = 'status-' . $emp['att_status'];
                        ?>
                            <tr class="<?= $row_class ?>">
                                <td><?= htmlspecialchars($emp['matricule']) ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($emp['full_name']) ?></td>
                                <td><?= htmlspecialchars($emp['function_title']) ?></td>
                                <td><small style="background:#eee; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($emp['department'] ?: '-') ?></small></td>
                                <td style="text-align:center;">
                                    <select name="attendance[<?= $emp['id'] ?>][status]" class="select-status att-status" onchange="updateRowColor(this)">
                                        <option value="P" <?= $emp['att_status'] == 'P' ? 'selected' : '' ?>>Present (P)</option>
                                        <option value="A" <?= $emp['att_status'] == 'A' ? 'selected' : '' ?>>Absent (A)</option>
                                        <option value="W" <?= $emp['att_status'] == 'W' ? 'selected' : '' ?>>Weekend (****)</option>
                                    </select>
                                </td>
                                <td style="text-align:center;">
                                    <input type="number" step="0.5" min="0" max="24" name="attendance[<?= $emp['id'] ?>][hours]" value="<?= htmlspecialchars($emp['hours_worked']) ?>" class="input-hours att-hours" placeholder="e.g. 9">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($employees)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px;">No active employees found for this department.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($employees)): ?>
            <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                <button type="submit" name="save_attendance" class="btn-save" style="font-size: 1.1em; padding: 10px 30px;">💾 Save Attendance / حفظ الحضور</button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <script>
        function updateRowColor(selectElem) {
            const tr = selectElem.closest('tr');
            tr.className = 'status-' + selectElem.value;
            
            // Auto blank hours if A or W
            const hoursInput = tr.querySelector('.att-hours');
            if (selectElem.value === 'A' || selectElem.value === 'W') {
                hoursInput.value = '';
            } else if (selectElem.value === 'P' && hoursInput.value === '') {
                hoursInput.value = '9'; // Default to 9 if empty and marking present
            }
        }

        function fillAll(hours, status) {
            const trs = document.querySelectorAll('tbody tr');
            trs.forEach(tr => {
                const statusSelect = tr.querySelector('.att-status');
                const hoursInput = tr.querySelector('.att-hours');
                
                if (statusSelect && hoursInput) {
                    statusSelect.value = status;
                    if (status === 'A' || status === 'W') {
                        hoursInput.value = '';
                    } else {
                        hoursInput.value = hours;
                    }
                    updateRowColor(statusSelect);
                }
            });
        }
    </script>
</body>
</html>
