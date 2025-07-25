# cPanel Email Account Creator

A Python-based tool for bulk creating and managing email accounts in cPanel hosting environments. This project automates the process of creating multiple email accounts from an Excel spreadsheet and provides utilities for password management and account recovery.

## 🚀 Features

- **Bulk Email Creation**: Create multiple email accounts from Excel data
- **Secure Password Generation**: Cryptographically strong 14-character passwords
- **Password Recovery**: Set passwords for existing accounts missing them
- **Backup & Export**: Multiple backup formats (Excel, text files)
- **Error Handling**: Comprehensive error reporting and debugging
- **Authentication Options**: Support for both username/password and API tokens
- **Excel Integration**: Direct read/write to Excel files with status tracking

## 📋 Prerequisites

- Python 3.7 or higher
- cPanel hosting account with API access
- Excel file with email addresses to create
- Required Python packages (see Installation)

## 🛠️ Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/ingmtz/createEmails.git
   cd createEmails
   ```

2. **Install required packages**:
   ```bash
   pip install requests openpyxl python-dotenv
   ```

3. **Configure environment variables**:
   ```bash
   cp .env.example .env
   ```
   
   Edit `.env` with your cPanel credentials:
   ```bash
   CPANEL_HOST=your-cpanel-host.com
   CPANEL_USER=your-username
   CPANEL_PASS=your-password
   # CPANEL_TOKEN=your_api_token_here  # Optional: More secure than password
   DEFAULT_QUOTA=1024
   XLSX_PATH=emails.xlsx
   SHEET_NAME=Sheet1
   ```

## 📊 Excel File Format

Your Excel file should have the following structure:

| Column A | Column B | Column C |
|----------|----------|----------|
| Email Address | Password | Status |
| user1@domain.com | (generated) | (result) |
| user2@domain.com | (generated) | (result) |

- **Column A**: Email addresses to create
- **Column B**: Generated passwords (filled by script)
- **Column C**: Creation status ("OK" or error message)

## 🎯 Usage

### Main Email Creation Script

```bash
python createEmails.py
```

This script will:
1. Test cPanel authentication
2. Read email addresses from Excel file
3. Create email accounts with secure passwords
4. Update Excel file with results

### Password Recovery Tool

For accounts that already exist but need password updates:

```bash
python recover_passwords.py
```

This tool will:
1. Find accounts with blank passwords in Excel
2. Generate new secure passwords
3. Update existing account passwords via cPanel API
4. Save results back to Excel

### Backup and Display Tools

**Create a backup of all accounts**:
```bash
python backup_emails.py
```

**Display all passwords on screen**:
```bash
python show_passwords.py
```

## ⚙️ Configuration Options

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `CPANEL_HOST` | Your cPanel server hostname | Required |
| `CPANEL_USER` | cPanel username | Required |
| `CPANEL_PASS` | cPanel password | Required* |
| `CPANEL_TOKEN` | API token (more secure) | Optional |
| `DEFAULT_QUOTA` | Email quota in MB (0 = unlimited) | 0 |
| `XLSX_PATH` | Path to Excel file | emails.xlsx |
| `SHEET_NAME` | Excel sheet name | Sheet1 |
| `VERIFY_SSL` | SSL certificate verification | True |
| `DEBUG` | Enable debug output | False |
| `SKIP_OK_ROWS` | Skip rows already marked "OK" | True |
| `SKIP_AUTH_TEST` | Skip authentication test | False |

*Either `CPANEL_PASS` or `CPANEL_TOKEN` is required.

### Security Recommendations

1. **Use API Tokens**: More secure than passwords
   - Log into cPanel → Security → Manage API Tokens
   - Create a new token
   - Add `CPANEL_TOKEN=your_token` to `.env`
   - Comment out `CPANEL_PASS`

2. **Enable SSL Verification**: Keep `VERIFY_SSL=True`

3. **Secure Password Storage**: Never commit `.env` to version control

## 🔧 Troubleshooting

### Common Issues

**"The login is invalid" errors**:
- Check cPanel credentials in `.env`
- Verify two-factor authentication is disabled for API access
- Try using an API token instead of password
- Contact hosting provider to enable API access

**Excel file locked errors**:
- Close Excel completely before running scripts
- Use backup tools to view data while Excel is open

**Authentication failures**:
- Set `SKIP_AUTH_TEST=True` if auth test fails but creation works
- Enable `DEBUG=True` for detailed error information

### Debug Mode

Enable detailed logging:
```bash
DEBUG=True
```

This will show:
- Full HTTP requests and responses
- JSON parsing details
- Authentication method used
- Detailed error messages

## 📁 Project Structure

```
createEmails/
├── createEmails.py          # Main email creation script
├── recover_passwords.py     # Password recovery tool
├── backup_emails.py         # Backup creation utility
├── show_passwords.py        # Password display tool
├── emails.xlsx              # Excel file with email data
├── .env                     # Configuration (not in git)
├── .env.example             # Configuration template
└── README.md                # This file
```

## 🔐 Security Features

- **Cryptographically secure password generation** using Python's `secrets` module
- **Mixed character types**: uppercase, lowercase, and digits
- **Randomized password patterns** to prevent predictability
- **API token support** for enhanced security
- **SSL verification** for secure connections

## 📝 Password Format

Generated passwords include:
- Length: 14 characters
- At least 1 uppercase letter
- At least 1 lowercase letter  
- At least 1 digit
- Shuffled order to prevent patterns

Example: `XywtG9ibGJkGoj`

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License. See LICENSE file for details.

## 🆘 Support

For issues and questions:
1. Check the troubleshooting section
2. Enable debug mode for detailed error information
3. Contact your hosting provider for cPanel API access issues
4. Create an issue on GitHub for bugs or feature requests

## 📚 API Reference

This tool uses the cPanel UAPI (Unified API) endpoints:
- `Email/add_pop` - Create email accounts
- `Email/passwd_pop` - Change passwords
- `Email/count_pops` - Test authentication

For more information, see the [cPanel UAPI Documentation](https://documentation.cpanel.net/display/DD/Guide+to+UAPI).

---

**Note**: This tool is designed for cPanel-based hosting environments. Make sure your hosting provider supports cPanel UAPI access before using this tool.
