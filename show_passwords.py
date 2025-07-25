#!/usr/bin/env python3
"""
Display all email accounts and their passwords from the Excel file.
This is useful when Excel is open and you can't save to the file.
"""

import openpyxl
import os
from dotenv import load_dotenv

load_dotenv()

XLSX_PATH = os.getenv("XLSX_PATH", "emails.xlsx")
SHEET_NAME = os.getenv("SHEET_NAME") or None

def show_passwords():
    try:
        wb = openpyxl.load_workbook(XLSX_PATH)
        ws = wb[SHEET_NAME] if SHEET_NAME else wb.active
        
        print("EMAIL ACCOUNTS AND PASSWORDS")
        print("=" * 80)
        print(f"{'Email Address':<45} {'Password':<15} {'Status':<20}")
        print("-" * 80)
        
        accounts_with_passwords = []
        accounts_without_passwords = []
        
        for row in ws.iter_rows(min_row=2, min_col=1, max_col=3):
            cell_email, cell_pwd, cell_status = row
            email = (cell_email.value or "").strip()
            password = (cell_pwd.value or "").strip()
            status = (cell_status.value or "").strip()
            
            if email:
                if password:
                    accounts_with_passwords.append((email, password, status))
                    print(f"{email:<45} {password:<15} {status:<20}")
                else:
                    accounts_without_passwords.append((email, status))
        
        print("\n" + "=" * 80)
        print(f"SUMMARY:")
        print(f"✓ Accounts with passwords: {len(accounts_with_passwords)}")
        print(f"⚠ Accounts without passwords: {len(accounts_without_passwords)}")
        
        if accounts_without_passwords:
            print(f"\nACCOUNTS MISSING PASSWORDS:")
            print("-" * 50)
            for email, status in accounts_without_passwords:
                print(f"• {email}")
                print(f"  Status: {status}")
            
            print(f"\nTo generate passwords for these accounts:")
            print(f"1. Close Excel completely")
            print(f"2. Run: python recover_passwords.py")
        
        # Export to text file as backup
        with open("email_passwords.txt", "w") as f:
            f.write("EMAIL ACCOUNTS AND PASSWORDS\n")
            f.write("=" * 50 + "\n")
            from datetime import datetime
            f.write(f"Generated on: {datetime.now()}\n\n")
            
            for email, password, status in accounts_with_passwords:
                f.write(f"Email: {email}\n")
                f.write(f"Password: {password}\n")
                f.write(f"Status: {status}\n")
                f.write("-" * 30 + "\n")
            
            if accounts_without_passwords:
                f.write("\nACCOUNTS WITHOUT PASSWORDS:\n")
                for email, status in accounts_without_passwords:
                    f.write(f"Email: {email} (Status: {status})\n")
        
        print(f"\n💾 Password list also saved to: email_passwords.txt")
        
    except FileNotFoundError:
        print(f"ERROR: Excel file not found: {XLSX_PATH}")
    except Exception as e:
        print(f"ERROR: Failed to read Excel file: {e}")

if __name__ == "__main__":
    show_passwords()
