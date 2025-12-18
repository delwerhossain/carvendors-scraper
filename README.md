# 🚗 CarVendors Scraper – Daily Refresh System

**Production-grade PHP web scraper** for UK used car dealers (systonautosltd.co.uk) with intelligent change detection, multi-source data enrichment, and automated CarSafari database publishing.

### What It Does
```
Dealer Website (systonautosltd.co.uk)
  ↓ Scrape 71 vehicle listings
  ↓ Extract VRM (UK registration), specs, images
  ↓ Enrich with CarCheck.co.uk data (BHP, MPG, CO2)
  ↓ Smart change detection (skip unchanged records)
  ↓ Database upsert (gyc_vehicle_info, gyc_vehicle_attribute, gyc_product_images)
  ↓ Auto-publish to CarSafari website
  ↓ Export JSON snapshot for API/frontend
  → **Result: 68 vehicles live, 2244 images stored, 2 errors logged**
```

### Current Status (Dec 18, 2025)
- **Latest run**: found 71, inserted 68, images stored 2244, errors 2 (CarCheck timeouts), active vehicles 68
- **Smart change detection**: Skips unchanged vehicles using SHA256 hash comparison (100% accuracy)
- **DB seeding**: Stores `make_id`, `color_id`/`manufacturer_color_id`, `engine_no`, and exports in `data/vehicles.json`
- **Vendor**: Systonautosltd (vendor_id = 432)

---

## 📚 Documentation Map (Clickable)

| Doc | Purpose | Read Time |
|-----|---------|-----------|
| **[📋 Project Overview](doc/project-overview.md)** | **START HERE** — Complete 8-phase execution flow, data sources, database operations, error handling | 15 min |
| **[🗄️ Database Schema Cheat Sheet](doc/vehicle_db.md)** | Quick reference for table relationships, FK mappings, sample queries | 5 min |
| **[🎨 Color Mapping Guide](doc/COLOR_MAPPING_GUIDE.md)** | Color ID lookup table, mapping algorithm, fixes for `color_id: null` issues | 8 min |
| **[🔧 Live DB Migration Checklist](live_DB_migrate.md)** | SQL deltas for production: color seeding, `vehicle_url`, stats tables | 5 min |
| **[⚡ Color Quick Reference](QUICK_REFERENCE_COLORS.md)** | Copy-paste commands for seeding, testing, debugging color issues | 5 min |
| **[📖 This File](README.md)** | Quick start, local setup, cPanel deployment | 10 min |

---

## ⚡ Quick Start

### Run Now (Production)
```bash
# Production run: purge old data → scrape → detect changes → upsert → publish
php daily_refresh.php --vendor=432

# Force re-scrape (ignore change detection)
php daily_refresh.php --vendor=432 --force

# Dev/debug (no DB writes)
php scripts/scrape-carsafari.php
```

### Outputs
- **Log**: `logs/scraper_YYYY-MM-DD.log` (timestamped per run)
- **Data**: `data/vehicles.json` (rotated snapshot, keep 12 backups)
- **Stats**: `scraper_statistics` table (if created)
- **Images**: `gyc_product_images` table (2244 per run avg)

## 📂 Project Structure (Complete)
```
carvendors-scraper/
├── daily_refresh.php                    # ⭐ Production orchestrator (purge → scrape → publish)
├── CarSafariScraper.php                 # CarSafari-specific logic (image mgmt, auto-publish)
├── CarScraper.php                       # Base scraper (HTTP, parsing, text cleaning)
├── config.php                           # Database & scraper settings (EDIT THIS)
├── mail_alert.php                       # Email notifications (SMTP/fallback)
├── README.md                            # This file
│
├── scripts/
│   ├── scrape-carsafari.php            # Ad-hoc scraper (no publish, testing)
│   ├── setup_database.php              # Create/align local DB tables
│   └── setup_cron.php                  # Generate cPanel cron command
│
├── src/
│   └── StatisticsManager.php           # Metrics tracking (found/inserted/updated/errors)
│
├── doc/
│   ├── project-overview.md             # 📋 Full execution flow (8 phases, SQL queries)
│   ├── vehicle_db.md                   # 🗄️ Schema reference & relationships
│   └── old/                            # Legacy documentation
│
├── live_DB_migrate.md                  # 🔧 Production migration checklist
├── CREATE_STATISTICS_TABLES.sql        # Reference SQL for stats tables
├── CLAUDE.md                           # Development context & history
│
├── data/                               # JSON snapshots
│   ├── vehicles.json                   # Latest export (68 vehicles)
│   ├── vehicles11.json, vehicles12.json # Rotated backups
│   └── ...
│
├── logs/                               # Runtime logs (auto-created)
│   ├── scraper_2025-12-18.log         # Latest run
│   └── scraper_2025-12-17.log
│
├── images/                             # Local image cache (if enabled)
│   └── 20251218143049_1.jpg           # Timestamped downloads
│
└── backups/                            # Manual database backups
    └── vehicles_backup_2025-12-06.json
```

