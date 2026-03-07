import pandas as pd
import json

file_path = "PAIE 02-2026 HORAIRE.xls"
try:
    df = pd.read_excel(file_path, sheet_name=0, header=10)
    # The dates start at column 10 (index 9) for 31 days.
    # We just need to know what dates the columns represent or what dates are in the header.
    # Alternatively, let's just read the header row directly:
    df_raw = pd.read_excel(file_path, sheet_name=0, header=None)
    row_7 = df_raw.iloc[7].values.tolist()
    row_8 = df_raw.iloc[8].values.tolist()
    row_9 = df_raw.iloc[9].values.tolist()
    print("Row 7:", [str(x) for x in row_7[8:15]])
    print("Row 8:", [str(x) for x in row_8[8:15]])
    print("Row 9:", [str(x) for x in row_9[8:15]])
    
    # Also check the "Période du" text
    print("Row 2:", [str(x) for x in df_raw.iloc[2].values.tolist() if str(x) != 'nan' and 'riode' in str(x)])
except Exception as e:
    print("Error:", str(e))
