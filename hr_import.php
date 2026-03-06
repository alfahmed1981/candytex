<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Only HR and Admin can access this
require_hr_or_admin();
$is_admin = ($_SESSION['role'] === 'admin');

// Add the CSRF token manually (if not auto-added by auth.php in this view context)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import Data - CandyTex Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        .import-container {
            max-width: 800px;
            margin: 40px auto;
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            text-align: center;
        }
        .import-icon {
            font-size: 60px;
            color: #0b3c5d;
            margin-bottom: 20px;
        }
        .upload-area {
            border: 2px dashed #1a6b8a;
            padding: 40px;
            border-radius: 8px;
            background: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .upload-area:hover {
            background: #e9ecef;
            border-color: #0b3c5d;
        }
        .instructions {
            background: #e8f4f8;
            border-left: 4px solid #1a6b8a;
            padding: 15px;
            text-align: left;
            margin-bottom: 30px;
            border-radius: 0 8px 8px 0;
        }
        #fileInput {
            display: none;
        }
    </style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>📥 Import Data / استيراد البيانات</h1>
        <p>Smart Excel Import for Employees, Attendance, and Payroll</p>
    </div>

    <div class="import-container">
        
        <div class="instructions">
            <h4>📋 Requirements</h4>
            <ul style="margin-top:10px; padding-left:20px; line-height:1.6;">
                <li>The file must be the standard monthly <b>PAIE Excel</b> file (`.xls` or `.xlsx`).</li>
                <li>The system will automatically process both the <b>HORAIRE</b> and <b>MENS</b> sheets.</li>
                <li>Missing employees will be auto-created with their Gender, Hire Date, and Hourly Rate.</li>
                <li>Absences (ML, AT, MT, A) will be intelligently extracted into official records.</li>
            </ul>
        </div>

        <div class="upload-area" onclick="document.getElementById('fileInput').click()">
            <div class="import-icon">📊</div>
            <h3>Click here to select the Excel file</h3>
            <p style="color:#666; margin-top:10px;">or drag and drop it here.</p>
        </div>
        
        <input type="file" id="fileInput" accept=".xlsx, .xls">
        
        <div class="filter-group" style="margin-bottom: 20px; display: flex; justify-content: center; gap: 15px;">
            <div>
                <label>Month</label>
                <select id="import_month" class="form-control" style="width:120px; display:inline-block;">
                    <?php 
                    $current_m = date('n');
                    for($m=1; $m<=12; $m++): 
                        $sel = ($m == $current_m) ? 'selected' : '';
                        echo "<option value='".str_pad($m, 2, '0', STR_PAD_LEFT)."' $sel>".str_pad($m, 2, '0', STR_PAD_LEFT)."</option>";
                    endfor; 
                    ?>
                </select>
            </div>
            <div>
                <label>Year</label>
                <select id="import_year" class="form-control" style="width:120px; display:inline-block;">
                    <?php 
                    $current_y = date('Y');
                    for($y=$current_y-2; $y<=$current_y+2; $y++): 
                        $sel = ($y == $current_y) ? 'selected' : '';
                        echo "<option value='$y' $sel>$y</option>";
                    endfor; 
                    ?>
                </select>
            </div>
        </div>

        <button id="processBtn" class="btn" style="width: 100%; padding: 15px; font-size: 1.1em; margin-top: 10px; display: none;">
            🚀 Extract and Import Data
        </button>

    </div>
</div>