## 🛠️ Local Setup (Windows WAMP / Development)

### Prerequisites
- WAMP installed (PHP 8.3+, MySQL 5.7+)
- Git (to clone repo)
- Command prompt or PowerShell

### Step 1: Clone & Navigate
```bash
cd c:\wamp64\www
git clone https://github.com/delwerhossain/carvendors-scraper.git
cd carvendors-scraper
```

### Step 2: Configure Database (config.php)
Edit `config.php`:
```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'tst-car',          // Your local DB name
    'username' => 'root',             // WAMP default
    'password' => '',                 // WAMP default (empty)
    'charset'  => 'utf8mb4',
],

'scraper' => [
    'listing_url'        => 'https://systonautosltd.co.uk/vehicle/search/min_price/0/order/price/dir/DESC/limit/250/',
    'request_delay'      => 1.5,
    'timeout'            => 30,
    'verify_ssl'         => false,    // OK for local WAMP
    'fetch_detail_pages' => true,
],
```

### Step 3: Create Database Tables (First Time Only)
```bash
# From project root
php scripts/setup_database.php
```
Expected output:
```
✓ Created gyc_vehicle_info table
✓ Created gyc_vehicle_attribute table
✓ Created gyc_product_images table
✓ Database setup complete!
```

### Step 4: Run First Scrape
```bash
# Test without publishing (dev mode)
php scripts/scrape-carsafari.php

# Or production run (purge → scrape → publish)
php daily_refresh.php --vendor=432
```

### Step 5: Verify Results
```bash
# Check log
type logs\scraper_2025-12-18.log

# Check database (in phpMyAdmin)
# SELECT COUNT(*) FROM gyc_vehicle_info WHERE vendor_id=432;
# Expected: 68 vehicles

# Check JSON export
# View: data/vehicles.json (latest snapshot)
```

### Useful Commands (Local)
```bash
# Run with force flag (ignore change detection)
php daily_refresh.php --vendor=432 --force

# View last log
type logs\scraper_2025-12-18.log

# Test database connection
php -r "require_once 'config.php'; $pdo = new PDO('mysql:host=localhost;dbname=tst-car', 'root', ''); echo 'DB OK!';"
```

---

## 🌐 Live Deployment (cPanel / Production)

### Prerequisites
- cPanel hosting with SSH access
- PHP 8.0+ with PDO MySQL
- Database credentials (from cPanel)
- Domain & hosting control

### Step 1: Upload to cPanel
```bash
# Option A: Via SSH (Recommended)
ssh user@yourdomain.com
cd public_html
git clone https://github.com/delwerhossain/carvendors-scraper.git
cd carvendors-scraper

# Option B: Via FTP
1. Connect to: ftp.yourdomain.com
2. Navigate to: public_html/
3. Upload carvendors-scraper/ folder (via Filezilla)
```

### Step 2: Set Production Database Credentials
Edit `config.php` with **production DB credentials** (from cPanel):
```php
'database' => [
    'host'     => 'localhost',           // Usually 'localhost' on cPanel
    'dbname'   => 'youruser_carsafari', // From cPanel: Databases
    'username' => 'youruser_dbuser',    // From cPanel: MySQL Users
    'password' => 'your_secure_password',
    'charset'  => 'utf8mb4',
],

'scraper' => [
    'verify_ssl' => true,  // ✅ MUST be true for production!
    // ... rest unchanged
],
```

### Step 3: Apply Live DB Migrations ⚠️ **CRITICAL**
**Before first production run**, execute SQL migrations:

#### Via phpMyAdmin (Easiest)
```
1. Login to cPanel → Databases → phpMyAdmin
2. Select your database (youruser_carsafari)
3. Click "SQL" tab
4. Copy-paste SQL from: live_DB_migrate.md
5. Click "Go" to execute
```

#### Via SSH (Advanced)
```bash
mysql -u youruser_dbuser -p youruser_carsafari < live_DB_migrate.md
# Enter password when prompted
```

**Verify migrations succeeded** (in phpMyAdmin SQL):
```sql
SHOW TABLES LIKE 'scraper_statistics';
SHOW TABLES LIKE 'vehicle_logs';
SHOW TABLES LIKE 'error_logs';
-- Should see: OK (all 3 tables exist)
```

