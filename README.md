# 🚗 CarVendors Scraper

**Auto-publish vehicle listings from systonautosltd.co.uk to CarSafari database**

---

## 📋 Quick Overview

| Aspect | Details |
|--------|---------|
| **Purpose** | Scrape vehicle listings and auto-publish to CarSafari database |
| **Source** | systonautosltd.co.uk (81 vehicles) |
| **Database** | CarSafari with MySQL |
| **Status** | ✅ Production Ready |

---

## 🚀 Getting Started

### 1. Setup Database

```bash
# Connect to MySQL and create database
mysql -u root -p
CREATE DATABASE tst_car CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
exit

# Create required tables
mysql -u root tst_car < carsafari.sql
mysql -u root tst_car < ALTER_DB_ADD_URL.sql
```

### 2. Configure

Edit `config.php` with your database credentials:

```php
<?php
return [
    'database' => [
        'host'     => 'localhost',
        'dbname'   => 'tst_car',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    'scraper' => [
        'base_url'             => 'https://systonautosltd.co.uk',
        'listing_url'          => 'https://systonautosltd.co.uk/vehicle/search/min_price/0/order/price/dir/DESC/limit/250/',
        'fetch_detail_pages'  => true,
        'request_delay'        => 1.5,
        'timeout'              => 30,
        'verify_ssl'           => false,
    ],
    'output' => [
        'save_json' => true,
        'json_path' => __DIR__ . '/data/vehicles.json',
    ],
];
```

### 3. Run Scraper

```bash
# Local Windows
c:\wamp64\bin\php\php8.3.14\php.exe scrape-carsafari.php

# Production (Linux)
php scrape-carsafari.php
```

**Options:**
- `--no-details` - Skip fetching detail pages (faster)
- `--no-json` - Skip JSON export

---

## 📁 Project Structure

```
carvendors-scraper/
├── scrape-carsafari.php     # MAIN ENTRY POINT
├── CarScraper.php          # Base scraper class
├── CarSafariScraper.php    # CarSafari integration
├── config.php              # Database & settings
├── carsafari.sql           # Database schema
├── ALTER_DB_ADD_URL.sql    # Migration (vehicle_url field)
├── .gitignore              # Git ignore rules
├── README.md               # This file
├── data/                   # Auto-generated JSON output
├── logs/                   # Runtime logs
├── images/                 # Downloaded vehicle images
└── backups/                # Data backups (excluded from git)
```

---

## 📊 What Gets Extracted

### Vehicle Data
- **reg_no** - UK registration number (WP66UEX)
- **title** - Vehicle name and specs
- **price** - Selling price in pounds
- **mileage** - Odometer reading
- **description** - Full vehicle description
- **vehicle_url** - Direct link to listing page

### Specifications
- **colour** - Car color (whitelist validated)
- **transmission** - Manual/Automatic
- **fuel_type** - Diesel/Petrol/Hybrid/Electric
- **body_style** - Hatchback/Sedan/SUV/Coupe
- **year** - Registration year

### Images
- **Multiple images per vehicle** - 60-90+ images
- **Serial numbering** - Images linked as 1, 2, 3...
- **URLs stored** - No disk files, just references

---

## ⚙️ Features

✅ **5 Data Quality Improvements**
1. vendor_id = 432 (default)
2. vehicle_url field in database
3. Multi-image support with serial numbers
4. Colour validation (50+ valid colors)
5. UTF-8 cleanup (removes "â¦" garbage)

✅ **Smart Processing**
- Automatic deduplication
- Change detection (skips unchanged vehicles)
- Rate limiting (1.5 seconds between requests)
- Error recovery and retries

✅ **Production Ready**
- JSON export for APIs
- Database logging
- Detailed logs
- Cron job support

---

## 🔧 Configuration Options

### Environment Settings

```php
// Development
'fetch_detail_pages'  => true,
'request_delay'       => 1.5,
'timeout'            => 30,
'verify_ssl'         => false,

// Production
'fetch_detail_pages'  => true,
'request_delay'       => 2.0,
'timeout'            => 45,
'verify_ssl'         => true,
```

### Database Settings

```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'your_database_name',
    'username' => 'your_username',
    'password' => 'your_password',
    'charset'  => 'utf8mb4',
],
```

---

## 📈 Output

### Database Tables
- `gyc_vehicle_info` - Main vehicle records
- `gyc_vehicle_attribute` - Specifications
- `gyc_product_images` - Image URLs

### JSON Export
```json
{
  "generated_at": "2025-12-04T10:08:00+00:00",
  "source": "systonautosltd",
  "count": 81,
  "vehicles": [
    {
      "id": 1,
      "reg_no": "WP66UEX",
      "title": "Volvo V40 2.0 D4",
      "price": "£8,990",
      "colour": "Silver",
      "transmission": "Manual",
      "mileage": "75000",
      "description": "Full vehicle description...",
      "vehicle_url": "https://...",
      "images": ["image1.jpg", "image2.jpg"]
    }
  ]
}
```

---

## 🔄 Cron Jobs

### Daily Run
```bash
# Runs at 6 AM daily
0 6 * * * /usr/bin/php /path/to/scrape-carsafari.php >> /path/to/logs/cron.log 2>&1
```

### Twice Daily
```bash
# Runs at 6 AM and 6 PM
0 6,18 * * * /usr/bin/php /path/to/scrape-carsafari.php >> /path/to/logs/cron.log 2>&1
```

---

## 🐛 Troubleshooting

### Common Issues

**"Column not found" error**
- Database tables not created properly
- Run: `mysql -u root tst_car < carsafari.sql`

**"No vehicles found"**
- Check website URL in config.php
- Verify internet connectivity
- Check scraper logs

**Images not downloading**
- Ensure image directories are writable
- Check image URLs are accessible

### Log Files

```bash
# View latest logs
tail -50 logs/scraper_*.log

# Check JSON output
cat data/vehicles.json

# Database count
mysql -u root tst_car -e "SELECT COUNT(*) FROM gyc_vehicle_info WHERE vendor_id = 432;"
```

---

## 📞 Support

1. Check `logs/scraper_*.log` for error messages
2. Verify `config.php` database credentials
3. Ensure MySQL is running
4. Check `data/` and `logs/` directories are writable

---

## 📊 Performance

```
Typical Execution:
- Found: 81 vehicles
- Processing time: ~10-15 minutes
- Images stored: 5,500+ total
- Memory usage: ~256MB
```

---

**Last Updated**: December 4, 2025
**Status**: ✅ Production Ready
**Vehicles**: 81 | **Images**: ~6,000 per run | **Vendor**: 432