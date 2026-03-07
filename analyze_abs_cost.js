const XLSX = require('xlsx');

const workbook = XLSX.readFile('PAIE 02-2026 HORAIRE.xls');
const sheets_to_process = ['HORAIRE', 'MENS'];

console.log("--- SALARY & ABSENCE IMPACT ANALYSIS ---");

for (const sheetName of sheets_to_process) {
    if (!workbook.Sheets[sheetName]) continue;
    const sheet = workbook.Sheets[sheetName];
    const data = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: null });

    let abs_impact_sum = 0;
    
    // Check first 5 employees as a sample
    let sample = 0;
    for (let i = 3; i < data.length && sample < 5; i++) {
        const row = data[i];
        if (!row || row.length < 5 || !row[0]) continue;
        
        const fullname = row[1];
        
        let daily_hours = [];
        let abs_days = 0;
        
        for (let col = 7; col <= 37; col++) {
            let val = row[col];
            let str = val !== null && val !== undefined ? String(val).trim().toUpperCase() : '';
            if (str === 'A') {
                abs_days++;
            } else {
                let p = parseFloat(str);
                if (!isNaN(p)) daily_hours.push(p);
            }
        }
        
        let sum_worked = daily_hours.reduce((a, b) => a + b, 0);
        let avg_daily = daily_hours.length > 0 ? (sum_worked / daily_hours.length) : 0;
        
        if (sheetName === 'HORAIRE') {
            let excel_nh = parseFloat(row[38]) || 0;
            let taux = parseFloat(row[6]) || 0;
            let brut = parseFloat(row[40]) || 0;
            let net = parseFloat(row[45]) || 0;
            
            if (abs_days > 0 && taux > 0) {
                sample++;
                let estimated_loss = abs_days * avg_daily * taux;
                console.log(`\nEmployee: ${fullname} (Hourly)`);
                console.log(`  - Days Absent (A): ${abs_days}`);
                console.log(`  - Avg Hours/Day: ${avg_daily.toFixed(1)}`);
                console.log(`  - Hourly Rate: ${taux} MAD`);
                console.log(`  - Estimated Salary Loss Due to Absence: ~${estimated_loss.toFixed(2)} MAD`);
                console.log(`  - Actual Brut Paid: ${brut} MAD (For ${excel_nh} hours)`);
            }
        } else {
             // Mensuel
            let taux_mensuel = parseFloat(row[6]) || 0;
            let brut = parseFloat(row[31]) || 0; // Usually brut is here in MENS
            
            if (abs_days > 0 && taux_mensuel > 0) {
                sample++;
                let daily_rate = taux_mensuel / 26; // Standard 26 working days
                let estimated_loss = abs_days * daily_rate;
                
                console.log(`\nEmployee: ${fullname} (Monthly)`);
                console.log(`  - Days Absent (A): ${abs_days}`);
                console.log(`  - Monthly Salary Base: ${taux_mensuel} MAD`);
                console.log(`  - Estimated Salary Loss: ~${estimated_loss.toFixed(2)} MAD`);
            }
        }
    }
}
