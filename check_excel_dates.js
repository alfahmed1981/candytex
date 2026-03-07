const XLSX = require('xlsx');
const workbook = XLSX.readFile('PAIE 02-2026 HORAIRE.xls');
const sheet_name_list = workbook.SheetNames;
const worksheet = workbook.Sheets[sheet_name_list[0]];

const data = XLSX.utils.sheet_to_json(worksheet, { header: 1 });

console.log("Row 7:", data[6].slice(8, 15));
console.log("Row 8:", data[7].slice(8, 15));
console.log("Row 9:", data[8].slice(8, 15));

for (let i = 0; i < 5; i++) {
    if (data[i]) console.log("Header row " + i + ":", data[i].join(" | ").substring(0, 100));
}
