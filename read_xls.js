const xlsx = require('xlsx');

try {
    const workbook = xlsx.readFile('PAIE 02-2026 HORAIRE.xls');
    const sheetName = workbook.SheetNames[0];
    const sheet = workbook.Sheets[sheetName];
    const data = xlsx.utils.sheet_to_json(sheet, {header: 1});

    console.log("Total rows:", data.length);
    console.log("--- First 10 rows ---");
    for (let i = 0; i < Math.min(10, data.length); i++) {
        console.log(`Row ${i}:`, JSON.stringify(data[i]));
    }
} catch (err) {
    console.error("Error reading file:", err.message);
}
