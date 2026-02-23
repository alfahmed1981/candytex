import pandas as pd
import sys

file_path = r"c:\Users\MON PC\Documents\GitHub\candytexiso\candytex\PAIE 12-2025 HORAIRE.xlsx"

try:
    # Read the excel file
    df = pd.read_excel(file_path)
    
    print("--- HEADERS ---")
    for col in df.columns:
        print(f"- {col}")
        
    print("\n--- SAMPLE DATA (First 3 rows) ---")
    print(df.head(3).to_string())
    
except Exception as e:
    print(f"Error reading excel file: {e}")
