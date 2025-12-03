# ⚡ Quick Reference Card

**Copy & Paste Commands for Common Tasks**

---

## 🚀 LOCAL TESTING (Windows WAMP)

```bash
# Navigate to project
cd c:\wamp64\www\carvendors-scraper

# Test generic scraper
c:\wamp64\bin\php\php8.3.14\php.exe scrape.php

# Test CarSafari scraper
c:\wamp64\bin\php\php8.3.14\php.exe scrape-carsafari.php

# Test with options
c:\wamp64\bin\php\php8.3.14\php.exe scrape-carsafari.php --no-details
c:\wamp64\bin\php\php8.3.14\php.exe scrape-carsafari.php --vendor=2

# View latest log
type logs\scraper_2025-12-03.log | tail -50
```

---

## 🌐 CPANEL / LINUX (Production)

```bash
# SSH into server
ssh user@domain.com

# Navigate to project
cd /home/username/carvendors-scraper

# Test scraper
/usr/bin/php scrape-carsafari.php

# Test with options
/usr/bin/php scrape-carsafari.php --no-details

# View logs real-time
tail -f logs/scraper_*.log

# Check cron jobs
crontab -l

# Edit cron jobs
crontab -e

# View recent scrapes
grep "COMPLETED\|FAILED" logs/scraper_*.log

# Count total vehicles processed
wc -l logs/scraper_*.log
```

---

## 🔄 CRON JOBS (Copy & Paste)

### Daily at 6 AM
```bash
0 6 * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

### Twice Daily (6 AM & 6 PM) ⭐ RECOMMENDED
```bash
0 6,18 * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

### Every 12 Hours
```bash
0 */12 * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

### Every 6 Hours (4x daily)
```bash
0 */6 * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

### Hourly
```bash
0 * * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

---

## 📝 CONFIGURATION

### Quick DB Test
```php
<?php
require 'config.php';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
    $config['database']['username'],
    $config['database']['password']
);
echo $pdo->query('SELECT COUNT(*) FROM gyc_vehicle_info')->fetchColumn() . ' vehicles in database';
?>
```

### Update DB Credentials
```php
# Edit config.php:
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'carsafari',
    'username' => 'db_user',
    'password' => 'db_password',
],
```

---

## 📊 DATABASE QUERIES

### Count Vehicles
```sql
SELECT COUNT(*) FROM gyc_vehicle_info;
```

### Show Latest 10
```sql
SELECT id, attention_grabber, selling_price, mileage, created_at
FROM gyc_vehicle_info
ORDER BY created_at DESC LIMIT 10;
```

### Count Today's
```sql
SELECT COUNT(*) FROM gyc_vehicle_info
WHERE DATE(created_at) = CURDATE();
```

### Find Issues (NULL descriptions)
```sql
SELECT COUNT(*) FROM gyc_vehicle_info
WHERE description IS NULL OR description = '';
```

### Find Duplicates
```sql
SELECT reg_no, COUNT(*) as cnt
FROM gyc_vehicle_info
GROUP BY reg_no HAVING cnt > 1;
```

### View Scrape History
```sql
SELECT * FROM scrape_logs
ORDER BY started_at DESC LIMIT 10;
```

### Check by Status
```sql
SELECT active_status, COUNT(*) as cnt
FROM gyc_vehicle_info
GROUP BY active_status;
```

---

## 🔍 LOG ANALYSIS

### View last 50 lines
```bash
tail -50 logs/scraper_*.log
```

### Watch live
```bash
tail -f logs/scraper_*.log
```

### Count vehicles processed
```bash
grep "Processing" logs/scraper_*.log | wc -l
```

### Find errors
```bash
grep -i "error\|failed" logs/scraper_*.log
```

### Count successful runs
```bash
grep "COMPLETED SUCCESSFULLY" logs/scraper_*.log | wc -l
```

### Show run times
```bash
grep "CarSafari Scraper\|COMPLETED" logs/scraper_*.log
```

---

## 🖼️ FILE OPERATIONS

### List files
```bash
ls -lh /home/username/carvendors-scraper/

