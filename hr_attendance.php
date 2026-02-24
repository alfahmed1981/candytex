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
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
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
            <?php if ($is_admin): ?>
            <button class="btn-primary" onclick="openImportModal()" style="background:#28a745; display:flex; gap:5px; align-items:center;">
                📊 Import Excel (PAIE)
            </button>
            <?php endif; ?>
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
                    <label>Department (CNSS) / القسم</label>
                    <select name="department" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; min-width: 150px;">
                        <option value="All">-- All --</option>
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

    <?php if ($is_admin): ?>
    <!-- Excel Import Modal -->
    <div id="importModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(0,0,0,0.5);">
        <div class="modal-content" style="background-color:#fefefe; margin:15% auto; padding:20px; border:1px solid #888; width:400px; border-radius:8px;">
            <span onclick="closeImportModal()" style="color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
            <h2>Import PAIE Excel</h2>
            <p style="color:#666; font-size:0.9em; margin-bottom:15px;">Please upload the standard monthly PAIE Excel file. The system will extract days 26 to 25 from both <b>HORAIRE</b> and <b>mens</b> sheets efficiently.</p>
            <input type="file" id="excelFile" accept=".xlsx, .xls" style="margin-bottom: 20px; width: 100%;">
            <button onclick="closeImportModal()" class="btn-cancel">Cancel</button>
        </div>
    </div>

    <script>
        function openImportModal() {
            document.getElementById('importModal').style.display = 'block';
        }
        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
        }

        document.getElementById('excelFile').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, {type: 'array'});
                    
                    const sheetNamesToProcess = ['HORAIRE', 'mens', 'MENS', 'Mens'];
                    let foundAnySheet = false;
                    const records = [];

                    // Dates mapping (Nov 26 to Dec 25 mapped to cols 7 to 36)
                    const dates = [];
                    for(let d=26; d<=30; d++) dates.push(`2025-11-${d}`);
                    for(let d=1; d<=25; d++) dates.push(`2025-12-${String(d).padStart(2, '0')}`);

                    sheetNamesToProcess.forEach(sheetName => {
                        if(workbook.Sheets[sheetName]) {
                            foundAnySheet = true;
                            const sheet = workbook.Sheets[sheetName];
                            const rowData = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: null});

                            // Data starts at row index 3
                            for (let i = 3; i < rowData.length; i++) {
                                const row = rowData[i];
                                if (!row || row.length < 5) continue;

                                let matricule = row[0];
                                if (!matricule) continue;
                                matricule = String(matricule).trim();
                                
                                // Check if numeric matricule
                                if (isNaN(parseInt(matricule))) continue;

                                // Column C check (some employees might have CNSS info, we skip or use it as needed. Let's just focus on dates 7-36)
                                for (let col = 7; col <= 36; col++) {
                                    const dateStr = dates[col - 7];
                                    let cellValue = row[col];
                                    
                                    if (cellValue === null || cellValue === undefined || cellValue === '') continue; // skip blank
                                    
                                    cellValue = String(cellValue).trim().toUpperCase();
                                    
                                    let status = 'P';
                                    let hours = 0;

                                    if (cellValue === 'A') {
                                        status = 'A';
                                    } else if (cellValue === 'W' || cellValue.includes('*')) {
                                        status = 'W';
                                    } else {
                                        let parsed = parseFloat(cellValue);
                                        if (!isNaN(parsed)) {
                                            status = 'P';
                                            hours = parsed;
                                        } else {
                                            continue; // empty/unknown
                                        }
                                    }

                                    records.push({
                                        matricule: matricule,
                                        date: dateStr,
                                        hours: hours,
                                        status: status
                                    });
                                }
                            }
                        }
                    });

                    if (!foundAnySheet) {
                        Swal.fire('Error', 'Could not find HORAIRE or mens sheets in the Excel file.', 'error');
                        return;
                    }

                    if (records.length === 0) {
                        Swal.fire('Information', 'No valid attendance data found to import.', 'info');
                        return;
                    }

                    // Confirm and Send to API
                    Swal.fire({
                        title: 'Ready to Import',
                        text: `Found ${records.length} daily pointage records across multiple sheets. Do you want to save them to the database?`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Import Now',
                        showLoaderOnConfirm: true,
                        preConfirm: () => {
                            return fetch('api_import_attendance.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?>'
                                },
                                body: JSON.stringify({ records: records })
                            })
                            .then(response => {
                                if (!response.ok) {
                                    return response.json().then(err => { throw new Error(err.error || response.statusText) });
                                }
                                return response.json();
                            })
                            .catch(error => {
                                Swal.showValidationMessage(`Request failed: ${error}`);
                            })
                        },
                        allowOutsideClick: () => !Swal.isLoading()
                    }).then((result) => {
                        if (result.isConfirmed) {
                            Swal.fire({
                                title: 'Success!',
                                text: result.value.message,
                                icon: 'success'
                            }).then(() => {
                                closeImportModal();
                                window.location.reload();
                            });
                        }
                    });

                } catch(err) {
                    Swal.fire('Error', 'Failed to parse Excel file: ' + err.message, 'error');
                }
            };
            reader.readAsArrayBuffer(file);
        });
    </script>
    <?php endif; ?>
</body>
</html>
