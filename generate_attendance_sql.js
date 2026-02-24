const xlsx = require('xlsx');
const fs = require('fs');

const filePath = process.argv[2] || 'PAIE 12-2025 HORAIRE.xlsx';

try {
    const workbook = xlsx.readFile(filePath);
    const sheet = workbook.Sheets['HORAIRE'];
    const data = xlsx.utils.sheet_to_json(sheet, { header: 1, defval: null });

    // Build dates array mapping to columns 7 through 36
    const dates = [];
    for (let d = 26; d <= 30; d++) dates.push(`2025-11-${d}`);
    for (let d = 1; d <= 25; d++) dates.push(`2025-12-${String(d).padStart(2, '0')}`);

    let sql = "-- SQL Script to Import Attendance from Excel\n";
    sql += "-- Run this script manually or via run_sql_file\n\n";

    // First, let's clear the previous attendance for these dates to be safe (optional, since we use ON DUPLICATE KEY)
    // sql += "DELETE FROM hr_attendance WHERE work_date BETWEEN '2025-11-26' AND '2025-12-25';\n\n";

    let insertCount = 0;

    for (let i = 3; i < data.length; i++) {
        const row = data[i];
        if (!row || row.length < 5) continue;

        let matricule = row[0];
        if (!matricule) continue;
        matricule = String(matricule).trim();

        // Ensure it's a valid employee row (matricule is number)
        if (isNaN(parseInt(matricule))) continue;

        for (let col = 7; col <= 36; col++) {
            const dateStr = dates[col - 7];
            let cellValue = row[col];

            if (cellValue === null || cellValue === undefined || cellValue === '') continue; // skip blank

            cellValue = String(cellValue).trim().toUpperCase();

            let status = 'P';
            let hours = 0;

            if (cellValue === 'A') {
                status = 'A'; // Absent
            } else if (cellValue === 'W' || cellValue.includes('*')) {
                status = 'W'; // Weekend
            } else {
                // Try parsing as number
                let parsed = parseFloat(cellValue);
                if (!isNaN(parsed)) {
                    status = 'P';
                    hours = parsed;
                } else {
                    continue; // unrecognized string
                }
            }

            // Generate INSERT
            sql += `INSERT INTO hr_attendance (employee_id, work_date, hours_worked, status, recorded_by)\n`;
            sql += `VALUES ((SELECT id FROM hr_employees WHERE matricule='${matricule}' LIMIT 1), '${dateStr}', ${hours}, '${status}', 'SYSTEM_EXCEL')\n`;
            sql += `ON DUPLICATE KEY UPDATE hours_worked=${hours}, status='${status}';\n`;

            insertCount++;
        }
    }

    // Write to file
    fs.writeFileSync('import_attendance.sql', sql);
    console.log(`✅ Extracted ${insertCount} attendance records from ${filePath}.`);
    console.log(`Saved SQL to import_attendance.sql`);

} catch (e) {
    console.error("Error reading file:", e.message);
}
