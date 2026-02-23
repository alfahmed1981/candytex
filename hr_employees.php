<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// HR Module access: Admins or dedicated HR role. For now, we restrict to Admin.
require_admin();

// --- SELF-HEALING DATABASE MIGRATION ---
try {
    $pdo->exec(file_get_contents('hr_schema.sql'));
} catch (Exception $e) {
    // Ignore if tables exist
}

// --- HANDLE POST REQUESTS (CRUD) ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Add Employee
    if (isset($_POST['add_emp'])) {
        $mat = trim($_POST['matricule']);
        $name = trim($_POST['full_name']);
        $func = trim($_POST['function_title']);
        $dept = trim($_POST['department']);
        $h_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $rate = floatval($_POST['hourly_rate']);
        $cnss = trim($_POST['cnss_number']);

        try {
            $stmt = $pdo->prepare("INSERT INTO hr_employees (matricule, full_name, function_title, department, hire_date, hourly_rate, cnss_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$mat, $name, $func, $dept, $h_date, $rate, $cnss]);
            audit_log($pdo, 'hr_add_employee', "Added Employee: $name ($mat)");
            $msg = "<script>Swal.fire('Success', 'Employee added successfully', 'success');</script>";
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
        $id = intval($_POST['emp_id']);
        $mat = trim($_POST['matricule']);
        $name = trim($_POST['full_name']);
        $func = trim($_POST['function_title']);
        $dept = trim($_POST['department']);
        $h_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $rate = floatval($_POST['hourly_rate']);
        $cnss = trim($_POST['cnss_number']);
        $status = $_POST['status'];

        try {
            $stmt = $pdo->prepare("UPDATE hr_employees SET matricule=?, full_name=?, function_title=?, department=?, hire_date=?, hourly_rate=?, cnss_number=?, status=? WHERE id=?");
            $stmt->execute([$mat, $name, $func, $dept, $h_date, $rate, $cnss, $status, $id]);
            audit_log($pdo, 'hr_edit_employee', "Updated Employee ID: $id ($name)");
            $msg = "<script>Swal.fire('Success', 'Employee updated successfully', 'success');</script>";
        } catch (PDOException $e) {
            $msg = "<script>Swal.fire('Error', 'Update failed!', 'error');</script>";
        }
    }
    // Delete Employee (Real world HR usually just inactivates, but we'll add admin delete to be safe)
    elseif (isset($_POST['delete_emp'])) {
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

$query = "SELECT * FROM hr_employees WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (full_name LIKE ? OR matricule LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter !== 'All') {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR - Employee Directory</title>
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
        }

        .emp-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85em;
            color: #666;
        }

        .emp-meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .emp-actions {
            display: flex;
            gap: 8px;
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
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>
    <?= $msg ?>

    <div class="main-content">
        <div class="hr-header">
            <div>
                <h2>👥 Human Resources / الموارد البشرية</h2>
                <p>Employee Directory & Rates / دليل الموظفين وأجورهم</p>
            </div>
            <button class="btn-save" onclick="openAddModal()">➕ Add Employee</button>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="text" name="search" placeholder="Search by name or ID..."
                    value="<?= htmlspecialchars($search) ?>"
                    style="flex:1; min-width: 200px; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <select name="status" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?>>🟢 Active Only</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?>>🔴 Inactive Only
                    </option>
                    <option value="All" <?= $status_filter == 'All' ? 'selected' : '' ?>>🌍 All Employees</option>
                </select>
                <button type="submit" class="btn-save" style="background: #1a6b8a;">🔍 Filter</button>
            </form>
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
                                <span class="badge <?= strtolower($emp['status']) ?>">
                                    <?= $emp['status'] ?>
                                </span>
                            </h4>
                            <div class="emp-meta">
                                <span title="Matricule">🆔
                                    <?= htmlspecialchars($emp['matricule']) ?>
                                </span>
                                <span title="Function">💼
                                    <?= htmlspecialchars($emp['function_title'] ?: 'N/A') ?>
                                </span>
                                <span title="Hourly Rate">💰
                                    <?= number_format($emp['hourly_rate'], 2) ?> MAD/h
                                </span>
                                <?php if ($emp['hire_date']): ?>
                                    <span title="Hire Date">📅
                                        <?= date('d/m/Y', strtotime($emp['hire_date'])) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="emp-actions">
                            <button class="btn-save btn-sm" style="background:#0984e3;"
                                onclick='openEditModal(<?= json_encode($emp) ?>)'>✏️ Edit</button>
                            <!-- Admins can hard delete -->
                            <form method="POST"
                                onsubmit="return confirm('Are you sure you want to completely delete this employee?');"
                                style="display:inline;">
                                <?= csrf_token_field() ?>
                                <input type="hidden" name="emp_id" value="<?= $emp['id'] ?>">
                                <button type="submit" name="delete_emp" class="btn-save btn-sm" style="background:#d63031;">🗑️
                                    Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ADD/EDIT MODAL -->
    <div id="empModal" class="modal-overlay"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
        <div class="modal"
            style="background:white; padding:25px; border-radius:8px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto;">
            <h3 id="modalTitle" style="margin-top:0;">Add New Employee</h3>
            <form method="POST" id="empForm">
                <?= csrf_token_field() ?>
                <input type="hidden" name="emp_id" id="emp_id">
                <input type="hidden" name="add_emp" id="form_action" value="1">

                <div class="form-row"
                    style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group">
                        <label>Matricule / ID (Required)*</label>
                        <input type="text" name="matricule" id="m_matricule" required style="width:100%; padding:8px;">
                    </div>
                    <div class="form-group">
                        <label>Full Name / الاسم الكامل*</label>
                        <input type="text" name="full_name" id="m_full_name" required style="width:100%; padding:8px;">
                    </div>
                </div>

                <div class="form-row"
                    style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group">
                        <label>Function / الوظيفة (Excel: Fonction)</label>
                        <input type="text" name="function_title" id="m_function" style="width:100%; padding:8px;">
                    </div>
                    <div class="form-group">
                        <label>Department / القسم</label>
                        <input type="text" name="department" id="m_dept" style="width:100%; padding:8px;">
                    </div>
                </div>

                <div class="form-row"
                    style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-bottom:15px;">
                    <div class="form-group">
                        <label>Hourly Rate (MAD/h)*</label>
                        <input type="number" step="0.01" name="hourly_rate" id="m_rate" value="9.00" required
                            style="width:100%; padding:8px;">
                    </div>
                    <div class="form-group">
                        <label>Hire Date (D emb)</label>
                        <input type="date" name="hire_date" id="m_hire" style="width:100%; padding:8px;">
                    </div>
                    <div class="form-group">
                        <label>CNSS Number</label>
                        <input type="text" name="cnss_number" id="m_cnss" style="width:100%; padding:8px;">
                    </div>
                </div>

                <div class="form-group" id="statusGroup" style="display:none; margin-bottom:15px;">
                    <label>Status / الحالة</label>
                    <select name="status" id="m_status" style="width:100%; padding:8px;">
                        <option value="Active">Active 🟢</option>
                        <option value="Inactive">Inactive 🔴</option>
                    </select>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <button type="button" class="btn-details" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-save" id="modalSubmitBtn">Save Employee</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerText = '➕ Add New Employee';
            document.getElementById('empForm').reset();
            document.getElementById('emp_id').value = '';
            document.getElementById('form_action').name = 'add_emp';
            document.getElementById('statusGroup').style.display = 'none';
            document.getElementById('modalSubmitBtn').innerText = 'Save Employee';
            document.getElementById('empModal').style.display = 'flex';
        }

        function openEditModal(emp) {
            document.getElementById('modalTitle').innerText = '✏️ Edit Employee';
            document.getElementById('emp_id').value = emp.id;
            document.getElementById('m_matricule').value = emp.matricule;
            document.getElementById('m_full_name').value = emp.full_name;
            document.getElementById('m_function').value = emp.function_title;
            document.getElementById('m_dept').value = emp.department;
            document.getElementById('m_rate').value = emp.hourly_rate;
            document.getElementById('m_hire').value = emp.hire_date || '';
            document.getElementById('m_cnss').value = emp.cnss_number;
            document.getElementById('m_status').value = emp.status;

            document.getElementById('form_action').name = 'edit_emp';
            document.getElementById('statusGroup').style.display = 'block'; // Show status on edit
            document.getElementById('modalSubmitBtn').innerText = 'Update Employee';
            document.getElementById('empModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('empModal').style.display = 'none';
        }
    </script>
</body>

</html>