# With sizes
du -sh /home/username/carvendors-scraper/*
```

### File permissions
```bash
chmod +x scrape-carsafari.php
chmod 755 data/
chmod 755 logs/
chmod 755 images/
```

### Check directory tree
```bash
tree -L 2 /home/username/carvendors-scraper/
```

---

## ⚙️ MAINTENANCE

### Backup database
```bash
mysqldump -u user -p carsafari > backup_$(date +%Y%m%d).sql
```

### Archive old logs
```bash
tar -czf logs_backup_$(date +%Y%m%d).tar.gz logs/*.log
rm logs/*.log
```

### Clear old images
```bash
# Keep only last 100 images
ls -t images/*.jpg | tail -n +101 | xargs rm
```

### Check disk usage
```bash
du -sh . && du -sh data/ images/ logs/
```

---

## 🔐 SECURITY

### Set restricted permissions
```bash
chmod 600 config.php          # Only owner can read
chmod 750 .                   # Directory access only for user
chmod 700 logs/
chmod 700 data/
chmod 700 images/
```

### Check file ownership
```bash
ls -l /home/username/carvendors-scraper/
# Should be user:group
```

### Backup config (secret!)
```bash
cp config.php config.php.backup
chmod 600 config.php.backup
```

---

## 📊 MONITORING

### One-line status check
```bash
echo "===== SCRAPER STATUS =====" && \
echo "Last run: $(tail -1 logs/cron.log)" && \
echo "Vehicle count: $(mysql -u user -p dbname -e "SELECT COUNT(*) FROM gyc_vehicle_info")" && \
echo "Images: $(ls images/*.jpg 2>/dev/null | wc -l)" && \
echo "Log size: $(du -h logs/ | tail -1)"
```

### Monitor cron execution
```bash
# Watch cron.log for new entries
watch -n 5 'tail logs/cron.log'
```

### Check last successful run
```bash
grep "COMPLETED SUCCESSFULLY" logs/scraper_*.log | tail -1
```

---

## 🐛 TROUBLESHOOTING

### Test PHP syntax
```bash
php -l CarSafariScraper.php
php -l config.php
php -l scrape-carsafari.php
```

### Find PHP executable
```bash
which php
# Or check cPanel: Software → Select PHP Version
```

### Test DB connection from command line
```bash
php -r "require 'config.php'; new PDO('mysql:host=' . \$c['database']['host'] . ';dbname=' . \$c['database']['dbname'], \$c['database']['username'], \$c['database']['password']); echo 'OK';"
```

### Check cron logs (on some systems)
```bash
grep CRON /var/log/syslog    # Linux
log stream --predicate 'process == "cron"'  # macOS
```

### Test download URL
```bash
curl -I "https://example.com/image.jpg"
# Should return 200 OK
```

---

## 📞 HELP

### See full documentation
```
COMPLETE_SETUP.md              ← START HERE
├── CARSAFARI_INTEGRATION_GUIDE.md
├── CPANEL_SETUP_GUIDE.md
├── SETUP_GUIDE.md
└── OPTIMIZATION_REPORT.md
```

### Quick Help from Script
```bash
php scrape-carsafari.php --help
php scrape.php --help
```

---

## ✅ CHECKLIST

### Before Going Live
- [ ] config.php updated with correct DB
- [ ] Test scraper works locally
- [ ] Cron command tested in Terminal
- [ ] Directory permissions correct
- [ ] Log files are writable
- [ ] Backup created
- [ ] First cron scheduled

### After Going Live
- [ ] Check logs daily
- [ ] Verify vehicles in admin
- [ ] Monitor disk usage
- [ ] Archive old logs weekly
- [ ] Keep config.php.backup

---

## 📱 Emergency Commands

### Stop scraper (disable cron)
```bash
# Edit crontab
crontab -e
# Comment out the cron line with #
```

### Restore from backup
```bash
mysql -u user -p carsafari < backup_20251203.sql
```

### Clear failed jobs
```bash
# Delete corrupted records from last failed run
mysql -u user -p -e "DELETE FROM gyc_vehicle_info WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND updated_at IS NULL;" carsafari
```

### Force immediate run
```bash
/usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php
```

---

**More help?** See full guides in README, SETUP_GUIDE, or CPANEL_SETUP_GUIDE

**Version**: 1.0 | **Last Updated**: 2025-12-03
