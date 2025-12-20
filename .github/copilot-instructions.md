# CarVendors Scraper - AI Agent Instructions

## Project Overview

**Production PHP scraper** for systonautosltd.co.uk vehicle listings → CarSafari MySQL database with hash-based change detection, multi-image management, and auto-publishing. Processes ~70 vehicles in 1.3 seconds with 100% efficiency for unchanged data.

**Dual-Mode Architecture**: `CarScraper` (generic base) extends to `CarSafariScraper` (CarSafari schema integration + `StatisticsManager` tracking).

---

## Core Architecture

### Class Design Pattern
- **`CarScraper`** (base): HTTP fetching, HTML parsing, text cleaning, database operations
  - Public: `run()` — initiates scrape cycle
  - Protected: `parseListingPage()`, `enrichWithDetailPages()`, `fetchUrl()`, `cleanText()` — overridable parsing
  - Protected: `$db`, `$config`, `$stats` — accessed by subclasses
  
- **`CarSafariScraper`** (extends): CarSafari-specific implementation
  - Public: `runWithCarSafari()` — production entry point with change detection
  - Public: `setVendorId()` — vendor isolation (default 432=systonautosltd)
  - Private: `saveVehiclesToCarSafari()`, `autoPublishVehicles()`, `downloadAndSaveImages()` — implementation-specific

- **`StatisticsManager`**: Performance & error tracking
  - Initialized in `CarSafariScraper::__construct()`
  - Records metrics: found/inserted/updated/skipped/errors → `scraper_statistics` table
  - Fallback: disabled if initialization fails (non-blocking)

### Data Flow (Single Run)
```
1. Fetch listing page HTML
2. Parse vehicle cards → array[reg_no → {price, mileage, images, specs}]
3. [Optional] Enrich with detail pages (full descriptions, CarCheck data)
4. Change detection: hash comparison (skip 100% for unchanged vehicles)
5. Database upsert: insert/update gyc_vehicle_info, gyc_vehicle_attribute
6. Image download & serial numbering → gyc_product_images (FK by vehicle_info_id)
7. Auto-publish: set active_status=1 for new/changed vehicles
8. Deactivate stale/invalid records (>30 days old, invalid VRM format)
9. Statistics finalization & JSON snapshot export
```

### Database Schema
| Table | Key Fields | Role |
|-------|-----------|------|
| `gyc_vehicle_info` | `reg_no` (PK), `vendor_id`, `attr_id` (FK), `selling_price`, `mileage`, `color`, `description`, `vehicle_url`, `active_status`, `data_hash` | Main vehicle record + change detection |
| `gyc_vehicle_attribute` | `id` (PK), `model`, `year`, `fuel_type`, `transmission`, `body_style`, `engine_size` | Cached specs (one per reg_no) |
| `gyc_product_images` | `id` (PK), `vehicle_info_id` (FK), `file_name` (URL ref), `serial` (1,2,3...) | Multi-image manifest (no actual storage) |
| `scraper_statistics` | `vendor_id`, `execution_date`, `found`, `inserted`, `updated`, `skipped`, `errors` | Performance metrics per run |

---

## Critical Implementation Details

### Safe Daily Refresh - Health-Gated Cleanup
**CRITICAL FEATURE**: Protects live website from zero inventory on bad scrape runs.

**Flow**:
1. **Scrape first** → Save vehicles, auto-publish (no cleanup yet)
2. **Validate health** → Check success_rate >= 85% AND inventory_ratio >= 80%
3. **Cleanup only if healthy** → Deactivate missing vehicles, delete old inactive
4. **On failure** → Keep all data, send alert, exit safely

**Safety Gates** (both must pass):
```php
// Gate 1: Success Rate (85% default)
$successRate = ($inserted + $updated + $skipped) / $found >= 0.85

// Gate 2: Inventory Ratio (80% default)
$newActiveCount >= ($currentActiveCount * 0.80)
```

