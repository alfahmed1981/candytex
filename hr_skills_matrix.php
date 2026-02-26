<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Auth Check
if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$user_cin = $_SESSION['user_cin'];
$is_admin = is_admin();
$is_hr = is_hr() || $is_admin;

// Auto-run schema migration
try {
    if(!function_exists('run_skills_sql')){
        function run_skills_sql($pdo) {
            $file = __DIR__ . '/hr_skills_schema.sql';
            if (!file_exists($file)) return;
            $sql = file_get_contents($file);
            $queries = explode(';', $sql);
            foreach ($queries as $query) {
                $cleaned = trim($query);
                if (!empty($cleaned)) {
                    try { $pdo->exec($cleaned); } catch (PDOException $e) {}
                }
            }
        }
    }
    run_skills_sql($pdo);
} catch (Exception $e) {}

$msg = "";
$error = "";

// Handle Skill Evaluation Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evaluate_skill'])) {
    require_csrf();
    
    $emp_id = intval($_POST['employee_id']);
    $skill_id = intval($_POST['skill_id']);
    $level = intval($_POST['level']);
    
    // Level constraints
    if($level < 0 || $level > 4) $level = 0; 
    
    try {
        if ($level === 0) {
            // Remove skill if level is 0
            $stmt = $pdo->prepare("DELETE FROM worker_skills WHERE employee_id = ? AND skill_id = ?");
            $stmt->execute([$emp_id, $skill_id]);
        } else {
            // Insert or Update Skill Level
            $stmt = $pdo->prepare("INSERT INTO worker_skills (employee_id, skill_id, level, evaluated_by_cin, last_evaluated_date) 
                                   VALUES (?, ?, ?, ?, CURRENT_DATE) 
                                   ON DUPLICATE KEY UPDATE level = VALUES(level), evaluated_by_cin = VALUES(evaluated_by_cin), last_evaluated_date = CURRENT_DATE");
            $stmt->execute([$emp_id, $skill_id, $level, $user_cin]);
        }
        
        $msg = "✅ Polyvalence Matrix updated successfully.";
    } catch (PDOException $e) {
        $error = "Update Error: " . $e->getMessage();
    }
}

// Ensure at least some data exists for dictionary
$all_skills = [];
try {
    $stmt_skills = $pdo->query("SELECT * FROM skills_dictionary ORDER BY skill_category, skill_name");
    if ($stmt_skills) {
        $all_skills = $stmt_skills->fetchAll();
    }
} catch (PDOException $e) {
    // Table might not exist yet if schema failed to load
}

// Filters
$dept_filter = $_GET['department'] ?? '';
$search = $_GET['search'] ?? '';

// Fetch Employees based on filter
$emp_query = "SELECT id, matricule, full_name, department, function_title FROM hr_employees WHERE status = 'Active'";
$params = [];

if ($search) {
    $emp_query .= " AND (full_name LIKE ? OR matricule LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($dept_filter) {
    $emp_query .= " AND department = ?";
    $params[] = $dept_filter;
}
$emp_query .= " ORDER BY department, full_name LIMIT 100"; // Limit to prevent massive horizontal scrolling lag

$employees = [];
try {
    $stmt = $pdo->prepare($emp_query);
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
} catch (PDOException $e) {}

// Fetch all skill evaluations for these employees
$evaluations = [];
if (!empty($employees)) {
    try {
        $emp_ids = array_column($employees, 'id');
        $in_clause = implode(',', array_fill(0, count($emp_ids), '?'));
        $stmt_evals = $pdo->prepare("SELECT employee_id, skill_id, level FROM worker_skills WHERE employee_id IN ($in_clause)");
        $stmt_evals->execute($emp_ids);
        
        foreach ($stmt_evals->fetchAll() as $row) {
            $evaluations[$row['employee_id']][$row['skill_id']] = $row['level'];
        }
    } catch (PDOException $e) {
        // Table might missing if schema failed
    }
}

