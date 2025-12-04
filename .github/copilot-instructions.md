# CarVendors Scraper - AI Agent Instructions

## Project Overview

A PHP web scraper that extracts used car listings from dealer websites (currently systonautosltd.co.uk) and publishes them directly to a CarSafari vehicle database with full data normalization, image management, and auto-publishing.

**Key Design**: Dual-mode architecture—`CarScraper` (JSON export) and `CarSafariScraper` (database auto-publish).

---

## Architecture Essentials

### Class Hierarchy & Responsibility
- **CarScraper** (base): HTTP fetching, HTML parsing, data extraction, text cleaning, generic database saving
- **CarSafariScraper** (extends): CarSafari-specific schema (gyc_* tables), multi-image handling, vendor management, auto-publish workflow

### Data Flow Pipeline
```
Listing Page (164 vehicles)
  ↓ parseListingPage() → array of basic vehicle data
  ↓ enrichWithDetailPages() → fetch full specs, descriptions, images
  ↓ saveVehicles/saveVehiclesToCarSafari() → database insert/update
  ↓ downloadAndSaveImages() → serial-numbered images (YYYYMMDDHHmmss_N.jpg)
  ↓ autoPublishVehicles() → set active_status=1, auto-live
  → CarSafari website LIVE
```

### Critical Data Transformations
1. **Text Cleaning** (CarScraper:783-813): 7-step UTF-8 garbage removal—handles broken characters (â¦, â€™), control chars, non-ASCII bytes
2. **Price/Mileage**: Regex extraction of numeric values from formatted strings (£5,490 → 5490)
3. **Colour Validation**: Whitelist enforcement (50+ valid colors) vs rejected garbage (e.g., "TOUCHSCREEN")
4. **Image Serials**: Timestamp_N format (20251203143049_1.jpg, _2.jpg) links multiple images per vehicle

### Database Schema Mapping
| Table | Purpose | Key Fields |
|-------|---------|-----------|
| `gyc_vehicle_info` | Main vehicle record | `reg_no` (unique), `vendor_id` (432=scraped), `vehicle_url`, `selling_price`, `color`, `description`, `active_status` (0-4, 1=live) |
| `gyc_vehicle_attribute` | Specs cache | `model`, `year`, `fuel_type`, `transmission`, `body_style` |
| `gyc_product_images` | Image manifest | `file_name` (serial), `vehicle_info_id` (FK), `serial` (1,2,3...) |

---

## Critical Implementation Patterns

### Protected vs Private Methods
**Why**: CarSafariScraper needs to override parsing behavior—use `protected` for methods called by child classes. Examples:
- `protected function parseListingPage()` — called by both run() and runWithCarSafari()
- `protected function enrichWithDetailPages()` — shared detail fetching
- `protected $config`, `protected $db` — accessed by CarSafariScraper::saveVehiclesToCarSafari()

### Image Download & Linking
```php
// Pattern: downloadAndSaveImages(array $imageUrls, int $vehicleId)
// Saves each image with serial: 20251203143049_1.jpg, _2.jpg, etc.
// Links via: INSERT gyc_product_images(vehicle_info_id, file_name, serial)
```

### Deduplication Strategy
Uses `reg_no` (vehicle registration) as unique identifier—INSERT ignores duplicate REG numbers, UPDATE refreshes price/mileage on re-scrape.

### Configuration Override Pattern
Command-line args override config.php:
```bash
php scrape-carsafari.php --no-details --vendor=2
# Sets: $config['scraper']['fetch_detail_pages'] = false; $vendorId = 2;
```

---

## Common Development Tasks

### Adding a New Dealer Source
1. Create `YourDealerScraper extends CarScraper`
2. Override `parseListingPage()` with new CSS selector path
3. Override `parseVehicleCard()` with dealer's HTML structure
4. Test with `scrape.php` (JSON output) before database integration
5. Create `scrape-yourscraper.php` entry point with vendor ID management

### Fixing Parsing Issues
1. Check `logs/scraper_YYYY-MM-DD.log` for parse errors
2. Debug in `scrape-single-page.php` with direct HTML inspection
3. Verify CSS selectors match current website structure (websites change!)
4. Add test case to ensure whitelist validation (colors, fields)

### Data Quality Improvements
See `CLAUDE.md` for 5 implemented improvements:
- Vendor ID default (432)
- Vehicle URL field addition
- Multi-image serial numbering
- Colour whitelist validation (CarScraper:426-455)
- UTF-8 garbage cleanup (CarScraper:783-813)

