# 📖 cPanel Quick Reference Guide

## 🚀 Fast Setup (5 minutes)

### 1. Upload Project
```bash
# SSH into cPanel
ssh username@yourdomain.com

# Clone project
cd ~/public_html
git clone https://github.com/delwerhossain/carvendors-scraper.git
cd carvendors-scraper

# Set permissions
chmod -R 755 logs data images
chmod 644 config.php scrape-carsafari.php CarScraper.php CarSafariScraper.php
```

### 2. Create Database
**In cPanel → MySQL Databases:**
1. Create database: `yourprefix_carsafari`
2. Create user: `yourprefix_caruser`
3. Password: (strong, 16+ chars)
4. Add user to database → ALL privileges

### 3. Configure Database
Edit `config.php`:
```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'yourprefix_carsafari',     # Your database
    'username' => 'yourprefix_caruser',       # Your user
    'password' => 'your_password_here',       # Your password
    'charset'  => 'utf8mb4',
],
```

### 4. Apply SQL Migration 1 (REQUIRED)
**In cPanel → phpMyAdmin:**
1. Select your database
2. Click "SQL" tab
3. Paste content from: `sql/01_ADD_UNIQUE_REG_NO.sql`
4. Click "Execute"

### 5. Test Scraper
```bash
# Quick test
cd ~/public_html/carvendors-scraper
/usr/bin/php scrape-carsafari.php --no-details

# Check logs
tail logs/scraper_*.log
```

### 6. Setup Cron Job
**In cPanel → Cron Jobs:**
1. Click "Add New Cron Job"
2. Common Settings: "Often" (every 12 hours)
3. Command:
```
/usr/bin/php /home/username/public_html/carvendors-scraper/scrape-carsafari.php >> /home/username/public_html/carvendors-scraper/logs/cron.log 2>&1
```
4. Add

---

## 🔧 Common Tasks

### Check Database Connection
```bash
cd ~/public_html/carvendors-scraper
/usr/bin/php -r "
\$config = require 'config.php';
try {
    \$pdo = new PDO('mysql:host=localhost;dbname=' . \$config['database']['dbname'],
                    \$config['database']['username'],
                    \$config['database']['password']);
    \$count = \$pdo->query('SELECT COUNT(*) FROM gyc_vehicle_info')->fetchColumn();
    echo '✓ Connected! Vehicles in DB: ' . \$count . '\n';
} catch (Exception \$e) {
    echo '✗ Error: ' . \$e->getMessage() . '\n';
}
"
```

### Run Full Scrape
```bash
cd ~/public_html/carvendors-scraper
/usr/bin/php scrape-carsafari.php

# With details (slower, ~3-5 minutes)
/usr/bin/php scrape-carsafari.php --details

# Without details (faster, ~1-2 minutes)
/usr/bin/php scrape-carsafari.php --no-details
```

### View Recent Logs
```bash
# Latest scrape log
tail -100 ~/public_html/carvendors-scraper/logs/scraper_*.log

# Cron job log
tail -50 ~/public_html/carvendors-scraper/logs/cron.log

# Follow live logs
tail -f ~/public_html/carvendors-scraper/logs/scraper_*.log
```

### Check Scrape Statistics
```bash
cd ~/public_html/carvendors-scraper
/usr/bin/php -r "
\$config = require 'config.php';
\$pdo = new PDO('mysql:host=localhost;dbname=' . \$config['database']['dbname'],
                \$config['database']['username'],
                \$config['database']['password']);

// Count vehicles
\$vehicles = \$pdo->query('SELECT COUNT(*) FROM gyc_vehicle_info')->fetchColumn();
echo 'Total vehicles: ' . \$vehicles . '\n';

// Count with color
\$colors = \$pdo->query('SELECT COUNT(*) FROM gyc_vehicle_info WHERE color IS NOT NULL')->fetchColumn();
echo 'With color: ' . \$colors . '/' . \$vehicles . '\n';

// Count with engine_size
\$engines = \$pdo->query('SELECT COUNT(*) FROM gyc_vehicle_attribute WHERE engine_size IS NOT NULL')->fetchColumn();
echo 'With engine_size: ' . \$engines . '\n';

// Count images
\$images = \$pdo->query('SELECT COUNT(*) FROM gyc_product_images')->fetchColumn();
echo 'Total images: ' . \$images . '\n';
"
```

### Export Vehicles to JSON
```bash
cd ~/public_html/carvendors-scraper
cat data/vehicles.json | head -100   # First 100 lines
wc -l data/vehicles.json              # Total lines
```

### Check Cron Job Is Scheduled
```bash
crontab -l    # List all cron jobs

# Or check cPanel file
cat /var/spool/cron/crontabs/username
```

### Test Cron Command Manually
```bash
# This is the exact command cron runs
/usr/bin/php /home/username/public_html/carvendors-scraper/scrape-carsafari.php >> /home/username/public_html/carvendors-scraper/logs/cron.log 2>&1

# Check if it ran
tail logs/cron.log
```

---

## 🐛 Troubleshooting

### "Command not found: php"
```bash
# Find PHP path
which php              # Usually: /usr/bin/php

# Use full path
/usr/bin/php scrape-carsafari.php

# Or add to crontab
0 6 * * * /usr/bin/php /home/username/public_html/carvendors-scraper/scrape-carsafari.php
```

