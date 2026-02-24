const xlsx = require('xlsx');

try {
    const wb = xlsx.readFile('PAIE 12-2025 HORAIRE.xlsx');
    const sheetMENS = wb.Sheets['MENS'];
    const dataMENS = xlsx.utils.sheet_to_json(sheetMENS, { header: 1, defval: null });

    console.log("MENS Row 2 (Headers):");
    dataMENS[2].forEach((v, i) => console.log(`${i}: ${v}`));

    console.log("\nMENS Row 4 (Sample Data):");
    dataMENS[4].forEach((v, i) => console.log(`${i}: ${v}`));

} catch (e) {
    console.error(e.message);
}