// Fetch distinct departments for dropdown
$depts = [];
try {
    $depts = $pdo->query("SELECT DISTINCT department FROM hr_employees WHERE department IS NOT NULL AND status='Active' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ISO 9001 - HR Competence Matrix</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container { max-width: 1400px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto; }
        
        table { width: 100%; border-collapse: collapse; min-width: 800px; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; font-size: 13px; }
        th { background: #f4f6f8; color: #333; }
        .emp-col { text-align: left; font-weight: bold; min-width: 250px; background: #fff; position: sticky; left: 0; z-index: 10; border-right: 2px solid #ccc; }
        .skill-header { writing-mode: vertical-rl; text-orientation: mixed; height: 120px; white-space: nowrap; padding: 10px 5px; transform: rotate(180deg); }
        
        .level-badge { 
            display: inline-block; width: 25px; height: 25px; line-height: 25px; 
            border-radius: 50%; font-weight: bold; color: white; cursor: pointer;
            transition: transform 0.2s;
        }
        .level-badge:hover { transform: scale(1.2); }
        .lvl-0 { background: #e9ecef; color: transparent; border: 1px dashed #ccc; }
        .lvl-1 { background: #dc3545; } /* Beginner / Red */
        .lvl-2 { background: #fd7e14; } /* Under Supervision / Orange */
        .lvl-3 { background: #28a745; } /* Independent / Green */
        .lvl-4 { background: #007bff; } /* Trainer / Blue */

        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

        .legend-box { display: flex; gap: 15px; background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; flex-wrap: wrap; }
        
        /* Modal Info */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); justify-content: center; align-items: center; z-index: 100; }
        .modal-box { background: white; padding: 25px; border-radius: 8px; text-align: center; width: 350px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal-box select { width: 100%; padding: 10px; margin: 15px 0; border: 1px solid #ccc; border-radius: 4px; }
        .form-filters { display: flex; gap: 10px; margin-bottom: 20px; align-items: flex-end; }
        .form-filters input, .form-filters select { padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .btn-blue { background: #007bff; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
    <?php include 'includes/nav.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <h2>🧠 Training & Polyvalence Matrix <small style="font-size:0.6em;color:#666">(مصفوفة المهارات والتدريب)</small></h2>
            <p style="color: #666; font-size:0.9em;">
                <strong>ISO 9001 Clause 7.2 Requirements: Ensuring worker competence, training evaluation, and skill mapping.</strong><br>
                <span style="direction:rtl; display:inline-block;">تهدف هذه المصفوفة إلى تقييم كفاءة العمال، وتحديد الاحتياجات التدريبية، لضمان قيام العمال المؤهلين فقط بتشغيل الماكينات.</span>
            </p>
            
            <div class="alert alert-info" style="background:#e3f2fd; color:#0d47a1; border:1px solid #bbdefb; padding:15px; border-radius:6px; margin:15px 0;">
                <h4 style="margin:0 0 10px 0;">📖 How to Use (دليل الاستخدام):</h4>
                <ul style="margin:0; padding-inline-start: 20px; font-size:13px; line-height:1.6; direction:ltr;">
                    <li><strong>Search/Filter:</strong> Use the filters to find a specific worker or department. <span style="direction:rtl; display:inline-block;">(استخدم الفلاتر للبحث عن عامل معين أو تصفية العمال حسب القسم)</span></li>
                    <li><strong>Evaluate:</strong> Click the badge/empty square corresponding to the worker and skill. <span style="direction:rtl; display:inline-block;">(اضغط على المربع الفارغ المقابل لاسم العامل والمهارة المطلوبة)</span></li>
                    <li><strong>Proficiency Levels:</strong> A window will prompt you to select the worker's level from 0 to 4. <span style="direction:rtl; display:inline-block;">(ستظهر نافذة لتحديد مستوى كفاءة العامل من 0 إلى 4)</span></li>
                    <li><strong>Update:</strong> Matrix updates automatically and changes color based on skill level. <span style="direction:rtl; display:inline-block;">(سيتم تحديث المصفوفة تلقائياً وتلوين المربع حسب مستوى المهارة)</span></li>
                </ul>
            </div>
            
            <?php if ($msg): ?> <div class="alert alert-success"><?= $msg ?></div> <?php endif; ?>

            <div class="legend-box">
                <strong style="width:100%; margin-bottom:10px;">📋 Skill Levels Legend (مفتاح مستويات التقييم):</strong>
                <span><div class="level-badge lvl-0" style="width:15px;height:15px;display:inline-block;vertical-align:middle;"></div> <strong>0</strong> - Not Trained <small>(غير مدرب)</small></span>
                <span><div class="level-badge lvl-1" style="width:15px;height:15px;display:inline-block;vertical-align:middle;"></div> <strong>1</strong> - 🔴 Beginner <small>(مبتدئ)</small></span>
                <span><div class="level-badge lvl-2" style="width:15px;height:15px;display:inline-block;vertical-align:middle;"></div> <strong>2</strong> - 🟠 Needs Supervision <small>(يحتاج إشراف)</small></span>
                <span><div class="level-badge lvl-3" style="width:15px;height:15px;display:inline-block;vertical-align:middle;"></div> <strong>3</strong> - 🟢 Independent <small>(مستقل)</small></span>
                <span><div class="level-badge lvl-4" style="width:15px;height:15px;display:inline-block;vertical-align:middle;"></div> <strong>4</strong> - 🔵 Expert/Trainer <small>(مدرب)</small></span>
            </div>

            <form method="GET" class="form-filters">
                <div>
                    <label style="font-size:12px;font-weight:bold;">🔍 Search Worker <small>(بحث عن عامل)</small></label><br>
                    <input type="text" name="search" placeholder="Name or Matricule..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:bold;">🏢 Filter Department <small>(تصفية القسم)</small></label><br>
                    <select name="department">
                        <option value="">-- All Departments --</option>
                        <?php foreach($depts as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $dept_filter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-blue">🎯 Filter</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th class="emp-col">👤 Employee Name / Dept <small>(اسم العامل والقسم)</small></th>
                        <?php foreach($all_skills as $sk): ?>
                            <th title="<?= htmlspecialchars($sk['skill_category']) ?>">
                                <div class="skill-header"><?= htmlspecialchars($sk['skill_name']) ?></div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr><td colspan="<?= count($all_skills) + 1 ?>">🚫 No employees found matching filter. <small>(لا يوجد عمال متطابقون)</small></td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($employees as $emp): ?>
                        <tr>
                            <td class="emp-col">
                                <?= htmlspecialchars($emp['full_name']) ?> <small>(<?= htmlspecialchars($emp['matricule']) ?>)</small><br>
                                <small style="color:#666; font-weight:normal;"><?= htmlspecialchars($emp['department'] ?: 'No Dept') ?></small>
                            </td>
                            
                            <?php foreach($all_skills as $sk): 
                                $lvl = $evaluations[$emp['id']][$sk['id']] ?? 0;
                            ?>
                                <td>
                                    <div class="level-badge lvl-<?= $lvl ?>" 
                                         onclick="openEvalModal(<?= $emp['id'] ?>, '<?= addslashes($emp['full_name']) ?>', <?= $sk['id'] ?>, '<?= addslashes($sk['skill_name']) ?>', <?= $lvl ?>)"
                                         title="Click to Evaluate">
                                        <?= $lvl > 0 ? $lvl : '' ?>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Evaluation Modal -->
    <div id="evalModal" class="modal-overlay" onclick="if(event.target === this) closeEvalModal()">
        <div class="modal-box">
            <h3 id="modalEmpName" style="margin-top:0; color:#0b3c5d;">Worker Name</h3>
            <p id="modalSkillName" style="color:#555; font-weight:bold;">Skill Name</p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="employee_id" id="modalEmpId">
                <input type="hidden" name="skill_id" id="modalSkillId">
                
                <div style="text-align:left; margin-bottom:10px;">
                    <label style="font-size:12px;font-weight:bold;color:#444;">Proficiency Level <span style="direction:rtl;display:inline-block;">(مستوى الكفاءة)</span>:</label>
                </div>
                <select name="level" id="modalLevel">
                    <option value="0">0 - Untrained / Remove Skill (غير مدرب)</option>
                    <option value="1">1 - 🔴 Beginner in Training (مبتدئ قيد التدريب)</option>
                    <option value="2">2 - 🟠 Capable but Needs Supervision (يحتاج إشراف)</option>
                    <option value="3">3 - 🟢 Independent / Standard Output (مستقل ويعمل بمعايير الجودة)</option>
                    <option value="4">4 - 🔵 Expert / Can Train Others (خبير مؤهل لتدريب الآخرين)</option>
                </select>
                
                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="submit" name="evaluate_skill" class="btn-blue" style="width:100%;">💾 Save Level</button>
                    <button type="button" onclick="closeEvalModal()" style="padding:10px; border:1px solid #ccc; background:#f9f9f9; border-radius:4px; cursor:pointer;">❌ Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEvalModal(empId, empName, skillId, skillName, currentLvl) {
            document.getElementById('modalEmpId').value = empId;
            document.getElementById('modalEmpName').innerText = empName;
            
            document.getElementById('modalSkillId').value = skillId;
            document.getElementById('modalSkillName').innerText = skillName;
            
            document.getElementById('modalLevel').value = currentLvl;
            
            document.getElementById('evalModal').style.display = 'flex';
        }

        function closeEvalModal() {
            document.getElementById('evalModal').style.display = 'none';
        }
    </script>
</body>
</html>
