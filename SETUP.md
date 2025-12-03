# 🚗 Car Scraper - Complete Setup Guide

**Auto-publishing vehicle listings to your CarSafari database**

---

## ⚡ Quick Start (5 Minutes)

### Local Testing (Windows WAMP)
```bash
cd c:\wamp64\www\carvendors-scraper
c:\wamp64\bin\php\php8.3.14\php.exe scrape-carsafari.php
```

### Upload to cPanel
1. Upload files to `/home/username/carvendors-scraper/`
2. Edit `config.php` with your database credentials
3. Test: `/usr/bin/php scrape-carsafari.php`

### Set Cron Job (cPanel)
```bash
# cPanel → Cron Jobs → Add New Cron Job
0 6,18 * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

Done! Vehicles auto-publish at 6 AM & 6 PM daily.

---

## 📋 What You Have

| File | Purpose |
|------|---------|
| **CarScraper.php** | Base scraping class |
| **CarSafariScraper.php** | CarSafari database integration |
| **config.php** | Your database credentials |
| **scrape.php** | Generic scraper (JSON export) |
| **scrape-carsafari.php** | CarSafari auto-publisher |
| **SETUP.md** | This file |
| **QUICK_REFERENCE.md** | Copy-paste commands |

---

## 🔧 Configuration (2 Minutes)

Edit **config.php**:

```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'carsafari',          // ← Your database name
    'username' => 'db_user',            // ← Your database user
    'password' => 'db_password',        // ← Your database password
    'charset'  => 'utf8mb4',
],
```

Test connection:
```bash
php -r "
require 'config.php';
try {
    new PDO(
        'mysql:host=' . \$config['database']['host'] . ';dbname=' . \$config['database']['dbname'],
        \$config['database']['username'],
        \$config['database']['password']
    );
    echo 'Database OK!';
} catch (Exception \$e) {
    echo 'Error: ' . \$e->getMessage();
}"
```

---

## 📊 How It Works

```
Dealer Website (164 vehicles)
         ↓
  CarSafariScraper.php
         ↓
   ├─ gyc_vehicle_info (main data)
   ├─ gyc_vehicle_attribute (specs)
   ├─ gyc_product_images (images downloaded)
   └─ scrape_logs (tracking)
         ↓
  CarSafari Website LIVE ✅
```

### Data Mapping

| Source → Target | Example |
|-----------------|---------|
| title → attention_grabber | "Volvo V40..." |
| price → selling_price | 8990.00 |
| mileage → mileage | 75000 |
| colour → color | "Grey" |
| transmission → transmission | "Manual" |
| fuel_type → fuel_type | "Diesel" |
| body_style → body_style | "Hatchback" |
| description_full → description | Full listing text |
| image_url → file_name | Downloaded to images/ |
| external_id → reg_no | Unique identifier |

### Auto-Set Fields
```
active_status = 1          (Waiting for Publish)
publish_date = TODAY       (Publish date set)
vendor_id = 1              (Default vendor)
created_at = NOW           (Creation timestamp)
```

---

## 🎯 Two Scraper Options

### Option 1: Generic Scraper (Analysis)
```bash
php scrape.php
# Output: data/vehicles.json
# Use: Excel, Python, analysis
```

### Option 2: CarSafari Scraper ⭐ (Live Website)
```bash
php scrape-carsafari.php
# Output: gyc_vehicle_info, gyc_vehicle_attribute, gyc_product_images
# Use: Auto-publish to CarSafari website
```

---

## 🌐 cPanel Cron Setup

### Step 1: Find PHP Path
cPanel → Software → Select PHP Version → Copy Full Path

Usually: `/usr/bin/php` or `/usr/local/bin/php`

### Step 2: Create Cron Job
cPanel → Advanced → Cron Jobs

**Command:**
```bash
0 6,18 * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

**Options:**
- Daily (1x): `0 6 * * *`
- Twice daily ⭐: `0 6,18 * * *`
- Every 12 hours: `0 */12 * * *`
- Every 6 hours: `0 */6 * * *`

### Step 3: Monitor
```bash
# View logs
tail -f /home/username/carvendors-scraper/logs/cron.log

# Check execution
grep "COMPLETED" /home/username/carvendors-scraper/logs/scraper_*.log
```

---

## 🛠️ Command Options

```bash
# Normal run (all features enabled)
php scrape-carsafari.php

# Skip detail pages (faster)
php scrape-carsafari.php --no-details

# Skip JSON export
php scrape-carsafari.php --no-json

# Use vendor ID 2
php scrape-carsafari.php --vendor=2

# Combined
php scrape-carsafari.php --no-details --vendor=2

# Help
php scrape-carsafari.php --help
```

---

## 📊 Expected Output

### First Run
```
Found: 164
Inserted: 82
Updated: 0
Published: 82
```

