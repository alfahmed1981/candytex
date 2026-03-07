const XLSX = require('xlsx');
const workbook = XLSX.readFile('PAIE 02-2026 HORAIRE.xls');
const sheet = workbook.Sheets['HORAIRE'] || workbook.Sheets[workbook.SheetNames[0]];

const data = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: null });

// Print row 2 (index 1) which probably has the date headers + Total headers
if (data[1]) {
    console.log("Row 1 (Dates/Headers):");
    for (let c = 30; c < data[1].length; c++) {
        console.log(`Col ${c}: ${data[1][c]}`);
    }
}

console.log("\nRow 2 (Sub-headers):");
if (data[2]) {
    for (let c = 30; c < Math.max(data[2].length, data[3].length); c++) {
        console.log(`Col ${c}: ${data[2][c]}  | Value in row 3: ${data[3][c]}  | Value in row 4: ${data[4][c]}`);
    }
}