### "Permission denied"
```bash
# Fix directory permissions
chmod -R 755 ~/public_html/carvendors-scraper/logs
chmod -R 755 ~/public_html/carvendors-scraper/data
chmod -R 755 ~/public_html/carvendors-scraper/images
chmod 644 ~/public_html/carvendors-scraper/config.php
```

### "Could not connect to database"
**In cPanel:**
1. MySQL Databases → your database
2. Check username and password
3. Verify user has ALL privileges
4. Check "Manage User Privileges"

**In `config.php`:**
```php
'database' => [
    'host'     => 'localhost',                # Usually localhost
    'dbname'   => 'yourcp_yourdb',            # Exact database name
    'username' => 'yourcp_youruser',          # Exact username
    'password' => 'check_cpanel',             # Exact password
    'charset'  => 'utf8mb4',
],
```

### Cron job not running
```bash
# Check if cron is running
ps aux | grep cron

# Check cron error log
tail -50 /var/log/cron

# Verify command syntax
/usr/bin/php -l scrape-carsafari.php    # Check PHP syntax

# Test command directly
cd ~/public_html/carvendors-scraper
/usr/bin/php scrape-carsafari.php

# Check log output
tail logs/cron.log
```

### Database queries are slow
```bash
# Check if indexes are working
cd ~/public_html/carvendors-scraper
/usr/bin/php -r "
\$pdo = new PDO('mysql:host=localhost;dbname=yourdb', 'user', 'pass');
\$result = \$pdo->query('SHOW INDEX FROM gyc_vehicle_info');
foreach (\$result as \$row) {
    echo \$row['Key_name'] . ': ' . \$row['Column_name'] . '\n';
}
"

# Or in phpMyAdmin → your table → Indexes tab
```

---

## 📊 Database Queries for Monitoring

### Vehicles by Status
```sql
SELECT 
    active_status, 
    COUNT(*) as count 
FROM gyc_vehicle_info 
GROUP BY active_status;
```

### Color Distribution
```sql
SELECT 
    color, 
    COUNT(*) as count 
FROM gyc_vehicle_info 
WHERE vendor_id = 432
GROUP BY color 
ORDER BY count DESC;
```

### Engine Size Distribution
```sql
SELECT 
    a.engine_size, 
    COUNT(*) as count 
FROM gyc_vehicle_info v
JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
WHERE v.vendor_id = 432
GROUP BY a.engine_size 
ORDER BY count DESC;
```

### Recent Vehicles (Last 7 days)
```sql
SELECT 
    reg_no, 
    color, 
    created_at 
FROM gyc_vehicle_info 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY created_at DESC;
```

### Images per Vehicle
```sql
SELECT 
    vehicle_info_id, 
    COUNT(*) as image_count 
FROM gyc_product_images 
GROUP BY vehicle_info_id 
ORDER BY image_count DESC;
```

### Vehicles Without Images
```sql
SELECT 
    v.reg_no, 
    v.color, 
    COUNT(p.id) as image_count 
FROM gyc_vehicle_info v
LEFT JOIN gyc_product_images p ON v.id = p.vehicle_info_id
WHERE v.vendor_id = 432
GROUP BY v.id
HAVING image_count = 0;
```

---

## 📝 Checklist: First-Time cPanel Setup

- [ ] Project uploaded to `~/public_html/carvendors-scraper/`
- [ ] Directories have 755 permissions (logs, data, images)
- [ ] Database created: `yourprefix_carsafari`
- [ ] Database user created: `yourprefix_caruser`
- [ ] User has ALL privileges on database
- [ ] `config.php` updated with database credentials
- [ ] Migration 1 SQL applied (unique index on reg_no)
- [ ] Test scrape runs successfully (`--no-details` flag)
- [ ] Database shows vehicles after scrape
- [ ] Cron job added to cPanel (or SSH crontab)
- [ ] Cron log exists and shows successful runs
- [ ] Monitor first 3-5 cron executions (check logs)

---

## 🔗 Useful cPanel Paths

```
~/public_html/carvendors-scraper/     # Project root
~/public_html/carvendors-scraper/config.php              # Configuration
~/public_html/carvendors-scraper/scrape-carsafari.php    # Main script
~/public_html/carvendors-scraper/logs/                   # Log files
~/public_html/carvendors-scraper/data/vehicles.json      # JSON export
~/public_html/carvendors-scraper/images/                 # Downloaded images
~/.my.cnf                                                # MySQL credentials
/home/username/access-logs/                              # HTTP access logs
/home/username/error-logs/                               # Error logs
```

---

## 📞 Getting Help

1. **Check logs first**:
   ```bash
   tail -100 logs/scraper_*.log
   tail -50 logs/cron.log
   ```

2. **Test database connection**:
   ```bash
   /usr/bin/php -r "
   \$pdo = new PDO('mysql:host=localhost;dbname=db', 'user', 'pass');
   echo 'Connected!\n';
   "
   ```

3. **Verify cron syntax**:
   ```bash
   crontab -l
   ```

4. **Check PHP version**:
   ```bash
   /usr/bin/php -v
   ```

5. **Check MySQL/MariaDB version**:
   ```bash
   mysql -V
   ```

---

**Last Updated**: December 5, 2025  
**For**: cPanel/Linux Servers  
**Version**: 2.0
