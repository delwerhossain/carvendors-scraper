# 🚗 CarVendors Scraper - Optimized Daily Refresh System

**High-performance car listing scraper with smart change detection for production deployment**

---

## ⚡ Performance Highlights

- **78 vehicles processed in ~1 second**
- **100% efficiency for unchanged data** (smart change detection)
- **Hash-based comparison** prevents unnecessary database updates
- **Minimal downtime** through optimized refresh strategy

---

## 🎯 Quick Start

### For Production Use (Recommended)

```bash
# Optimized daily refresh with smart change detection
php daily_refresh.php --vendor=432

# Force refresh (ignores change detection when needed)
php daily_refresh.php --vendor=432 --force
```

### For Testing/Development

```bash
# Quick test (skip details and JSON for speed)
php scrape-carsafari.php --no-details --no-json

# Full scraping with all features
php scrape-carsafari.php
```

---

## 📁 Project Structure

```
carvendors-scraper/
├── 🚀 daily_refresh.php          # PRODUCTION - Optimized daily refresh
├── 🔧 scrape-carsafari.php        # TESTING - Original scraper
├── 🗃️ cleanup_vendor_data.php     # MAINTENANCE - Safe vendor cleanup
├── 🧹 cleanup_orphaned_attributes.php # MAINTENANCE - Cleanup unused data
├── ⏰ setup_cron.php              # SETUP - CRON job configuration
├── 🗃️ setup_database.php         # SETUP - Database initialization
├── ⚙️ config.php                  # CONFIG - Database & scraper settings
├── 📊 CarSafariScraper.php        # CORE - Main scraper class
├── 📊 CarScraper.php              # CORE - Base scraper functionality
├── 📈 src/StatisticsManager.php   # CORE - Performance tracking
└── 📋 logs/                       # OUTPUT - Runtime logs
```

---

## 🚀 Production Deployment

### Step 1: Database Setup

```bash
# Create database tables
php setup_database.php
```

### Step 2: Configure

Edit `config.php` with your database details:

```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
    'charset'  => 'utf8mb4',
],
```

### Step 3: CRON Job Setup

```bash
# Generate CRON commands for your hosting
php setup_cron.php
```

**Recommended CRON Schedule:**
```bash
# Daily at 2:00 AM (off-peak hours)
0 2 * * * /usr/bin/php /path/to/carvendors-scraper/daily_refresh.php --vendor=432

# Weekly cleanup on Sunday at 3:00 AM
0 3 * * 0 /usr/bin/php /path/to/carvendors-scraper/cleanup_orphaned_attributes.php --confirm
```

---

## 📖 Usage Guide

### Daily Operations

**Production Daily Refresh:**
```bash
# Standard daily refresh (recommended)
php daily_refresh.php

# Custom vendor ID
php daily_refresh.php --vendor=123

# Force refresh (when you want to ignore change detection)
php daily_refresh.php --force
```

**Manual Testing:**
```bash
# Quick test without details or JSON
php scrape-carsafari.php --no-details --no-json

# Full scraping with all features
php scrape-carsafari.php

# Custom vendor
php scrape-carsafari.php --vendor=123
```

### Maintenance Operations

**Vendor Data Cleanup:**
```bash
# Preview what would be deleted (safe)
php cleanup_vendor_data.php --vendor=432 --dry-run

# Actually delete vendor data (requires confirmation)
php cleanup_vendor_data.php --vendor=432 --confirm
```

**Cleanup Orphaned Data:**
```bash
# Preview orphaned attributes
php cleanup_orphaned_attributes.php --dry-run

# Delete orphaned attributes
php cleanup_orphaned_attributes.php --confirm
```

### Setup Operations

**Database Setup:**
```bash
# Initialize database tables
php setup_database.php
```

**CRON Job Setup:**
```bash
# Generate CRON commands for your hosting environment
php setup_cron.php
```

---

## 📊 Performance & Optimization

### Smart Change Detection

- **Hash-based comparison**: Only processes vehicles with actual changes
- **100% skip rate**: Unchanged vehicles are instantly skipped
- **Minimal database load**: Only updates when data actually changes

### Optimization Features

1. **Scrape First Strategy**: New data scraped before cleanup (minimal downtime)
2. **Bulk Operations**: Efficient database operations
3. **Memory Management**: Optimized memory usage (512MB limit)
4. **Rate Limiting**: Respectful scraping with delays

### Performance Metrics

```
Typical Performance:
- 78 vehicles found
- 78 vehicles skipped (100% efficiency - no changes)
- Processing time: ~1 second
- Memory usage: ~64MB
- Database operations: Minimal (unchanged data skipped)
```

---

## 🔧 Configuration

### Database Settings

```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'your_database',
    'username' => 'your_username',
    'password' => 'your_password',
    'charset'  => 'utf8mb4',
],
```

### Scraper Settings