### Subsequent Runs
```
Found: 164
Inserted: 0
Updated: 82 (prices/mileage refreshed)
Published: 82
```

---

## 📁 Generated Files

After first run:
```
carvendors-scraper/
├── data/
│   └── vehicles.json            (All vehicles in JSON)
├── images/
│   └── 20251203_*.jpg          (Downloaded images)
└── logs/
    └── scraper_2025-12-03.log  (Execution log)
```

---

## 🐛 Troubleshooting

### "Database connection failed"
```bash
# Check credentials in config.php
php -r "require 'config.php'; print_r(\$config['database']);"
```

### "0 vehicles found"
- Check URL in config.php
- Test in browser: https://systonautosltd.co.uk/vehicle/search/...
- Website structure may have changed

### "Cron not running"
```bash
# Check PHP path
which php

# Find correct path
/usr/local/bin/php scrape-carsafari.php

# Check permissions
chmod +x scrape-carsafari.php
```

### "Images not downloading"
- Check `image_url` in database
- Verify images/ directory is writable

### "Timeout error"
```bash
# Skip detail pages
php scrape-carsafari.php --no-details
```

---

## 🔍 Database Queries

```sql
-- Count vehicles
SELECT COUNT(*) FROM gyc_vehicle_info;

-- Show latest 10
SELECT id, attention_grabber, selling_price, mileage, created_at
FROM gyc_vehicle_info
ORDER BY created_at DESC LIMIT 10;

-- Count today's additions
SELECT COUNT(*) FROM gyc_vehicle_info
WHERE DATE(created_at) = CURDATE();

-- View scrape history
SELECT * FROM scrape_logs
ORDER BY started_at DESC LIMIT 5;

-- Find NULL descriptions
SELECT COUNT(*) FROM gyc_vehicle_info
WHERE description IS NULL OR description = '';

-- Check vehicle images
SELECT COUNT(*) FROM gyc_product_images;
```

---

## 🔐 Security

### Restrict File Access
```bash
chmod 600 config.php          # Only you can read
chmod 750 .                   # Directory access
chmod 755 data/ logs/
```

### Create Limited DB User
```sql
CREATE USER 'scraper'@'localhost' IDENTIFIED BY 'secure_password';
GRANT SELECT, INSERT, UPDATE ON carsafari.* TO 'scraper'@'localhost';
FLUSH PRIVILEGES;
```

### Backup Before Going Live
```bash
mysqldump -u user -p carsafari > backup_$(date +%Y%m%d).sql
```

---

## ✅ Implementation Checklist

### Local Testing
- [ ] Extract all files
- [ ] Update config.php (local database)
- [ ] Test: `php scrape-carsafari.php`
- [ ] Verify data/vehicles.json created
- [ ] Check vehicle count (should be 82)
- [ ] Review logs/scraper_*.log

### Production Setup
- [ ] Upload files to cPanel
- [ ] Update config.php (production database)
- [ ] Test connection (see above)
- [ ] Create cron job
- [ ] Monitor first execution
- [ ] Verify vehicles in CarSafari admin
- [ ] Check images are downloading

### Go Live
- [ ] All tests passed ✅
- [ ] Cron running correctly ✅
- [ ] Vehicles published ✅
- [ ] Images visible ✅

---

## 📞 Monitoring

### Daily
```bash
# Check log last line
tail -1 logs/scraper_*.log

# Count vehicles
mysql -u user -p -e "SELECT COUNT(*) FROM gyc_vehicle_info" carsafari
```

### Weekly
```bash
# View all runs
grep "COMPLETED\|FAILED" logs/scraper_*.log

# Archive old logs
tar -czf logs_backup_$(date +%Y%m).tar.gz logs/*.log
rm logs/scraper_*.log
```

---

## 🆘 Emergency

### Stop Scraper
```bash
# Disable in cPanel or edit crontab
crontab -e
# Comment out the line
```

### Restore Backup
```bash
mysql -u user -p carsafari < backup_20251203.sql
```

### Force Immediate Run
```bash
/usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php
```

---

## 📈 Performance

| Metric | Value |
|--------|-------|
| Vehicles per run | 164 |
| Processing time | ~8 minutes |
| Memory usage | ~256MB |
| Images downloaded | 1-3 per vehicle |
| Database updates | Atomic/safe |
| Duplicate prevention | Yes (by reg_no) |

---

## 🎯 Next Steps

1. **Update config.php** with your database
2. **Test locally**: `php scrape-carsafari.php`
3. **Upload to cPanel**
4. **Set cron job**: `0 6,18 * * *`
5. **Monitor first 24h**
6. **Go live!**

---

## 📖 For More Info

See **QUICK_REFERENCE.md** for:
- Copy-paste cron commands
- Database queries
- Log analysis commands
- Troubleshooting procedures

---

**Version**: 1.0 | **Status**: Production Ready ✅
**Support**: Check logs and queries above
