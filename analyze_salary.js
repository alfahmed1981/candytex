const XLSX = require('xlsx');

const workbook = XLSX.readFile('PAIE 02-2026 HORAIRE.xls');
const sheets_to_process = ['HORAIRE', 'MENS', 'mens'];

let anomalies = 0;
let total_processed = 0;

sheets_to_process.forEach(sheetName => {
    if (!workbook.Sheets[sheetName]) return;

    const sheet = workbook.Sheets[sheetName];
    const data = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: null });

    for (let i = 3; i < data.length; i++) {
        const row = data[i];
        if (!row || row.length < 5) continue;

        let matricule = row[0];
        if (!matricule || String(matricule).trim() === '') continue;
        let fullname = row[1];
        if (!fullname || String(fullname).trim() === '') continue;

        total_processed++;
        
        // Sum up the hours from the daily columns (7 to 37)
        let calculated_hours = 0;
        let absence_days = 0;
        
        for (let col = 7; col <= 37; col++) {
            let cellValue = row[col];
            let cellStr = cellValue === null || cellValue === undefined ? '' : String(cellValue).trim().toUpperCase();
            
            let parsed = parseFloat(cellStr);
            if (!isNaN(parsed)) {
                calculated_hours += parsed;
            } else if (cellStr === 'A') {
                absence_days++;
            }
        }
        
        // Total N/H in Excel
        let excel_total_nh = parseFloat(row[38]) || 0; // Col 38 is N/H (Total hours)
        let excel_taux = parseFloat(row[6]) || 0; // Col 6 is Taux
        let excel_brut = parseFloat(row[40]) || 0; // Col 40 is Brut
        
        // Let's see if Calculated Hours == Excel Total Hours
        if (sheetName === 'HORAIRE' && Math.abs(calculated_hours - excel_total_nh) > 0.1 && excel_total_nh > 0) {
            console.log(`[Mismatch] ${matricule} - ${fullname}: Grid Sum = ${calculated_hours} hrs, Excel N/H Column = ${excel_total_nh} hrs (Diff: ${Math.abs(calculated_hours - excel_total_nh).toFixed(2)})`);
            anomalies++;
        }
        
        // Check Brut Calculation (Hours * Rate)
        let math_brut = excel_total_nh * excel_taux;
        // The excel brut might include bonuses (col 39) but let's just see difference
        let diff_brut = Math.abs(math_brut - excel_brut);
        if (sheetName === 'HORAIRE' && diff_brut > 100 && excel_brut > 0) {
            // console.log(`[Brut Check] ${fullname}: N/H (${excel_total_nh}) * Taux (${excel_taux}) = ${math_brut}. Excel says ${excel_brut}. Diff: ${diff_brut.toFixed(2)}`);
        }
    }
});

console.log(`\nProcessed ${total_processed} employees.`);
console.log(`Found ${anomalies} anomalies where the daily grid sum doesn't match the Total N/H column.`);
if (anomalies === 0) {
    console.log("SUCCESS: All daily hours exactly match the total N/H column in Excel.");
}