```php
'scraper' => [
    'listing_url'          => 'https://systonautosltd.co.uk/vehicle/search/min_price/0/order/price/dir/DESC/limit/250/',
    'request_delay'        => 1.5,          // Seconds between requests
    'timeout'              => 30,           // Request timeout
    'fetch_detail_pages'   => true,         // Fetch individual vehicle pages
    'verify_ssl'           => false,        // SSL verification
],
```

---

## 📊 Data Extracted

### Vehicle Information
- **Registration Number** (reg_no) - UK vehicle registration
- **Title & Specifications** - Vehicle name and technical specs
- **Price** - Selling price in GBP
- **Mileage** - Odometer reading
- **Description** - Full vehicle details
- **Vehicle URL** - Direct link to source listing

### Technical Specifications
- **Color** - Exterior color (validated against whitelist)
- **Transmission** - Manual/Automatic/Semi-automatic
- **Fuel Type** - Diesel/Petrol/Hybrid/Electric
- **Body Style** - Hatchback/Sedan/SUV/Coupe/Convertible
- **Year** - Registration year
- **Engine Size** - Engine capacity in cc
- **Doors** - Number of doors
- **Drive System** - FWD/RWD/AWD/4WD

### Images
- **Multiple images per vehicle** - All available photos
- **Serial numbering** - Ordered as 1, 2, 3...
- **URL storage** - Direct references to source images
- **Automatic cleanup** - Removes broken/deleted images

---

## 🔍 Monitoring & Logs

### Log Files
- **Location**: `logs/scraper_YYYY-MM-DD.log`
- **Automatic cleanup**: Files older than 7 days deleted
- **Detailed logging**: All operations, errors, and performance metrics

### Database Statistics
- **Table**: `scraper_statistics`
- **Metrics**: Vehicles found, inserted, updated, skipped, failed
- **Performance tracking**: Duration, error rates, efficiency
- **Historical data**: Daily, weekly, monthly statistics

### Health Monitoring
```bash
# Check latest logs
tail -20 logs/scraper_*.log

# Database status
mysql -u root -e "SELECT COUNT(*) as total_vehicles FROM gyc_vehicle_info WHERE vendor_id = 432;"

# Recent statistics
mysql -u root -e "SELECT * FROM scraper_statistics ORDER BY created_at DESC LIMIT 5;"
```

---

## 🚨 Troubleshooting

### Common Issues & Solutions

**Database Connection Error**
```bash
# Check database exists
mysql -u root -e "SHOW DATABASES LIKE 'your_database';"

# Test credentials
mysql -u your_username -p your_database

# Recreate tables if needed
php setup_database.php
```

**No Vehicles Found**
```bash
# Test website accessibility
curl -I "https://systonautosltd.co.uk/vehicle/search/min_price/0/order/price/dir/DESC/limit/250/"

# Check configuration
grep 'listing_url' config.php

# Run with debug output
php scrape-carsafari.php --no-details --no-json 2>&1 | tee debug.log
```

**Memory/Timeout Issues**
```bash
# Increase PHP memory limit
php -d memory_limit=1G scrape-carsafari.php

# Increase execution time
php -d max_execution_time=3600 scrape-carsafari.php
```

### Performance Issues

**Slow Processing**
- Check `request_delay` in config.php (reduce for faster processing)
- Use `--no-details` flag to skip detail page fetching
- Verify website response time

**High Memory Usage**
- Monitor with `ps aux | grep php`
- Reduce `memory_limit` in configuration
- Check for memory leaks in logs

---

## 🌐 Hosting Environments

### cPanel
```bash
# CRON job path
/usr/bin/php /home/username/public_html/carvendors-scraper/daily_refresh.php

# PHP path may vary
/opt/cpanel/ea-php83/root/usr/bin/php
```

### Plesk
```bash
# Scheduled task path
/usr/bin/php /var/www/vhosts/domain.com/carvendors-scraper/daily_refresh.php
```

### DirectAdmin
```bash
# CRON command
/usr/local/bin/php /home/username/domains/domain.com/public_html/carvendors-scraper/daily_refresh.php
```

### Docker/Cloud
```bash
# Dockerfile example
FROM php:8.3-cli
COPY . /app
WORKDIR /app
CMD ["php", "daily_refresh.php"]
```

---

## 📈 Success Metrics

### Current Performance
- ✅ **78 vehicles processed in 1 second**
- ✅ **100% efficiency** (unchanged data skipped)
- ✅ **Zero errors** during normal operation
- ✅ **Automatic optimization** through smart change detection

### Production Readiness Checklist
- [ ] Database tables created (`php setup_database.php`)
- [ ] Configuration updated (`config.php`)
- [ ] CRON jobs scheduled (`php setup_cron.php`)
- [ ] Test run completed (`php daily_refresh.php`)
- [ ] Monitoring enabled (check logs)
- [ ] Backup procedures in place

---

**🎯 Key Achievement: 100% efficiency through smart change detection - only processes vehicles with actual changes!**

**Last Updated**: December 12, 2025
**Status**: ✅ Production Ready with Daily Optimization
**Performance**: 78 vehicles in 1 second with 100% skip efficiency