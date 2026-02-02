<?php
session_start();
require 'db.php';

// Admin Only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// ===================== ACTIONS =====================

// Delete SQDC Records
if (isset($_POST['delete_sqdc'])) {
    $user_cin = $_POST['user_cin'];
    $filter_type = $_POST['filter_type']; // day, month, category, all
    $filter_value = $_POST['filter_value'] ?? '';
    
    $sql = "DELETE FROM sqdc_daily WHERE user_cin = ?";
    $params = [$user_cin];
    
    if ($filter_type === 'day' && $filter_value) {
        $sql .= " AND day_date = ?";
        $params[] = $filter_value;
    } elseif ($filter_type === 'month' && $filter_value) {
        $sql .= " AND DATE_FORMAT(day_date, '%Y-%m') = ?";
        $params[] = $filter_value;
    } elseif ($filter_type === 'category' && $filter_value) {
        $sql .= " AND category = ?";
        $params[] = $filter_value;
    }
    // if 'all' - delete everything for this user
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deleted_count = $stmt->rowCount();
    $msg = "🗑️ تم حذف $deleted_count سجل بنجاح";
}

// Update User
if (isset($_POST['update_user'])) {
    $id = $_POST['user_id'];
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $role = $_POST['role'];
    $location = $_POST['location'];
    $department = $_POST['department'];
    $job_title = $_POST['job_title'];
    
    $stmt = $pdo->prepare("UPDATE users SET name=?, phone=?, role=?, location=?, department=?, job_title=? WHERE id=?");
    $stmt->execute([$name, $phone, $role, $location, $department, $job_title, $id]);
    $msg = "✅ تم تحديث بيانات المستخدم";
}

// Delete User
if (isset($_POST['delete_user'])) {
    $id = $_POST['user_id'];
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
    $stmt->execute([$id]);
    $msg = "🗑️ تم حذف المستخدم";
}

