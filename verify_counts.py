import pandas as pd
from datetime import datetime, timedelta

file_path = "PAIE 02-2026 HORAIRE.xls"

try:
    # We will process both sheets just like PHP does
    sheets_to_process = ['HORAIRE', 'MENS', 'mens']
    excel_file = pd.ExcelFile(file_path)
    
    # Target dates
    dates = []
    # Nov 26 to Dec 25 mapped to 2026-01-26 to 2026-02-25
    for d in range(26, 32):
        dates.append(f"2026-01-{d:02d}")
    for d in range(1, 26):
        dates.append(f"2026-02-{d:02d}")
        
    all_blocks = []
    emp_count = 0
    
    for sheet_name in sheets_to_process:
        if sheet_name not in excel_file.sheet_names:
            continue
            
        df = pd.read_excel(file_path, sheet_name=sheet_name, header=1)
        
        # PHP logic checks rows starting from index 3 (which in this df would be index 1 because header is row 1 (0 in pandas for our header=1, wait...
        # In php, header: 1 returns array of arrays. row 0 is first row, row 1 is second row (dates). Data starts at row 3 (0,1,2,3 -> 4th row).
        # We can just iterate through the rows and look for employee numbers
        
        # The columns are 7 to 37 in pandas if it's 31 days
        date_cols = df.columns[6:37] # columns index 6 to 36
        
        for index, row in df.iterrows():
            matricule = str(row.iloc[0]).strip()
            if matricule == 'nan' or not matricule:
                continue
                
            emp_count += 1
            current_block = None
            emp_blocks = []
            
            for col_idx in range(6, 37): # 31 columns
                cell_val = str(row.iloc[col_idx]).strip().upper()
                date_str = dates[col_idx - 6]
                
                # Determine status exactly like PHP
                status = 'P'
                if cell_val == 'A':
                    status = 'A'
                elif cell_val == 'W' or '*' in cell_val:
                    status = 'W'
                elif cell_val in ('ML', 'M'):
                    status = 'M'
                elif cell_val in ('MT', 'MAT'):
                    status = 'MAT'
                elif cell_val in ('AT', 'ACC'):
                    status = 'ACC'
                else:
                    try:
                        float(cell_val)
                        status = 'P'
                    except ValueError:
                        status = 'P' # empty or unknown
                        if cell_val == 'NAN' or cell_val == '':
                            pass # Still considered a break in absence
                            
                # Grouping logic
                if status in ['A', 'M', 'MAT', 'ACC']:
                    if current_block and current_block['type'] == status:
                        # consecutive check
                        d1 = datetime.strptime(current_block['end_date'], "%Y-%m-%d")
                        d2 = datetime.strptime(date_str, "%Y-%m-%d")
                        diff = (d2 - d1).days
                        if diff <= 3:
                            current_block['end_date'] = date_str
                        else:
                            emp_blocks.append(current_block)
                            current_block = {'type': status, 'start': date_str, 'end_date': date_str}
                    else:
                        if current_block:
                            emp_blocks.append(current_block)
                        current_block = {'type': status, 'start': date_str, 'end_date': date_str}
                elif status == 'P' or cell_val == 'NAN' or cell_val == '':
                    if current_block:
                        emp_blocks.append(current_block)
                        current_block = None
            
            if current_block:
                emp_blocks.append(current_block)
                
            all_blocks.extend(emp_blocks)
            
    # Now filter by the report date range (Feb 1 to Feb 28)
    filter_start = datetime.strptime("2026-02-01", "%Y-%m-%d")
    filter_end = datetime.strptime("2026-02-28", "%Y-%m-%d")
    
    counts = {'A': 0, 'M': 0, 'MAT': 0, 'ACC': 0}
    
    for b in all_blocks:
        b_start = datetime.strptime(b['start'], "%Y-%m-%d")
        b_end = datetime.strptime(b['end_date'], "%Y-%m-%d")
        
        # Overlap condition
        if (filter_start <= b_start <= filter_end) or \
           (filter_start <= b_end <= filter_end) or \
           (b_start <= filter_start and b_end >= filter_end):
            if b['type'] in counts:
                counts[b['type']] += 1
                
    print(f"Total Employees Processed: {emp_count}")
    print(f"Total Absences Overlapping Feb 1 - Feb 28:")
    print(f"Maladie (M): {counts['M']}")
    print(f"Maternite (MAT): {counts['MAT']}")
    print(f"Accidents (ACC): {counts['ACC']}")
    print(f"Absences (A): {counts['A']}")
    print(f"Total: {sum(counts.values())}")
    
except Exception as e:
    print(f"Error: {e}")