**If gates fail**: No deletion, no deactivation, alert email sent, live site untouched.

See [SAFE_REFRESH_IMPLEMENTATION.md](SAFE_REFRESH_IMPLEMENTATION.md) for detailed examples.

### Change Detection (Hash-Based Efficiency)
```php
// Only updates when data actually changes (price, mileage, description, images count)
$dataHash = md5(json_encode([
    'selling_price', 'mileage', 'color', 'description', 
    'image_count', 'attention_grabber'
], JSON_SORT_KEYS));

// Compare against stored hash: if match → skip (0.001ms vs re-process 50ms)
if ($storedHash === $dataHash) {
    $stats['skipped']++;  // 76/78 vehicles on typical run
    return;
}
// Else: update vehicle info and image manifest
```

### Image Management Pattern
```php
// Images stored as URL references + serial numbering (not actual files)
// downloadAndSaveImages(array $imageUrls, int $vehicleId, string $regNo)
// Creates: gyc_product_images rows with serial 1, 2, 3...
// Each vehicle can have multiple images; serial tracks order
INSERT INTO gyc_product_images (vehicle_info_id, file_name, serial)
VALUES ($vehicleId, 'https://...jpg', 1), (..., 2), (..., 3)
```

### Configuration Override Pattern
```bash
# config.php sets defaults; CLI args override
php daily_refresh.php --vendor=432 --force
# $vendorId = 432 (override), $force = true (skip change detection)
```

---

## Entry Points & Usage

### Production (Scheduled) - Safe Daily Refresh
```bash
php daily_refresh.php --vendor=432
# Phase 1: Scrape data (no live impact until validated)
# Phase 2: Validate health (success_rate >= 85%, inventory >= 80% of current)
# Phase 3: Cleanup only if healthy (deactivate missing, delete old inactive)
# Phase 4: Report metrics via email alert
```

**Safety Gates (MUST both pass for cleanup)**:
- Success Rate: `(inserted + updated + skipped) / found >= 85%`
- Inventory Ratio: `new_count >= current_count * 80%`

If either gate fails: No deletion, no deactivation, alert sent, data preserved.

### Manual Testing
```bash
php scripts/scrape-carsafari.php  # Ad-hoc run (no daily_refresh safety gates)
php scripts/setup_database.php    # Create/align schema
php scripts/setup_cron.php        # Generate cPanel cron command
```

### Logging & Output
- **Logs**: `logs/scraper_YYYY-MM-DD.log` (per-run entries + gate decisions)
- **JSON**: `data/vehicles.json` + rotation (keeps 12 backups)
- **Stats**: `scraper_statistics` table (queryable via StatisticsManager)
- **Alerts**: Email sent after each run (success or failure reason)

---

## Common Patterns & Workflows

### Adding a New Dealer
1. **Subclass `CarScraper`**: Override `parseListingPage()` + `parseVehicleCard()` for new CSS selectors
2. **Test JSON export**: `scrape.php` entry point (no DB writes)
3. **Integrate with CarSafari**: Subclass `CarSafariScraper` for schema-specific logic
4. **Vendor ID management**: Unique `vendor_id` per dealer (isolates data via WHERE clauses)

### Debugging Parse Failures
1. Check `logs/scraper_*.log` for error messages and line numbers
2. Inspect actual HTML: `scrape-single-page.php` fetches one detail page for manual inspection
3. Update CSS selectors in `parseVehicleCard()` if dealer site structure changed
4. Validate extracted data: `cleanText()` handles UTF-8 garbage; color/price must pass whitelist/regex

### Data Quality Checks
```sql
-- Invalid VRM (regex: [A-Z]{2}[0-9]{2}[A-Z]{3} or older formats)
SELECT COUNT(*) FROM gyc_vehicle_info WHERE reg_no LIKE '%slug%' OR active_status = 0;

-- Missing specs
SELECT COUNT(*) FROM gyc_vehicle_info WHERE attr_id IS NULL;

-- Orphaned images (no parent vehicle)
SELECT gpi.* FROM gyc_product_images gpi 
LEFT JOIN gyc_vehicle_info gvi ON gvi.id = gpi.vehicle_info_id 
WHERE gvi.id IS NULL;
```