<script>
    const fileInput = document.getElementById('fileInput');
    const processBtn = document.getElementById('processBtn');
    let selectedFile = null;

    fileInput.addEventListener('change', function(e) {
        if(e.target.files.length > 0) {
            selectedFile = e.target.files[0];
            document.querySelector('.upload-area h3').innerText = "Selected: " + selectedFile.name;
            processBtn.style.display = 'block';
        }
    });

    processBtn.addEventListener('click', function() {
        if (!selectedFile) return;

        processBtn.disabled = true;
        processBtn.innerText = '⚙️ Processing...';

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array'});
                
                const sheetNamesToProcess = ['HORAIRE', 'mens', 'MENS', 'Mens'];
                let foundAnySheet = false;
                const records = [];
                const payrolls = [];

                const selectedMonth = parseInt(document.getElementById('import_month').value, 10);
                const selectedYear = parseInt(document.getElementById('import_year').value, 10);

                let prevMonth = selectedMonth - 1;
                let prevYear = selectedYear;
                if (prevMonth === 0) {
                    prevMonth = 12;
                    prevYear = selectedYear - 1;
                }

                sheetNamesToProcess.forEach(sheetName => {
                    if (workbook.Sheets[sheetName]) {
                        foundAnySheet = true;
                        const sheet = workbook.Sheets[sheetName];
                        const rowData = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: null});

                        // Extract dynamic date columns from header (row index 1 in Excel, which is index 1 of array if header:1)
                        // Example header row 1: [..., 26, 27, 28, 29, 30, 31, 1, 2, ...]
                        const dateCols = [];
                        if (rowData[1]) {
                            for (let col = 7; col <= 40; col++) {
                                let dayVal = rowData[1][col];
                                if (dayVal !== undefined && dayVal !== null && String(dayVal).trim() !== '') {
                                    let dayInt = parseInt(dayVal, 10);
                                    if (!isNaN(dayInt) && dayInt >= 1 && dayInt <= 31) {
                                        let y = selectedYear;
                                        let m = selectedMonth;
                                        if (dayInt >= 26) {
                                            y = prevYear;
                                            m = prevMonth;
                                        }
                                        const dateStr = `${y}-${String(m).padStart(2, '0')}-${String(dayInt).padStart(2, '0')}`;
                                        dateCols.push({ col: col, date: dateStr });
                                    }
                                }
                            }
                        }

                        // Data starts at row index 3
                        for (let i = 3; i < rowData.length; i++) {
                            const row = rowData[i];
                            if (!row || row.length < 5) continue;

                            let matricule = row[0];
                            if (!matricule) continue;
                            matricule = String(matricule).trim();

                            // Extract Employee details from Excel
                            let full_name = (row[1] || '').toString().trim();
                            let function_title = (row[2] || '').toString().trim();
                            let cnss = '';
                            let gender = 'M'; // Default
                            let hire_date_str = ''; // YYYY-MM-DD
                            let hourly_rate = 0;
                            let brut = 0;
                            let cnss_ded = 0;
                            let advance = 0;
                            let frais = 0;
                            let net = 0;
                            let rounded_net = 0;

                            if (sheetName.toUpperCase() === 'HORAIRE') {
                                cnss = row[3];
                                gender = (row[4] === 'F' || row[4] === 'M') ? row[4] : 'M';
                                
                                // Parse Excel Serial Date for Hire Date
                                let excelDate = row[5];
                                if (excelDate && typeof excelDate === 'number') {
                                    let dateObj = new Date(Math.round((excelDate - 25569) * 86400 * 1000));
                                    hire_date_str = dateObj.toISOString().split('T')[0];
                                }

                                hourly_rate = parseFloat(row[6]) || 0;

                                brut = parseFloat(row[39]) || 0;
                                cnss_ded = parseFloat(row[40]) || 0;
                                advance = parseFloat(row[42]) || 0;
                                frais = parseFloat(row[43]) || 0;
                                net = parseFloat(row[44]) || 0;
                                rounded_net = parseFloat(row[45]) || 0;
                            } else {
                                cnss = row[4];
                                frais = parseFloat(row[37]) || 0;
                                advance = parseFloat(row[36]) || 0;
                            }

                            payrolls.push({
                                matricule: matricule,
                                full_name: full_name,
                                function_title: function_title,
                                gender: gender,
                                hire_date: hire_date_str,
                                hourly_rate: hourly_rate,
                                sheet_type: sheetName.toUpperCase(),
                                cnss: String(cnss || '').trim(),
                                brut: brut,
                                cnss_deduction: cnss_ded,
                                advances: advance,
                                frais: frais,
                                net_salary: net,
                                rounded_net: rounded_net
                            });

                            // Extract Attendance grid dynamically
                            for (let idx = 0; idx < dateCols.length; idx++) {
                                const col = dateCols[idx].col;
                                const dateStr = dateCols[idx].date;
                                let cellValue = row[col];

                                if (cellValue === null || cellValue === undefined || cellValue === '') continue; // skip blank
                                
                                cellValue = String(cellValue).trim().toUpperCase();

                                let status = 'P';
                                let hours = 0;

                                if (cellValue === 'A') {
                                    status = 'A';
                                } else if (cellValue === 'W' || cellValue.includes('*')) {
                                    status = 'W';
                                } else if (cellValue === 'ML' || cellValue === 'M') {
                                    status = 'M';
                                } else if (cellValue === 'MT' || cellValue === 'MAT') {
                                    status = 'MAT';
                                } else if (cellValue === 'AT' || cellValue === 'ACC') {
                                    status = 'AT'; // Mapped to ACC on backend
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
                    resetBtn();
                    return;
                }

                if (records.length === 0) {
                    Swal.fire('Information', 'No valid attendance data found to import.', 'info');
                    resetBtn();
                    return;
                }

                // Confirm and Send to API
                const selectedMonth = document.getElementById('import_month').value;
                const selectedYear = document.getElementById('import_year').value;

                Swal.fire({
                    title: 'Ready to Import',
                    text: `Importing ${records.length} records and ${payrolls.length} payrolls for ${selectedMonth}/${selectedYear} ?`,
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
                            body: JSON.stringify({
                                records: records, 
                                payrolls: payrolls, 
                                csrf_token: '<?= $_SESSION['csrf_token'] ?>',
                                target_month: selectedMonth,
                                target_year: selectedYear
                            })
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
                            window.location.href = 'hr_attendance.php';
                        });
                    } else {
                        resetBtn();
                    }
                });

            } catch (err) {
                Swal.fire('Error', 'Failed to parse Excel file: ' + err.message, 'error');
                resetBtn();
            }
        };
        reader.readAsArrayBuffer(selectedFile);
    });

    function resetBtn() {
        processBtn.disabled = false;
        processBtn.innerText = '🚀 Extract and Import Data';
    }
</script>

</body>
</html>