---

## Key Files & Their Purpose

| File | Purpose |
|------|---------|
| `CarScraper.php` | Base: fetching, parsing, text cleaning, generic DB save |
| `CarSafariScraper.php` | Extends: CarSafari schema, image management, vendor/publish logic |
| `scrape-carsafari.php` | CLI entry point with cmd-line arg parsing |
| `config.php` | Database credentials, URL patterns, timeouts, output paths |
| `CLAUDE.md` | Complete context memory with data quality details & SQL schema |
| `QUICK_REFERENCE.md` | Copy-paste commands for cron, debugging, monitoring |

---

## Critical Configuration Points

### Database Connection (config.php)
```php
'database' => [
    'host' => 'localhost',
    'dbname' => 'carsafari',  // Target database name
    'username' => 'db_user',
    'password' => 'db_password',
    'charset' => 'utf8mb4',
]
```

### Scraper Behavior (config.php)
- `fetch_detail_pages`: true = slower but complete; false = listing-only, fast
- `request_delay`: 1.5s politeness delay between HTTP requests
- `timeout`: 30s request timeout (increase for slow sites)
- `verify_ssl`: false for local WAMP, true for production

### Logging & Output
- Logs: `logs/scraper_YYYY-MM-DD.log` (auto-created)
- JSON: `data/vehicles.json` (all vehicles snapshot)
- Images: `images/YYYYMMDDHHmmss_*.jpg` (numbered by timestamp)

---

## Testing & Debugging Workflow

### Quick Test
```bash
cd /path/to/scraper
php scrape-carsafari.php --no-details  # Fast listing-only run
# Check: logs/scraper_*.log for output
# Check: database for new records
```

### Single-Page Debug
```bash
php scrape-single-page.php  # Fetch/parse ONE vehicle detail
# Test HTML structure changes without full scrape
```

### Database Verification
```sql
-- Count vehicles by vendor
SELECT vendor_id, COUNT(*) FROM gyc_vehicle_info GROUP BY vendor_id;

-- Check latest images
SELECT vehicle_info_id, file_name, serial FROM gyc_product_images ORDER BY created_at DESC LIMIT 10;

-- Find NULL/invalid data
SELECT COUNT(*) FROM gyc_vehicle_info WHERE description IS NULL OR color NOT IN ('black','white',...);
```

---

## Deployment Notes

### Local (Windows WAMP)
```bash
php c:\wamp64\bin\php\php8.3.14\php.exe scrape-carsafari.php
```

### Production (cPanel/Linux Cron)
```bash
# Add to crontab (runs at 6 AM & 6 PM daily)
0 6,18 * * * /usr/bin/php /home/user/carvendors-scraper/scrape-carsafari.php >> logs/cron.log 2>&1
```

### Safety Practices
1. Test locally first with `--no-details` flag
2. Backup database before first production run: `mysqldump carsafari > backup.sql`
3. Monitor `logs/scraper_*.log` for errors (check in cron.log post-execution)
4. Restrict `config.php` permissions: `chmod 600 config.php`

---

## Known Constraints & Workarounds

1. **Website Structure Changes**: Parsing fails if dealer updates HTML—verify CSS selectors in `parseVehicleCard()` against live site
2. **Image Download Timeouts**: Use `--no-details` flag to skip image downloads if hitting timeout
3. **UTF-8 Garbage**: Some dealers export broken UTF-8—7-step cleanup in `cleanText()` handles common cases
4. **Duplicate Prevention**: Based on `reg_no` only—if same vehicle listed twice, it updates; use WHERE clauses to filter
5. **Memory on Large Scrapes**: 512MB limit set in `scrape-carsafari.php` (line 35); increase if handling 500+ vehicles

---

## Extension Points for AI Agents

When working on enhancements:
- **New Dealer**: Subclass `CarScraper`, override `parseVehicleCard()` & `parseListingPage()`
- **New Database**: Subclass `CarSafariScraper`, override `saveVehicles()` & `autoPublishVehicles()`
- **New Data Fields**: Add to vehicle array in parsing, validate in `cleanText()` or whitelist, map to DB schema
- **Image Processing**: Modify `downloadAndSaveImage()` for compression/resizing before saving
- **Error Recovery**: Wrap DB operations in try-catch, store failed vehicle IDs for retry logic

---

**For detailed implementation history, test results, and data quality improvements, see `CLAUDE.md`.**
