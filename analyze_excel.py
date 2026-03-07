import pandas as pd
import sys

file_path = "d:/iso candytex/candytex/PAIE 02-2026 HORAIRE.xls"
try:
    # Try reading with xlrd (older .xls)
    # The header is often row 2 (0-indexed, so 1 or 2)
    # Based on the earlier node output, Row 1 and Row 2 have the headers
    # Row 1: ['PAIE HORAIRE MOIS 02-2026', null, null, null, null, null, null, 26, 27, 28...]
    # Row 2: [null, 'NOM&PRENOM', 'Fonction', 'CNSS', null, 'D emb', 'Taux', null...]
    
    df = pd.read_excel(file_path, engine='xlrd', header=None)
    print("Shape:", df.shape)
    
    # Print first 5 rows to see structure
    for i in range(5):
        row_vals = df.iloc[i].fillna("").tolist()
        # limit length of strings
        row_str = [str(x)[:20] for x in row_vals]
        print(f"Row {i}:", row_str)

    print("\n--- Column Mapping Analysis ---")
    headers_row1 = df.iloc[1].fillna("").tolist()
    headers_row2 = df.iloc[2].fillna("").tolist()
    
    for col_idx in range(df.shape[1]):
        val1 = str(headers_row1[col_idx]).strip()
        val2 = str(headers_row2[col_idx]).strip()
        # Combine row 1 and 2 to get the column meaning
        col_name = f"{val1} {val2}".strip()
        if col_name:
            print(f"Col {col_idx}: {col_name}")
            
except Exception as e:
    print("Error:", e)
    sys.exit(1)
