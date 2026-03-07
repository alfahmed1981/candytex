const XLSX = require('xlsx');

const workbook = XLSX.readFile('PAIE 02-2026 HORAIRE.xls');
const sheets_to_process = ['HORAIRE', 'MENS', 'mens'];

const dates = [];
for (let d = 26; d <= 31; d++) dates.push(`2026-01-${String(d).padStart(2, '0')}`);
for (let d = 1; d <= 25; d++) dates.push(`2026-02-${String(d).padStart(2, '0')}`);

let all_blocks = [];
let emp_count = 0;

sheets_to_process.forEach(sheetName => {
    if (!workbook.Sheets[sheetName]) return;

    const sheet = workbook.Sheets[sheetName];
    const data = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: null });

    // Data starts around row 3 (index 3 in 0-based if not strict header)
    for (let i = 3; i < data.length; i++) {
        const row = data[i];
        if (!row || row.length < 5) continue;

        let matricule = row[0];
        if (!matricule || String(matricule).trim() === '') continue;
        let fullname = row[1];
        if (!fullname || String(fullname).trim() === '') continue;

        emp_count++;
        let current_block = null;
        let emp_blocks = [];

        // columns 7 to 37 are the 31 days
        for (let col = 7; col <= 37; col++) {
            let cellValue = row[col];
            let dateStr = dates[col - 7];

            let cellStr = cellValue === null || cellValue === undefined ? '' : String(cellValue).trim().toUpperCase();

            let status = 'P';
            if (cellStr === 'A') {
                status = 'A';
            } else if (cellStr === 'W' || cellStr.includes('*')) {
                status = 'W';
            } else if (cellStr === 'ML' || cellStr === 'M') {
                status = 'M';
            } else if (cellStr === 'MT' || cellStr === 'MAT') {
                status = 'MAT';
            } else if (cellStr === 'AT' || cellStr === 'ACC') {
                status = 'ACC';
            } else {
                let parsed = parseFloat(cellStr);
                if (!isNaN(parsed)) {
                    status = 'P';
                } else {
                    continue; // Skip the cell completely, just like hr_import.js in PHP!
                }
            }

            if (['A', 'M', 'MAT', 'ACC'].includes(status)) {
                if (current_block && current_block.type === status) {
                    let d1 = new Date(current_block.end_date);
                    let d2 = new Date(dateStr);
                    let diffDays = (d2 - d1) / (1000 * 60 * 60 * 24);
                    if (diffDays <= 3) {
                        current_block.end_date = dateStr;
                    } else {
                        emp_blocks.push(current_block);
                        current_block = { type: status, start_date: dateStr, end_date: dateStr };
                    }
                } else {
                    if (current_block) emp_blocks.push(current_block);
                    current_block = { type: status, start_date: dateStr, end_date: dateStr };
                }
            } else if (status === 'P') {
                // Notice: In PHP, empty cells ('') are skipped by JS.
                // We only break if we see a genuine 'P' (number)
                if (current_block) {
                    emp_blocks.push(current_block);
                    current_block = null;
                }
            }
            // IF it's empty cellStr === '', we do nothing, meaning current_block remains open!
        }
        if (current_block) emp_blocks.push(current_block);
        
        all_blocks = all_blocks.concat(emp_blocks);
    }
});

const filter_start = new Date("2026-02-01");
const filter_end = new Date("2026-02-28");

const counts = { 'A': 0, 'M': 0, 'MAT': 0, 'ACC': 0 };

all_blocks.forEach(b => {
    let b_start = new Date(b.start_date);
    let b_end = new Date(b.end_date);

    if ((b_start >= filter_start && b_start <= filter_end) ||
        (b_end >= filter_start && b_end <= filter_end) ||
        (b_start <= filter_start && b_end >= filter_end)) {
        if (counts[b.type] !== undefined) {
            counts[b.type]++;
        }
    }
});

console.log(`Total Employees Processed: ${emp_count}`);
console.log(`\nExact counts of absences overlapping Feb 1st - Feb 28th based on Excel:`);
console.log(`Maternite (MAT): ${counts['MAT']}`);
console.log(`Maladie (M): ${counts['M']}`);
console.log(`Absences Injustifiees (A): ${counts['A']}`);
console.log(`Accidents de Travail (ACC): ${counts['ACC']}`);
console.log(`Total: ${counts['MAT'] + counts['M'] + counts['A'] + counts['ACC']}`);
