const xlsx = require('xlsx');
const fs = require('fs');

const filePath = process.argv[2];

try {
    const workbook = xlsx.readFile(filePath);
    const sheetName = workbook.SheetNames[0];
    const sheet = workbook.Sheets[sheetName];

    // Read raw data exactly as it is in the grid
    const data = xlsx.utils.sheet_to_json(sheet, { header: 1, defval: null });

    let sql = "-- HR Employees Import Script\n";
    sql += "-- Generated from PAIE 12-2025 HORAIRE.xlsx\n\n";
    sql += "INSERT INTO `hr_employees` (`matricule`, `full_name`, `first_name`, `last_name`, `function_title`, `department`, `cnss_number`, `hourly_rate`, `hire_date`) VALUES\n";

    let values = [];

    // The data starts roughly at row 3 (0-indexed) where the first person is Said Rharbal
    for (let i = 3; i < data.length; i++) {
        const row = data[i];
        if (!row || row.length < 5) continue; // Skip empty rows

        let matricule = row[0];
        let fullName = row[1];

        if (!matricule || !fullName) continue;
        if (String(fullName).includes("TOTAL ARRONDI")) continue;

        // Ensure string
        fullName = String(fullName).trim();
        matricule = String(matricule).trim();

        let fonction = row[2] ? String(row[2]).trim() : '';
        let devAndCnss = row[3] ? String(row[3]).trim() : ''; // Excel column says CNSS, but contains Department codes like D25
        let d_emb = row[5]; // Hire date (serial number from excel)
        let taux = parseFloat(row[6]) || 0;

        // Parse Name into First and Last
        let nameParts = fullName.split(/\s+/);
        let lastName = nameParts.length > 1 ? nameParts.pop() : '';
        let firstName = nameParts.join(' ');

        // Format Date if exists
        let hireDate = 'NULL';
        if (d_emb && !isNaN(d_emb)) {
            // Excel dates: days since Jan 1 1900. (25569 offset for Unix epoch)
            let date = new Date(Math.round((d_emb - 25569) * 86400 * 1000));
            hireDate = `'${date.toISOString().split('T')[0]}'`;
        }

        const esc = (str) => typeof str === 'string' ? `'${str.replace(/'/g, "''")}'` : 'NULL';

        // We use devAndCnss for both Department and CNSS Number to ensure it appears in the UI
        values.push(`(${esc(matricule)}, ${esc(fullName)}, ${esc(firstName)}, ${esc(lastName)}, ${esc(fonction)}, ${esc(devAndCnss)}, ${esc(devAndCnss)}, ${taux}, ${hireDate})`);
    }

    if (values.length > 0) {
        sql += values.join(",\n") + "\nON DUPLICATE KEY UPDATE \n" +
            "full_name=VALUES(full_name), first_name=VALUES(first_name), last_name=VALUES(last_name), " +
            "function_title=VALUES(function_title), department=VALUES(department), cnss_number=VALUES(cnss_number), hourly_rate=VALUES(hourly_rate), hire_date=VALUES(hire_date);\n";

        fs.writeFileSync('import_hr_employees.sql', sql, 'utf8');
        console.log(`Successfully generated import_hr_employees.sql with ${values.length} records including departments.`);
    } else {
        console.log("No valid rows found to import.");
    }

} catch (e) {
    console.error("Error:", e.message);
}
