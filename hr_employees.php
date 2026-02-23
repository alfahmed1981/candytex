<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// HR Module access: Admins or dedicated HR role. For now, we restrict to Admin.
require_admin();

// --- SELF-HEALING DATABASE MIGRATION ---
try {
    // Try to run the v2 migration script to ensure the new columns exist
    $pdo->exec(file_get_contents('hr_schema_v2.sql'));
} catch (Exception $e) {
    // Ignore error if columns already exist
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
        $h_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $rate = floatval($_POST['hourly_rate']);
        $cnss = trim($_POST['cnss_number']);
        $contract = trim($_POST['contract_type']);

        $blood = trim($_POST['blood_group']);
        $em_contact = trim($_POST['emergency_contact']);
        $em_phone = trim($_POST['emergency_phone']);

        try {
            $stmt = $pdo->prepare("INSERT INTO hr_employees 
                (matricule, first_name, last_name, full_name, cin, date_of_birth, gender, marital_status, children_count, 
                 phone_number, address, function_title, department, hire_date, hourly_rate, cnss_number, contract_type, 
                 blood_group, emergency_contact, emergency_phone) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
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
                $h_date,
                $rate,
                $cnss,
                $contract,
                $blood,
                $em_contact,
                $em_phone
            ]);

            audit_log($pdo, 'hr_add_employee', "Added Employee: $full_name ($mat)");
            $msg = "<script>Swal.fire('Success', 'Employee profile created successfully', 'success');</script>";
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
        $h_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
        $rate = floatval($_POST['hourly_rate']);
        $cnss = trim($_POST['cnss_number']);
        $contract = trim($_POST['contract_type']);

        $blood = trim($_POST['blood_group']);
        $em_contact = trim($_POST['emergency_contact']);
        $em_phone = trim($_POST['emergency_phone']);

        $status = $_POST['status'];

        try {
            $stmt = $pdo->prepare("UPDATE hr_employees SET 
                matricule=?, first_name=?, last_name=?, full_name=?, cin=?, date_of_birth=?, gender=?, marital_status=?, children_count=?, 
                phone_number=?, address=?, function_title=?, department=?, hire_date=?, hourly_rate=?, cnss_number=?, contract_type=?, 
                blood_group=?, emergency_contact=?, emergency_phone=?, status=? 
                WHERE id=?");

            $stmt->execute([
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
                $h_date,
                $rate,
                $cnss,
                $contract,
                $blood,
                $em_contact,
                $em_phone,
                $status,
                $id
            ]);

            audit_log($pdo, 'hr_edit_employee', "Updated Employee ID: $id ($full_name)");
            $msg = "<script>Swal.fire('Success', 'Employee profile updated successfully', 'success');</script>";
        } catch (PDOException $e) {
            $msg = "<script>Swal.fire('Error', 'Update failed: " . addslashes($e->getMessage()) . "', 'error');</script>";
        }
    }
    // Delete Employee
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
    // Search by name, matricule, cin, or phone
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
    </style>
</head>

<body>
    <?php include 'includes/nav.php'; ?>
    <?= $msg ?>

    <div class="main-content">
        <div class="hr-header">
            <div>
                <h2>👥 Advanced Human Resources / الموارد البشرية</h2>
                <p>Employee Profiles, ISO & CNSS Data / ملفات الموظفين المفصلة</p>
            </div>
            <button class="btn-save" onclick="openAddModal()">➕ Add Employee Profile</button>
        </div>

        <!-- Filters -->
        <div class="filter-card"
            style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="text" name="search" placeholder="Search Name, ID, CIN, or Phone..."
                    value="<?= htmlspecialchars($search) ?>"
                    style="flex:1; min-width: 250px; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <select name="status" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?>>🟢 Active Only</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?>>🔴 Inactive Only
                    </option>
                    <option value="All" <?= $status_filter == 'All' ? 'selected' : '' ?>>🌍 All Employees</option>
                </select>
                <button type="submit" class="btn-save" style="background: #1a6b8a;">🔍 Search</button>
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
                                <span class="badge <?= strtolower($emp['status']) ?>"><?= $emp['status'] ?></span>
                            </h4>
                            <div class="emp-meta">
                                <span title="Matricule">🆔 <?= htmlspecialchars($emp['matricule']) ?></span>
                                <?php if ($emp['cin']): ?><span title="CIN">💳
                                        <?= htmlspecialchars($emp['cin']) ?></span><?php endif; ?>
                                <?php if ($emp['phone_number']): ?><span title="Phone">📞
                                        <?= htmlspecialchars($emp['phone_number']) ?></span><?php endif; ?>
                                <span title="Function">💼 <?= htmlspecialchars($emp['function_title'] ?: 'N/A') ?></span>
                                <span title="Hourly Rate">💰 <?= number_format($emp['hourly_rate'], 2) ?> MAD/h</span>
                            </div>
                        </div>
                        <div class="emp-actions">
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
                <h3 id="modalTitle" style="margin-top:0; color:#0b3c5d;">Add New Employee Profile</h3>
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
                        & Contact</button>
                    <button type="button" class="tab-btn" onclick="openTab('tab-work', this)">💼 Work & Payroll</button>
                    <button type="button" class="tab-btn" onclick="openTab('tab-safety', this)">🚑 ISO 45001
                        Safety</button>
                </div>

                <!-- TAB 1: Personal & Contact -->
                <div id="tab-personal" class="tab-content active">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Matricule / ID (Required)*</label>
                            <input type="text" name="matricule" id="m_matricule" required>
                        </div>
                        <div class="form-group">
                            <label>CIN / رقم البطاقة المتنطية</label>
                            <input type="text" name="cin" id="m_cin">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name / الاسم الشخصي*</label>
                            <input type="text" name="first_name" id="m_first" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name / الاسم العائلي*</label>
                            <input type="text" name="last_name" id="m_last" required>
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label>Date of Birth / تاريخ الازدياد</label>
                            <input type="date" name="date_of_birth" id="m_dob">
                        </div>
                        <div class="form-group">
                            <label>Gender / الجنس</label>
                            <select name="gender" id="m_gender">
                                <option value="Male">Male 👨</option>
                                <option value="Female">Female 👩</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Marital / عائلي</label>
                            <select name="marital_status" id="m_marital">
                                <option value="Single">Single (أعزب)</option>
                                <option value="Married">Married (متزوج)</option>
                                <option value="Divorced">Divorced (مطلق)</option>
                                <option value="Widowed">Widowed (أرمل)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Children (CNSS logic) / عدد الأطفال</label>
                            <input type="number" name="children_count" id="m_children" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label>Phone Number / رقم الهاتف</label>
                            <input type="text" name="phone_number" id="m_phone">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Home Address / العنوان</label>
                        <textarea name="address" id="m_address" rows="2"></textarea>
                    </div>
                </div>

                <!-- TAB 2: Work & Payroll -->
                <div id="tab-work" class="tab-content">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Function / الوظيفة (Excel: Fonction)</label>
                            <input type="text" name="function_title" id="m_function">
                        </div>
                        <div class="form-group">
                            <label>Department / القسم</label>
                            <input type="text" name="department" id="m_dept">
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label>Hire Date (D emb)</label>
                            <input type="date" name="hire_date" id="m_hire">
                        </div>
                        <div class="form-group">
                            <label>Contract Type / نوع العقد</label>
                            <select name="contract_type" id="m_contract">
                                <option value="CDI">CDI (لامحدود)</option>
                                <option value="CDD">CDD (محدود)</option>
                                <option value="ANAPEC">ANAPEC</option>
                                <option value="Interim">Interim</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>CNSS Number</label>
                            <input type="text" name="cnss_number" id="m_cnss">
                        </div>
                    </div>

                    <div class="form-row"
                        style="background:#f8f9fa; padding:15px; border-radius:4px; border:1px solid #ddd;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="color:#28a745;">Hourly Rate (Taux) MAD/h*</label>
                            <input type="number" step="0.01" name="hourly_rate" id="m_rate" value="9.00" required
                                style="font-size:1.2em; font-weight:bold; color:#28a745;">
                        </div>
                        <div class="form-group" id="statusGroup" style="display:none; margin-bottom:0;">
                            <label>Status / الحالة</label>
                            <select name="status" id="m_status" style="font-weight:bold;">
                                <option value="Active">Active 🟢</option>
                                <option value="Inactive">Inactive 🔴</option>
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
                            <label>Blood Group / فصيلة الدم</label>
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
                            <label>Emergency Contact Name / اسم جهة اتصال الطوارئ</label>
                            <input type="text" name="emergency_contact" id="m_em_contact">
                        </div>
                        <div class="form-group">
                            <label>Emergency Phone / هاتف الطوارئ</label>
                            <input type="text" name="emergency_phone" id="m_em_phone">
                        </div>
                    </div>
                </div>

                <!-- Form Submit -->
                <div
                    style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #ddd; padding-top:15px;">
                    <span style="font-size:0.85em; color:#666;">* Required Fields</span>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn-details" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn-save" id="modalSubmitBtn"
                            style="padding:10px 25px; font-size:1.1em;">💾 Save Profile</button>
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

        function openAddModal() {
            document.getElementById('modalTitle').innerText = '➕ Add New Employee Profile';
            document.getElementById('empForm').reset();
            document.getElementById('emp_id').value = '';
            document.getElementById('form_action').name = 'add_emp';
            document.getElementById('statusGroup').style.display = 'none';
            document.getElementById('modalSubmitBtn').innerText = '💾 Save Profile';

            // Go to first tab
            openTab('tab-personal', document.querySelector('.tab-buttons .tab-btn:first-child'));
            document.getElementById('empModal').style.display = 'flex';
        }

        function openEditModal(emp) {
            document.getElementById('modalTitle').innerText = '✏️ Edit Profile: ' + emp.full_name;

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
            document.getElementById('m_function').value = emp.function_title || '';
            document.getElementById('m_dept').value = emp.department || '';
            document.getElementById('m_hire').value = emp.hire_date || '';
            document.getElementById('m_contract').value = emp.contract_type || 'CDI';
            document.getElementById('m_cnss').value = emp.cnss_number || '';
            document.getElementById('m_rate').value = emp.hourly_rate;

            // ISO 45001
            document.getElementById('m_blood').value = emp.blood_group || '';
            document.getElementById('m_em_contact').value = emp.emergency_contact || '';
            document.getElementById('m_em_phone').value = emp.emergency_phone || '';

            // Extras
            document.getElementById('m_status').value = emp.status || 'Active';
            document.getElementById('statusGroup').style.display = 'block';

            document.getElementById('modalSubmitBtn').innerText = '💾 Update Profile';

            // Go to first tab
            openTab('tab-personal', document.querySelector('.tab-buttons .tab-btn:first-child'));
            document.getElementById('empModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('empModal').style.display = 'none';
        }
    </script>
</body>

</html>