// ===================== FILTERS =====================
$filter_location = $_GET['location'] ?? '';
$filter_department = $_GET['department'] ?? '';
$filter_role = $_GET['role'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($filter_location) {
    $sql .= " AND location = ?";
    $params[] = $filter_location;
}
if ($filter_department) {
    $sql .= " AND department = ?";
    $params[] = $filter_department;
}
if ($filter_role) {
    $sql .= " AND role = ?";
    $params[] = $filter_role;
}
if ($search) {
    $sql .= " AND (name LIKE ? OR cin LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get unique locations and departments for filters
$locations = $pdo->query("SELECT DISTINCT location FROM users WHERE location IS NOT NULL AND location != '' ORDER BY location")->fetchAll(PDO::FETCH_COLUMN);
$departments = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// Column visibility (from cookie or default)
$visible_columns = isset($_COOKIE['visible_columns']) ? json_decode($_COOKIE['visible_columns'], true) : 
    ['name' => true, 'cin' => true, 'phone' => true, 'role' => true, 'location' => true, 'department' => true];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة متقدمة - SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
        }
        
        .filter-bar {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        
        .filter-bar select, .filter-bar input {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-bar button {
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn-filter { background: #007bff; color: white; border: none; }
        .btn-reset { background: #6c757d; color: white; border: none; }
        .btn-columns { background: #17a2b8; color: white; border: none; }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: right;
            border-bottom: 1px solid #eee;
        }
        
        .data-table th {
            background: #2c3e50;
            color: white;
            font-weight: 600;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-admin { background: #28a745; color: white; }
        .badge-manager { background: #007bff; color: white; }
        .badge-viewer { background: #6c757d; color: white; }
        
        .location-badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            background: #e9ecef;
        }
        .loc-candy1 { background: #ffeeba; }
        .loc-candy2 { background: #bee5eb; }
        .loc-flora1 { background: #d4edda; }
        
        .action-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin: 2px;
        }
        .btn-edit { background: #ffc107; color: #333; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-records { background: #6f42c1; color: white; }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal.active { display: flex; }
        
        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 15px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .modal-header h3 { margin: 0; }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn-submit {
            background: #28a745;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
            font-weight: 600;
        }
        
        .columns-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .column-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card .number {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }
        
        .stat-card .label {
            color: #666;
            font-size: 14px;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-danger { background: #f8d7da; color: #721c24; }
        
        @media (max-width: 768px) {
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 8px; }
            .filter-bar { flex-direction: column; }
            .filter-bar select, .filter-bar input { width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Mobile Top Navigation -->
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>⚙️ إدارة متقدمة</h3>
        </div>
        <div class="nav-links">
            <a href="index.php">📊 لوحة</a>
            <a href="admin.php">👥 مستخدمين</a>
            <a href="admin_advanced.php" class="active">⚙️ متقدم</a>
            <a href="index.php?logout=1" class="logout">خروج</a>
        </div>
    </div>

    <!-- Desktop Sidebar -->
    <div class="sidebar">
        <h3>⚙️ إدارة متقدمة</h3>
        <p>إدارة الفرق</p>
        <hr>
        <a href="index.php" class="logout-btn" style="background:#007bff;">📊 لوحة</a>
        <a href="admin.php" class="logout-btn" style="background:#17a2b8;">👥 مستخدمين</a>
        <a href="global.php" class="logout-btn" style="background:#6f42c1;">🏭 عام</a>
        <a href="index.php?logout=1" class="logout-btn" style="background:#dc3545;">خروج</a>
    </div>

    <div class="main-content">
        <div class="admin-container">
            <h1>⚙️ إدارة متقدمة للمستخدمين</h1>
            
            <?php if (isset($msg)): ?>
                <div class="alert alert-success"><?php echo $msg; ?></div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-bar">
                <div class="stat-card">
                    <div class="number"><?php echo count($users); ?></div>
                    <div class="label">مستخدم</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo count($locations); ?></div>
                    <div class="label">موقع</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo count($departments); ?></div>
                    <div class="label">قسم</div>
                </div>
            </div>
            
            <!-- Filters -->
            <form method="GET" class="filter-bar">
                <select name="location">
                    <option value="">كل المواقع</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc; ?>" <?php echo $filter_location === $loc ? 'selected' : ''; ?>><?php echo $loc; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="department">
                    <option value="">كل الأقسام</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept; ?>" <?php echo $filter_department === $dept ? 'selected' : ''; ?>><?php echo $dept; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select name="role">
                    <option value="">كل الأدوار</option>
                    <option value="admin" <?php echo $filter_role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="manager" <?php echo $filter_role === 'manager' ? 'selected' : ''; ?>>Manager</option>
                    <option value="viewer" <?php echo $filter_role === 'viewer' ? 'selected' : ''; ?>>Viewer</option>
                </select>
                
                <input type="text" name="search" placeholder="🔍 بحث بالاسم/CIN/الهاتف" value="<?php echo htmlspecialchars($search); ?>">
                
                <button type="submit" class="btn-filter">🔍 تصفية</button>
                <a href="admin_advanced.php" class="action-btn btn-reset">إعادة تعيين</a>
                <button type="button" class="btn-columns" onclick="openColumnsModal()">👁️ الأعمدة</button>
            </form>
            
            <!-- Data Table -->
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th id="col-name">الاسم</th>
                        <th id="col-cin">CIN</th>
                        <th id="col-phone">الهاتف</th>
                        <th id="col-role">الدور</th>
                        <th id="col-location">الموقع</th>
                        <th id="col-department">القسم</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><strong><?php echo htmlspecialchars($user['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($user['cin']); ?></td>
                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $user['role']; ?>">
                                    <?php echo strtoupper($user['role']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['location']): ?>
                                    <span class="location-badge loc-<?php echo strtolower(str_replace(' ', '', $user['location'])); ?>">
                                        <?php echo htmlspecialchars($user['location']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($user['department'] ?? '-'); ?></td>
                            <td>
                                <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)">✏️ تعديل</button>
                                <button class="action-btn btn-records" onclick="openRecordsModal('<?php echo $user['cin']; ?>', '<?php echo htmlspecialchars($user['name']); ?>')">🗑️ السجلات</button>
                                <?php if ($user['role'] !== 'admin'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('حذف هذا المستخدم؟')">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" name="delete_user" class="action-btn btn-delete">🗑️</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✏️ تعديل المستخدم</h3>
                <button class="close-btn" onclick="closeModal('editModal')">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-group">
                    <label>الاسم</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                
                <div class="form-group">
                    <label>الهاتف</label>
                    <input type="text" name="phone" id="edit_phone" required>
                </div>
                
                <div class="form-group">
                    <label>الدور</label>
                    <select name="role" id="edit_role">
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                        <option value="viewer">Viewer</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>الموقع</label>
                    <select name="location" id="edit_location">
                        <option value="">-- اختر --</option>
                        <option value="Candy 1">Candy 1</option>
                        <option value="Candy 2">Candy 2</option>
                        <option value="Flora 1">Flora 1</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>القسم</label>
                    <select name="department" id="edit_department">
                        <option value="">-- اختر --</option>
                        <option value="Sewing">Sewing | الخياطة</option>
                        <option value="Cutting">Cutting | القص</option>
                        <option value="Maintenance">Maintenance | الصيانة</option>
                        <option value="Embroidery">Embroidery | التطريز</option>
                        <option value="Warehouse MP">Warehouse MP | مستودع المواد</option>
                        <option value="Warehouse PF">Warehouse PF | مستودع المنتجات</option>
                        <option value="Administration">Administration | الإدارة</option>
                        <option value="Printing">Printing | الطباعة</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>المسمى الوظيفي</label>
                    <input type="text" name="job_title" id="edit_job_title">
                </div>
                
                <button type="submit" name="update_user" class="btn-submit">💾 حفظ التغييرات</button>
            </form>
        </div>
    </div>

    <!-- Delete Records Modal -->
    <div class="modal" id="recordsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🗑️ حذف سجلات SQDC</h3>
                <button class="close-btn" onclick="closeModal('recordsModal')">×</button>
            </div>
            <p id="records_user_name" style="margin-bottom:20px; color:#666;"></p>
            
            <form method="POST">
                <input type="hidden" name="user_cin" id="records_user_cin">
                
                <div class="form-group">
                    <label>نوع الحذف</label>
                    <select name="filter_type" id="filter_type" onchange="updateFilterValue()">
                        <option value="all">🗑️ حذف جميع السجلات</option>
                        <option value="day">📅 حذف يوم محدد</option>
                        <option value="month">📆 حذف شهر كامل</option>
                        <option value="category">🏷️ حذف صنف محدد</option>
                    </select>
                </div>
                
                <div class="form-group" id="filter_value_group" style="display:none;">
                    <label id="filter_value_label">القيمة</label>
                    <input type="date" name="filter_value" id="filter_value_date" style="display:none;">
                    <input type="month" name="filter_value" id="filter_value_month" style="display:none;">
                    <select name="filter_value" id="filter_value_category" style="display:none;">
                        <option value="S">S - Safety (السلامة)</option>
                        <option value="Q">Q - Quality (الجودة)</option>
                        <option value="D">D - Delivery (التسليم)</option>
                        <option value="5S">5S - Improvement (التحسين)</option>
                        <option value="C">C - Cost (التكلفة)</option>
                    </select>
                </div>
                
                <div style="background:#f8d7da; padding:15px; border-radius:8px; margin-bottom:15px;">
                    ⚠️ <strong>تحذير:</strong> هذا الإجراء لا يمكن التراجع عنه!
                </div>
                
                <button type="submit" name="delete_sqdc" class="btn-submit" style="background:#dc3545;" onclick="return confirm('هل أنت متأكد من حذف هذه السجلات؟')">
                    🗑️ تأكيد الحذف
                </button>
            </form>
        </div>
    </div>

    <!-- Columns Modal -->
    <div class="modal" id="columnsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>👁️ تخصيص الأعمدة</h3>
                <button class="close-btn" onclick="closeModal('columnsModal')">×</button>
            </div>
            <div class="columns-grid">
                <label class="column-checkbox">
                    <input type="checkbox" id="col_name" checked onchange="toggleColumn('name')"> الاسم
                </label>
                <label class="column-checkbox">
                    <input type="checkbox" id="col_cin" checked onchange="toggleColumn('cin')"> CIN
                </label>
                <label class="column-checkbox">
                    <input type="checkbox" id="col_phone" checked onchange="toggleColumn('phone')"> الهاتف
                </label>
                <label class="column-checkbox">
                    <input type="checkbox" id="col_role" checked onchange="toggleColumn('role')"> الدور
                </label>
                <label class="column-checkbox">
                    <input type="checkbox" id="col_location" checked onchange="toggleColumn('location')"> الموقع
                </label>
                <label class="column-checkbox">
                    <input type="checkbox" id="col_department" checked onchange="toggleColumn('department')"> القسم
                </label>
            </div>
        </div>
    </div>

    <script>
    function openEditModal(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_name').value = user.name;
        document.getElementById('edit_phone').value = user.phone;
        document.getElementById('edit_role').value = user.role;
        document.getElementById('edit_location').value = user.location || '';
        document.getElementById('edit_department').value = user.department || '';
        document.getElementById('edit_job_title').value = user.job_title || '';
        document.getElementById('editModal').classList.add('active');
    }
    
    function openRecordsModal(cin, name) {
        document.getElementById('records_user_cin').value = cin;
        document.getElementById('records_user_name').textContent = '👤 ' + name + ' (CIN: ' + cin + ')';
        document.getElementById('recordsModal').classList.add('active');
    }
    
    function openColumnsModal() {
        document.getElementById('columnsModal').classList.add('active');
    }
    
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }
    
    function updateFilterValue() {
        const type = document.getElementById('filter_type').value;
        const group = document.getElementById('filter_value_group');
        const dateInput = document.getElementById('filter_value_date');
        const monthInput = document.getElementById('filter_value_month');
        const categoryInput = document.getElementById('filter_value_category');
        
        // Hide all
        dateInput.style.display = 'none';
        monthInput.style.display = 'none';
        categoryInput.style.display = 'none';
        dateInput.name = '';
        monthInput.name = '';
        categoryInput.name = '';
        
        if (type === 'all') {
            group.style.display = 'none';
        } else {
            group.style.display = 'block';
            if (type === 'day') {
                dateInput.style.display = 'block';
                dateInput.name = 'filter_value';
                document.getElementById('filter_value_label').textContent = 'اختر اليوم';
            } else if (type === 'month') {
                monthInput.style.display = 'block';
                monthInput.name = 'filter_value';
                document.getElementById('filter_value_label').textContent = 'اختر الشهر';
            } else if (type === 'category') {
                categoryInput.style.display = 'block';
                categoryInput.name = 'filter_value';
                document.getElementById('filter_value_label').textContent = 'اختر الصنف';
            }
        }
    }
    
    function toggleColumn(col) {
        const cells = document.querySelectorAll(`[id="col-${col}"], td:nth-child(${getColIndex(col)})`);
        const isChecked = document.getElementById('col_' + col).checked;
        // Simple toggle - just reload with preference saved in cookie
        const visible = JSON.parse(localStorage.getItem('visible_columns') || '{}');
        visible[col] = isChecked;
        localStorage.setItem('visible_columns', JSON.stringify(visible));
    }
    
    function getColIndex(col) {
        const cols = ['name', 'cin', 'phone', 'role', 'location', 'department'];
        return cols.indexOf(col) + 2; // +2 because first col is #
    }
    
    // Close modal on click outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal(modal.id);
        });
    });
    </script>
</body>
</html>
