# 🚗 Car Listings Scraper & Aggregator

A PHP-based scraping system for aggregating used car listings from dealer websites. Designed to run via cron on cPanel hosting.

## Features

- ✅ Scrapes vehicle listings from dealer websites
- ✅ Extracts vehicle details (price, mileage, specs, descriptions)
- ✅ Fetches full descriptions from individual vehicle pages
- ✅ MySQL database with upsert logic (INSERT/UPDATE)
- ✅ Marks removed vehicles as inactive
- ✅ JSON snapshot export
- ✅ Detailed logging
- ✅ Simple API endpoint for frontend
- ✅ Example frontend with search & sort

## Directory Structure

```
car-scraper/
├── CarScraper.php          # Main scraper class
├── scrape.php              # CLI runner for cron
├── config.example.php      # Configuration template
├── config.php              # Your configuration (create this)
├── schema.sql              # MySQL table schema
├── api/
│   └── vehicles.php        # JSON API endpoint
├── public/
│   └── index.html          # Example frontend
├── data/
│   └── vehicles.json       # JSON snapshot (generated)
└── logs/
    └── scraper_YYYY-MM-DD.log  # Daily log files
```

## Installation

### 1. Upload Files

Upload all files to your hosting, e.g., `/home/username/car-scraper/`

### 2. Create Database Tables

Run the SQL in `schema.sql` in your MySQL database:

```bash
mysql -u your_username -p your_database < schema.sql
```

Or paste into phpMyAdmin's SQL tab.

### 3. Configure

Copy and edit the configuration:

```bash
cp config.example.php config.php
```

Edit `config.php` with your database credentials:

```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'your_database_name',
    'username' => 'your_db_username',
    'password' => 'your_db_password',
    'charset'  => 'utf8mb4',
],
```

### 4. Test Manually

Run the scraper manually first to ensure it works:

```bash
cd /home/username/car-scraper
php scrape.php
```

You should see output like:
```
==============================================
Car Listings Scraper - 2024-01-15 10:30:00
==============================================

Starting scrape...
Fetching listing page...
Parsing vehicle cards...
Found 87 vehicles
Fetching detail pages for full descriptions...
  Processing 1/87: volvo-v40-2-0-d4-r-design...
  ...
Saving to database...
Marking removed vehicles as inactive...
Saving JSON snapshot...

==============================================
COMPLETED SUCCESSFULLY
Found: 87
Inserted: 12
Updated: 75
Deactivated: 3
```

### 5. Set Up Cron Job

In cPanel → Cron Jobs, add a new cron job:

**For daily at 6:00 AM:**
```
0 6 * * * /usr/local/bin/php /home/username/car-scraper/scrape.php >> /home/username/car-scraper/logs/cron.log 2>&1
```

**For twice daily (6 AM and 6 PM):**
```
0 6,18 * * * /usr/local/bin/php /home/username/car-scraper/scrape.php >> /home/username/car-scraper/logs/cron.log 2>&1
```

**Notes:**
- Replace `/usr/local/bin/php` with your PHP path (use `which php` to find it)
- Replace `/home/username/` with your actual home directory path
- The `>> ... 2>&1` part appends output to a log file

### 6. Set Up API

If your public directory is `/home/username/public_html/carvendors.co.uk/`:

Option A: Copy the api folder:
```bash
cp -r /home/username/car-scraper/api /home/username/public_html/carvendors.co.uk/
```

Option B: Create a symlink:
```bash
ln -s /home/username/car-scraper/api /home/username/public_html/carvendors.co.uk/api
```

Then update the `require` path in `api/vehicles.php` to point to your config file.

## Command Line Options

```bash
# Normal run (fetch all details)
php scrape.php

# Skip detail page fetching (faster, but no full descriptions)
php scrape.php --no-details

# Skip JSON snapshot generation
php scrape.php --no-json

# Show help
php scrape.php --help
```

## API Usage

### Get All Vehicles
```
GET /api/vehicles.php
```

### With Parameters
```
GET /api/vehicles.php?limit=20&offset=0&sort=price&order=desc&search=volvo
```

**Parameters:**
- `limit` - Number of results (1-250, default: 250)
- `offset` - Pagination offset (default: 0)
- `sort` - Sort field: `price`, `mileage`, `title`, `created`, `updated`
- `order` - Sort order: `asc`, `desc`
- `search` - Search in title

**Response:**
```json
{
    "success": true,
    "meta": {
        "total": 87,
        "limit": 20,
        "offset": 0,
        "returned": 20
    },
    "vehicles": [
        {
            "id": 1,
            "external_id": "volvo-v40-2-0-d4-r-design",
            "title": "Volvo V40 2.0 D4 R-Design Nav Plus Euro 6 (s/s) 5dr - 2016 (66 plate)",
            "price": "£8,990",
            "price_numeric": 8990.00,
            "mileage": "75,000 miles",
            "colour": "Grey",
            "transmission": "Manual",
            "fuel_type": "Diesel",
            "body_style": "Hatchback",
            "first_reg_date": "28/12/2016",
            "description_short": "Amazing specs with full service history...",
            "description_full": "EURO 6| AMAZING SPECS WITH FULL SERVICE HISTORY...",
            "image_url": "https://systonautosltd.co.uk/images/...",
            "vehicle_url": "https://systonautosltd.co.uk/vehicle/name/volvo-v40...",
            "location": "Head office",
            "created_at": "2024-01-15 10:30:00",
            "updated_at": "2024-01-15 10:30:00"
        }
    ]
}
```

## Customization

### Adding New Dealers

1. Create a new configuration file for the dealer
2. Update the parsing methods in `CarScraper.php` if the HTML structure differs
3. Key methods to modify:
   - `parseListingPage()` - Main parsing logic
   - `parseVehicleCard()` - Individual card parsing
   - `extractVehicleDetails()` - Spec extraction regex patterns

### Adjusting Request Rate

Edit `config.php`:
```php
'request_delay' => 2.0,  // Seconds between detail page requests
```

### Description Cutoff Patterns

Add patterns to `config.php`:
```php
'description_cutoff_patterns' => [
    'Finance available',
    'Monthly Payment',
    // Add more patterns here
],
```

## Troubleshooting

### "Configuration file not found"
Copy `config.example.php` to `config.php` and edit it.

### "Database connection failed"
Check your database credentials in `config.php`.

### Scraper finds 0 vehicles
The website structure may have changed. Check the HTML selectors in `parseListingPage()`.

### Cron not running
1. Check PHP path: `which php`
2. Check file permissions: `chmod +x scrape.php`
3. Check cron logs: `grep CRON /var/log/syslog`

### Memory errors
Increase memory limit in `scrape.php`:
```php
ini_set('memory_limit', '512M');
```

## Database Schema

Key tables:

**vehicles** - Main vehicle data
- Unique key: `(source, external_id)`
- `is_active` flag for tracking removed listings
- `price_numeric` and `mileage_numeric` for sorting/filtering

**scrape_logs** - Scrape run history for monitoring

## License

MIT License - feel free to modify and use as needed.
