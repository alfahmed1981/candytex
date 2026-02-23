const xlsx = require('xlsx');

const filePath = process.argv[2];

try {
    const workbook = xlsx.readFile(filePath);
    const sheetName = workbook.SheetNames[0];
    const sheet = workbook.Sheets[sheetName];

    // Read raw data exactly as it is in the grid
    const data = xlsx.utils.sheet_to_json(sheet, { header: 1, defval: "" });

    console.log("--- TOP 10 ROWS OF EXCEL GRID ---");
    for (let i = 0; i < Math.min(10, data.length); i++) {
        const rowString = data[i].map(c => `[${c}]`).join(" ");
        console.log(`ROW ${i + 1}: ${rowString}`);
    }

    console.log("\n--- LAST 3 ROWS (Totals maybe?) ---");
    for (let i = Math.max(0, data.length - 3); i < data.length; i++) {
        const rowString = data[i].map(c => `[${c}]`).join(" ");
        console.log(`ROW ${i + 1}: ${rowString}`);
    }

} catch (e) {
    console.error("Error:", e.message);
}
