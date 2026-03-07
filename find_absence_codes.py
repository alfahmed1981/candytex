import pandas as pd

file_path = "d:/iso candytex/candytex/PAIE 02-2026 HORAIRE.xls"
excel_file = pd.ExcelFile(file_path, engine='xlrd')
print("Sheet names:", excel_file.sheet_names)
