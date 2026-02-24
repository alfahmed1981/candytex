const xlsx = require('xlsx');
const fs = require('fs');

try {
    const wb = xlsx.readFile('PAIE 12-2025 HORAIRE.xlsx');
    const hor = xlsx.utils.sheet_to_json(wb.Sheets['HORAIRE'], { header: 1, defval: null });

    // Look for ACHRAF
    const achrafHor = hor.filter(r => r.some(c => String(c).toUpperCase().includes('ACHRAF')));

    let out = "ACHRAF in HORAIRE:\n" + JSON.stringify(achrafHor, null, 2) + "\n";
    fs.writeFileSync('achraf_debug2.txt', out);
    console.log("Done");
} catch (e) {
    console.error(e);
}