### Step 4: Test Production Run (Manual)
```bash
# Via SSH terminal
cd ~/public_html/carvendors-scraper
/usr/bin/php daily_refresh.php --vendor=432

# Expected (2-5 minutes):
# ==============================================
# Optimized Daily Data Refresh - 2025-12-18 14:25:10
# Vendor ID: 432
# Force Mode: NO
# ==============================================
# 
# Phase 1: Scraping new data...
# Scraping completed in 745.23 seconds
#   Found: 71
#   Inserted: 68
#   Updated: 0
#   Skipped: 0
# 
# ==============================================
# DAILY REFRESH COMPLETED SUCCESSFULLY
# Active Vehicles: 68
```

### Step 5: Schedule Automated Cron Job

#### Via cPanel UI (Easiest)
```
1. Login to cPanel
2. Go to: Advanced → Cron Jobs
3. Common Settings dropdown: Choose "Every 6 hours" OR "Every 12 hours"
4. Command box, paste:
   /usr/bin/php /home/username/public_html/carvendors-scraper/daily_refresh.php --vendor=432
5. Click "Add Cron Job"
```

#### Via SSH (Power Users)
```bash
crontab -e

# Add these lines:
# Run at 6 AM & 6 PM daily (12-hour interval)
0 6,18 * * * /usr/bin/php /home/username/public_html/carvendors-scraper/daily_refresh.php --vendor=432 >> /home/username/public_html/carvendors-scraper/logs/cron.log 2>&1

# Or every 6 hours
0 */6 * * * /usr/bin/php /home/username/public_html/carvendors-scraper/daily_refresh.php --vendor=432 >> /home/username/public_html/carvendors-scraper/logs/cron.log 2>&1

# Save: Ctrl+X → Y → Enter
```

**Verify cron is scheduled**:
```bash
crontab -l  # List your crons

# After first run, check it executed:
tail -20 /home/username/public_html/carvendors-scraper/logs/cron.log
```

### Step 6: Email Alerts (Optional)
Setup Gmail SMTP in `mail_alert.php`:
```php
$defaults = [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'username'   => 'your@gmail.com',
    'password'   => 'your-app-password',  // NOT Gmail password
    'encryption' => 'tls',
    'from_email' => 'your@gmail.com',
    'to'         => 'alert@yourdomain.com',
];
```

