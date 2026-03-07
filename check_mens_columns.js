const XLSX = require('xlsx');
const workbook = XLSX.readFile('PAIE 02-2026 HORAIRE.xls');

// Let's find the Mens sheet
let mensSheetName = null;
workbook.SheetNames.forEach(name => {
    if (name.toUpperCase() === 'MENS') {
        mensSheetName = name;
    }
});

if (mensSheetName) {
    const sheet = workbook.Sheets[mensSheetName];
    const data = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: null });

    console.log("MENS Sheet Row 1 (Headers):");
    if (data[1]) {
        for (let c = 25; c < data[1].length; c++) {
             console.log(`Col ${c}: ${data[1][c]}`);
        }
    }
    
    console.log("\nMENS Sheet Row 2 (Sub-headers):");
    if (data[2]) {
        for (let c = 25; c < Math.max(data[2].length, data[3].length); c++) {
            console.log(`Col ${c}: ${data[2][c]}  | Row 3: ${data[3][c]}  | Row 4: ${data[4][c]}`);
        }
    }
} else {
    console.log("MENS sheet not found!");
}
