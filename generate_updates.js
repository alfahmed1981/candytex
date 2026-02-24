const xlsx = require('xlsx');
const fs = require('fs');

const filePath = process.argv[2];

try {
    const workbook = xlsx.readFile(filePath);

    // MENS sheet for Monthly employees
    const mensSheet = workbook.Sheets['MENS'];
    const mensData = xlsx.utils.sheet_to_json(mensSheet, { header: 1, defval: null });

    // HORAIRE sheet for Hourly employees
    const horSheet = workbook.Sheets['HORAIRE'];
    const horData = xlsx.utils.sheet_to_json(horSheet, { header: 1, defval: null });

    let sql = "-- Update Payment Types based on Excel\n\n";
    let updates = 0;

    // Monthly Sheet (We assume everyone here is 'Monthly')
    for (let i = 2; i < mensData.length; i++) {
        const row = mensData[i];
        if (!row || row.length < 5) continue;
        let matricule = String(row[0]).trim();
        let fullName = String(row[1]).trim();
        if (!matricule || !fullName || matricule === 'null' || fullName === 'null' || fullName.includes('TOTAL')) continue;

        sql += `UPDATE \`hr_employees\` SET \`payment_type\` = 'Monthly' WHERE \`matricule\` = '${matricule}';\n`;
        updates++;
    }

    let horMonthly = 0;
    // Hourly Sheet (We check the rate to see if they are actually monthly inserted here for grid context)
    for (let i = 2; i < horData.length; i++) {
        const row = horData[i];
        if (!row || row.length < 5) continue;
        let matricule = String(row[0]).trim();
        let fullName = String(row[1]).trim();
        if (!matricule || !fullName || matricule === 'null' || fullName === 'null' || fullName.includes('TOTAL')) continue;

        let rate = parseFloat(row[6]) || 0;

        // If the "hourly" rate is > 500 MAD, it's definitely a Monthly Salary.
        if (rate > 500) {
            sql += `UPDATE \`hr_employees\` SET \`payment_type\` = 'Monthly' WHERE \`matricule\` = '${matricule}';\n`;
            horMonthly++;
        } else {
            sql += `UPDATE \`hr_employees\` SET \`payment_type\` = 'Hourly' WHERE \`matricule\` = '${matricule}';\n`;
        }
        updates++;
    }

    fs.writeFileSync('update_payment_types.sql', sql, 'utf8');
    console.log(`Generated SQL to update ${updates} employee payment types.`);
    console.log(`Found ${horMonthly} monthly employees disguised in the HORAIRE sheet.`);

} catch (e) {
    console.error("Error:", e.message);
}