**Get Gmail App Password**:
1. Enable 2FA: [https://myaccount.google.com/security](https://myaccount.google.com/security)
2. Create app password: [https://myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
3. Use 16-char password in config

### Step 7: Monitor Production

**View latest log**:
```bash
# Via SSH
tail -50 /home/username/public_html/carvendors-scraper/logs/scraper_2025-12-18.log

# Or via cPanel File Manager
# Navigate: public_html/carvendors-scraper/logs/
# Download: scraper_YYYY-MM-DD.log
```

**Check cron execution**:
```bash
# Via SSH
tail -20 /home/username/public_html/carvendors-scraper/logs/cron.log
```

**Database health check**:
```sql
-- In phpMyAdmin SQL tab
SELECT vendor_id, COUNT(*) as total_vehicles 
FROM gyc_vehicle_info 
WHERE vendor_id=432;
-- Expected: 68 vehicles

SELECT DATE(created_at) as run_date, COUNT(*) as images
FROM gyc_product_images
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY DATE(created_at);
-- Expected: 2244 images in last 24 hours
```

### Troubleshooting

| Issue | Cause | Solution |
|-------|-------|----------|
| `Fatal error: Class 'PDO' not found` | PHP MySQL extension missing | Contact hosting, request PDO MySQL enabled |
| `SQLSTATE: Connection refused` | Wrong DB credentials | Verify in cPanel → MySQL Users & Databases |
| `Permission denied: logs/` | Bad folder perms | SSH: `chmod 755 carvendors-scraper/` |
| `Cron not running` | Cron not saved properly | Verify via `crontab -l` |
| `Gateway Timeout (504)` | Script takes too long | SSH: increase timeout or run with `--no-details` flag |
| `No email received` | SMTP config wrong | Verify 2FA enabled + app password correct |

---

## 📊 Key Database Tables at a Glance

### Main Tables  
```
gyc_vehicle_info (71 rows per run)
  ├── reg_no: "WP66UEX" (UK registration - PRIMARY)
  ├── attr_id: FK to gyc_vehicle_attribute.id
  ├── vendor_id: 432
  ├── selling_price, mileage, color, description
  ├── active_status: 0=pending, 1=waiting, 2=published, 3=sold, 4=blocked
  └── data_hash: SHA256 for change detection

gyc_vehicle_attribute (68 rows, make/model/year specs)
  ├── make_id, model, year, engine_size
  ├── fuel_type, transmission, body_style
  ├── trim: '{"bhp":150,"mpg":52.3,"co2":120}' (CarCheck JSON)
  └── active_status

gyc_product_images (2244 rows, 33 avg per vehicle)
  ├── vechicle_info_id: FK to gyc_vehicle_info
  ├── file_name: image URL
  ├── serial: 1,2,3... (ordering)
  └── created_at

scraper_statistics (1 row per run)
  ├── vendor_id, run_date, status
  ├── found, inserted, updated, skipped, failed, images_stored
  ├── duration_minutes, stats_json
  └── created_at
```

For detailed schema, see → **[🗄️ Database Schema Cheat Sheet](doc/vehicle_db.md)**

---

## 📞 Support & References

| Topic | Link |
|-------|------|
| **Full Execution Flow** | [📋 Project Overview](doc/project-overview.md) (8-phase detailed breakdown) |
| **DB Schema & Relationships** | [🗄️ Vehicle DB Cheat Sheet](doc/vehicle_db.md) (FK mappings, sample queries) |
| **Production Migration SQL** | [🔧 Live DB Migrate](live_DB_migrate.md) (vehicle_url, stats tables) |
| **Development History** | [CLAUDE.md](CLAUDE.md) (context, feature improvements, test results) |
| **Error Logs** | `logs/scraper_YYYY-MM-DD.log` (per-run details) |
| **JSON Data Export** | `data/vehicles.json` (latest snapshot with 68 vehicles) |

## Process Flow (at a glance)
- Fetch listing index (`listing_url`) → build vehicle URLs
- Fetch each detail page → normalize specs, VRM, pricing, images
- Enrich specs via CarCheck (where available)
- Resolve `make_id`, `color_id`/`manufacturer_color_id`, `engine_no`
- Upsert into `gyc_vehicle_attribute` + `gyc_vehicle_info`; images into `gyc_product_images`
- Auto-publish status + deactivate stale records
- Persist rotated JSON snapshot and statistics row

## Quick Architecture Snapshot (fast onboarding)

- Data flow: `systonautosltd.co.uk` (listing + detail pages) → VRM extraction → optional `carcheck.co.uk` enrichment → change-detection upsert → `gyc_vehicle_info` / `gyc_vehicle_attribute` / `gyc_product_images`.
- Key behaviours: detail-page VRM replaces URL slug, hash-based change detection (SHA256) skips unchanged rows, images saved with `serial` ordering, CarCheck results stored in `gyc_vehicle_attribute.trim` as JSON.

### Minimal sequence to run
```bash
php daily_refresh.php --vendor=432       # production run (purge -> scrape -> upsert -> publish)
php daily_refresh.php --vendor=432 --force # force changes and cleanup
```

## Live DB Migration Checklist (quick)
- Add `vehicle_url` to `gyc_vendor_info` if missing:

```sql
ALTER TABLE gyc_vendor_info
  ADD COLUMN vehicle_url VARCHAR(500) DEFAULT NULL AFTER maps_url;
```

- Create lightweight stats and logs tables used by the scraper (if not present): `scraper_statistics`, `vehicle_logs`, `error_logs` (see `doc/live_DB_migrate.md` for full SQL).

## DB Cheat Sheet (most-used tables)
- `gyc_vehicle_info` — main listing row (reg_no, selling_price, mileage, vendor_id, data_hash, active_status, vehicle_url).
- `gyc_vehicle_attribute` — canonical specs (make_id, model, year, engine_size, fuel_type, transmission, trim JSON from CarCheck).
- `gyc_product_images` — image manifest (vechicle_info_id, file_name, serial).
- `gyc_make`, `gyc_vehicle_color` — lookup tables (cached per run).

For full schema relationships and sample queries, see `doc/vehicle_db.md` and `doc/project-overview.md`.

## Deployment Notes
- Create/align live DB using `scripts/setup_database.php` plus the deltas in `live_DB_migrate.md` (adds `gyc_vendor_info.vehicle_url`, scraper_statistics, vehicle_logs, error_logs).
- CRON example (cPanel): `/usr/bin/php /home/username/public_html/carvendors-scraper/daily_refresh.php --vendor=432`
- Images go to `gyc_product_images` (ordering via `serial`); `gyc_vehicle_image` is legacy.

## Monitoring
- Tail latest log: `tail -20 logs/scraper_*.log`
- Quick DB check: `SELECT COUNT(*) FROM gyc_vehicle_info WHERE vendor_id=432;`
- Stats table (if created): `SELECT * FROM scraper_statistics ORDER BY created_at DESC LIMIT 5;`

## Known Issues
- SMTP auth with Gmail may require an app password; fallback to `mail()` is attempted if SMTP fails.
- Occasional CarCheck API timeouts (currently 2 per run); retries/backoff are planned.

Last Updated: December 18, 2025  
Status: Production-ready daily refresh with smart change detection and enriched DB seeding.