---

## Configuration Points (config.php)

| Setting | Default | Impact |
|---------|---------|--------|
| `database.dbname` | `tst-car` | Target database (change for production) |
| `scraper.listing_url` | systonautosltd... | HTML source (change for new dealer) |
| `scraper.fetch_detail_pages` | `true` | false = fast (listing only); true = slow (full specs) |
| `scraper.request_delay` | 1.5s | Politeness between HTTP requests |
| `scraper.timeout` | 30s | Request timeout (increase for slow sites) |
| `scraper.verify_ssl` | `false` | Local WAMP: false; Production: true |
| `output.save_json` | `true` | Export vehicles.json snapshot |

---

## Testing & Verification

### Quick Validation
```bash
# Test parsing without DB writes (use --dry-run if supported)
php daily_refresh.php --vendor=432 --force

# Check logs for success/errors
tail logs/scraper_*.log

# Verify DB: count vehicles by vendor
mysql> SELECT vendor_id, COUNT(*) FROM gyc_vehicle_info GROUP BY vendor_id;
```

### Profiling Performance
- Each run logs execution time, vehicle counts (found/inserted/updated/skipped)
- Change detection efficiency: `skipped / found` (goal: >90%)
- Database load: update operations should <<50% of found count

---

## Extension Points for Enhancements

| Feature | Location | Pattern |
|---------|----------|---------|
| **New dealer source** | Subclass `CarScraper`, override `parseVehicleCard()` | Protected methods allow override |
| **New database schema** | Subclass `CarSafariScraper`, override `saveVehicles()` | Private helpers can be refactored to protected |
| **New data fields** | Add to vehicle array in parsing, validate in `cleanText()` | Whitelist + regex for validation |
| **Image compression** | Modify `downloadAndSaveImage()` | Before file write, apply imagemagick/GD |
| **Error notifications** | Enhance `mail_alert.php` | Hook into `finishScrapeLog()` with error summary |
| **Statistics reporting** | `StatisticsManager` public query methods | `getStatisticsForDateRange()`, `getDailyStatistics()` |

---

## Known Issues & Constraints

1. **Website changes**: CSS selectors break if dealer updates HTML → update `parseVehicleCard()`
2. **UTF-8 garbage**: Some sources export broken UTF-8 → `cleanText()` handles common cases
3. **VRM validation**: Slug reg_nos auto-deactivated; `isValidVrm()` enforces UK format
4. **Image URLs only**: `gyc_product_images.file_name` stores URLs, not binary files
5. **Memory limit**: 512MB set in scripts; increase for 500+ vehicles with images

---

## Key Files Reference

| File | Purpose | Key Methods |
|------|---------|-------------|
| [CarScraper.php](CarScraper.php) | Base scraper | `run()`, `parseListingPage()`, `cleanText()`, `log()` |
| [CarSafariScraper.php](CarSafariScraper.php) | CarSafari integration | `runWithCarSafari()`, `saveVehiclesToCarSafari()`, `autoPublishVehicles()` |
| [daily_refresh.php](daily_refresh.php) | Production orchestrator | Phase 0-7 workflow, vendor purge, change detection |
| [config.php](config.php) | Configuration | Database, scraper, output settings |
| [src/StatisticsManager.php](src/StatisticsManager.php) | Metrics tracking | `initializeStatistics()`, `saveStatistics()`, query methods |
| [CLAUDE.md](CLAUDE.md) | Detailed context | Architecture deep-dives, data model, performance analysis |

---

**Status**: ✅ Production ready. Handles 70+ vehicles/run with <2s execution and 100% efficiency for unchanged data via hash-based change detection.
