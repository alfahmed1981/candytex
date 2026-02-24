const xlsx = require('xlsx');
const fs = require('fs');

try {
    const wb = xlsx.readFile('PAIE 12-2025 HORAIRE.xlsx');

    // MENS sheet
    const mens = xlsx.utils.sheet_to_json(wb.Sheets['MENS'], { header: 1, defval: null });
    let out = "--- MENS ---\n";
    for (let i = 0; i < 10; i++) out += JSON.stringify(mens[i]) + "\n";

    // Look for ACHRAF in MENS
    const achrafMens = mens.filter(r => r.some(c => String(c).toUpperCase().includes('ACHRAF')));
    out += "\nACHRAF in MENS:\n" + JSON.stringify(achrafMens, null, 2) + "\n";

    fs.writeFileSync('achraf_debug.txt', out);
    console.log("Done");
} catch (e) {
    console.error(e);
}
