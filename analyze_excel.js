const xlsx = require('xlsx');

try {
    console.log("Loading Excel file...");
    const wb = xlsx.readFile('PAIE 12-2025 HORAIRE.xlsx');
    console.log("SHEETS FOUND: ", wb.SheetNames.join(', '));

    wb.SheetNames.forEach(sheetName => {
        console.log(`\n================= SHEET: ${sheetName} ==================`);
        const sheet = wb.Sheets[sheetName];
        const data = xlsx.utils.sheet_to_json(sheet, { header: 1, defval: null });

        console.log(`Total Rows: ${data.length}`);
        if (data.length > 5) {
            console.log("ROW 0 (Headers/Titles):", data[0] ? data[0].filter(c => c !== null).join(' | ') : 'empty');
            console.log("ROW 2 (Headers/Titles):", data[2] ? data[2].filter(c => c !== null).join(' | ') : 'empty');
            console.log("ROW 3 (First Data Row):", data[3]);
            console.log("ROW 4 (Second Data Row):", data[4]);
        }
    });
} catch (e) {
    console.error("Error analyzing:", e.message);
}
