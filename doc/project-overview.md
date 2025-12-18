# 🚀 **Project Overview – Daily Refresh Flow (Vendor 432)**

Complete architectural documentation for the CarVendors scraper system. This describes the end-to-end process when you run:
```bash
php daily_refresh.php --vendor=432
```

---

## 📋 **Table of Contents**
1. [Architecture Overview](#architecture-overview)
2. [Execution Flow](#execution-flow)
3. [File Structure & Dependencies](#file-structure--dependencies)
4. [Data Sources](#data-sources)
5. [Database Operations](#database-operations)
6. [Error Handling & Statistics](#error-handling--statistics)

---

## 🏗️ **Architecture Overview**

### **High-Level Data Flow**
```
┌──────────────────────┐     ┌──────────────────────┐     ┌──────────────────────┐
│  systonautosltd.co.uk│────▶│   VRM Extraction     │────▶│   CarCheck.co.uk     │
│  (Dealer Website)    │     │   + Basic Parsing    │     │   (Enhanced Data)    │
└──────────────────────┘     └──────────────────────┘     └──────────────────────┘
         │                              │                              │
         ▼                              ▼                              ▼
┌─────────────────────┐     ┌─────────────────────┐     ┌─────────────────────┐
│ • Vehicle Listings  │     │ • Real UK VRM       │     │ • BHP/MPG/CO2       │
│ • Images (All)      │     │ • Price/Mileage     │     │ • Dimensions        │
│ • Basic Specs       │     │ • Colour/Body       │     │ • Weight/Speed      │
└─────────────────────┘     └─────────────────────┘     └─────────────────────┘
         │                              │                              │
         └──────────────────────────────┴──────────────────────────────┘
                                       ▼
                          ┌────────────────────────────┐
                          │   Database Upsert Engine   │
                          │  (Change Detection + Hash) │
                          └────────────────────────────┘
                                       ▼
         ┌──────────────────────────────┬──────────────────────────────┐
         ▼                              ▼                              ▼
┌──────────────────┐      ┌──────────────────────┐      ┌──────────────────────┐
│gyc_vehicle_info  │      │gyc_vehicle_attribute │      │gyc_product_images    │
│(Main Data)       │◀─────│(Specs/Model Data)    │      │(Image Storage)       │
│• reg_no (PK)     │attr_id│• model, year, etc.  │      │• file_name           │
│• price, mileage  │      │• make_id (FK)        │      │• vechicle_info_id(FK)│
│• vendor_id=432   │      │• trim (CarCheck JSON)│      │• serial (order)      │
└──────────────────┘      └──────────────────────┘      └──────────────────────┘
```

### **Database Relationships**
```
gyc_vehicle_info (main listing data)
  ├── attr_id → gyc_vehicle_attribute.id (vehicle specifications)
  ├── vendor_id → gyc_vendor_info.id (432 = systonautosltd)
  ├── color_id → gyc_vehicle_color.id (exterior color)
  ├── manufacturer_color_id → gyc_vehicle_color.id (original paint)
  ├── interior_color_id → gyc_vehicle_color.id (interior trim)
  ├── reg_no (UK VRM: "WP66UEX")
  ├── engine_no (fallback: reg_no if not available)
  ├── selling_price, regular_price, mileage
  ├── description (full cleaned text)
  ├── active_status (0=pending, 1=waiting, 2=published, 3=sold, 4=blocked)
  └── vehicle_url (source dealer URL)

gyc_vehicle_attribute (model/specs enriched from CarCheck)
  ├── make_id → gyc_make.id (cached lookup)
  ├── category_id → gyc_category.id
  ├── model, generation, trim (JSON: {"bhp":150,"mpg":52.3,"co2":120})
  ├── year, engine_size, fuel_type, transmission
  ├── body_style, gearbox, derivative
  └── active_status

gyc_product_images (multiple images per vehicle)
  ├── vechicle_info_id → gyc_vehicle_info.id (FK)
  ├── file_name (image URL from dealer)
  ├── serial (1, 2, 3... for ordering)
  └── created_at

gyc_make (manufacturer lookup - cached in memory per run)
  ├── id (PK)
  ├── name ("Volkswagen", "Ford", etc.)
  └── active_status

gyc_vehicle_color (color standardization - cached in memory per run)
  ├── id (PK)
  ├── color_name ("Black", "White", "Silver", etc.)
  └── active_status
```

---

## ⚙️ **Execution Flow**

### **Phase 0: Initialization** (`daily_refresh.php` lines 1-138)
```php
Entry Point: php daily_refresh.php --vendor=432
  │
  ├─▶ Load config.php (database credentials, scraper settings)
  ├─▶ Parse CLI args (--vendor=432, --force)
  ├─▶ Initialize PDO database connection
  ├─▶ Set memory limit (512M) & timeout (1800s)
  └─▶ Load dependencies:
       • CarScraper.php (base HTTP/parsing logic)
       • CarSafariScraper.php (CarSafari-specific logic)
       • StatisticsManager.php (metrics tracking)
       • mail_alert.php (email notifications)
```

### **Phase 1: Data Purge** (`daily_refresh.php` lines 94-138)
**Purpose**: Delete old vendor data BEFORE scraping to avoid stale records.

```php
Function: $purgeVendorData($vendorId = 432)
  │
  ├─▶ Step 1: Delete images from gyc_product_images
  │    WHERE vechicle_info_id IN (
  │      SELECT id FROM gyc_vehicle_info WHERE vendor_id = 432
  │    )
  │    Result: e.g., "Deleted 2244 images"
  │
  ├─▶ Step 2: Delete vehicles from gyc_vehicle_info
  │    WHERE vendor_id = 432
  │    Result: e.g., "Deleted 68 vehicles"
  │
  ├─▶ Step 3: Clean orphaned attributes from gyc_vehicle_attribute
  │    WHERE id NOT IN (SELECT DISTINCT attr_id FROM gyc_vehicle_info)
  │    Result: e.g., "Cleaned 0 orphaned attributes"
  │
  └─▶ Output: "Purge complete: 2244 images, 68 vehicles, 0 orphans deleted"
```

### **Phase 2: Scraping** (`daily_refresh.php` lines 144-165)
```php
Entry: $scraper->runWithCarSafari()
  │
  ├─▶ Step 2.1: Initialize StatisticsManager
  │    (creates scraper_statistics row if table exists)
  │
  ├─▶ Step 2.2: Cleanup old log files
  │    (delete logs older than 7 days from logs/ folder)
  │
  ├─▶ Step 2.3: Fetch listing page (CarScraper.php)
  │    URL: https://systonautosltd.co.uk/vehicle/search/.../limit/250/
  │    Method: fetchUrl() with cURL
  │    • Request delay: 1.5s politeness (from config)
  │    • User-Agent: Chrome 120 Windows 10
  │    • SSL verify: false (WAMP localhost)
  │    Result: Full HTML page (250 vehicle listings)
  │
  ├─▶ Step 2.4: Parse listing page (CarScraper.php lines 196-246)
  │    Method: parseListingPage($html)
  │    • Load HTML into DOMDocument
  │    • XPath query: //div[@class='vehicle-card'] (or similar)
  │    • For each card:
  │       ├─ Extract: external_id (URL slug)
  │       ├─ Extract: title ("2016 Volkswagen Polo 1.0 TSI Match")
  │       ├─ Extract: price (£5,490 → 5490.00)
  │       ├─ Extract: mileage (42,523 miles → 42523.0)
  │       ├─ Extract: vehicle_url (detail page link)
  │       └─ Extract: first image URL
  │    Result: Array of 71 vehicles with basic data
  │    Stats: $this->stats['found'] = 71
  │
  ├─▶ Step 2.5: Enrich with detail pages (CarScraper.php lines 785-857)
  │    Method: enrichWithDetailPages($vehicles)
  │    For each vehicle (71 iterations):
  │      │
  │      ├─▶ Fetch detail page HTML
  │      │    URL: vehicle_url (e.g., .../vehicle/polo-12345/)
  │      │    Delay: 1.5s between requests (politeness)
  │      │    Consecutive failure detection: abort after 5 failures
  │      │
  │      ├─▶ Extract full description (CarScraper.php lines 864-878)
  │      │    XPath: //div[@class='vehicle-description']
  │      │    • Remove finance text patterns (DISABLED - keep all)
  │      │    • Clean UTF-8 garbage (7-step process)
  │      │    • Store in: vehicle['description_full']
  │      │
  │      ├─▶ Extract vehicle details (CarScraper.php lines 486-755)
  │      │    Method: extractVehicleDetails($html)
  │      │    XPath/Regex extraction:
  │      │      • vrm: UK registration (e.g., "WP66UEX") - CRITICAL
  │      │      • colour: validated against 50+ whitelist
  │      │      • transmission: Manual/Automatic
  │      │      • fuel_type: Petrol/Diesel/Hybrid/Electric
  │      │      • body_style: Hatchback/Saloon/SUV/etc.
  │      │      • doors: 2/3/4/5
  │      │      • drive: Front/Rear/AWD
  │      │      • engine_size: 1000cc → 1.0L
  │      │      • all_images: array of ALL image URLs (carousel)
  │      │    Result: Merged into vehicle array
  │      │
  │      ├─▶ Override listing data with detail page data
  │      │    (Detail page is more accurate than listing cards)
  │      │    • vehicle['reg_no'] = vrm (CRITICAL: replaces URL slug)
  │      │    • vehicle['image_urls'] = cleaned & deduplicated all_images
  │      │
  │      └─▶ Log extracted data for debugging
  │           "Found VRM: WP66UEX"
  │           "Found 33 images (cleaned to: 33)"
  │           "Found colour: Silver"
  │
  └─▶ Result: Array of 71 vehicles with COMPLETE data
```

### **Phase 3: Database Save** (`CarSafariScraper.php` lines 177-231)
```php
Method: saveVehiclesToCarSafari($vehicles)
  │
  For each vehicle (71 iterations):
    │
    ├─▶ Step 3.1: Extract & validate VRM
    │    $regNo = vehicle['reg_no'] ?? vehicle['external_id']
    │    • Uppercase & strip spaces: "WP66UEX"
    │    • Validate VRM format: isValidVrm() regex check
    │    • Skip if invalid (e.g., URL slug "polo-12345")
    │    Stats: errors++ if invalid
    │
    ├─▶ Step 3.2: Save vehicle attributes (CarSafariScraper.php lines 566-688)
    │    Method: saveVehicleAttributes($regNo, $vehicle)
    │      │
    │      ├─▶ Step 3.2a: Extract make from title
    │      │    Method: extractMakeFromTitle("2016 Volkswagen Polo...")
    │      │    • Tokenize title, match against known makes
    │      │    • Result: "Volkswagen"
    │      │
    │      ├─▶ Step 3.2b: Resolve make_id (cached lookup)
    │      │    Method: resolveMakeId("Volkswagen")
    │      │    • Check in-memory cache: $this->makeCache
    │      │    • If miss: SELECT id FROM gyc_make WHERE name = ?
    │      │    • Cache hit rate: ~99% (71 vehicles, ~5 makes)
    │      │    Result: make_id = 123
    │      │
    │      ├─▶ Step 3.2c: Extract model from title
    │      │    Method: extractModelFromTitle("2016 Volkswagen Polo 1.0 TSI")
    │      │    • Remove make name, year, trim
    │      │    • Result: "Polo"
    │      │
    │      ├─▶ Step 3.2d: Fetch CarCheck data (CarSafariScraper.php lines 485-560)
    │      │    Method: getCarCheckData($regNo, $title)
    │      │      │
    │      │      ├─▶ Build CarCheck URL
    │      │      │    URL: https://www.carcheck.co.uk/volkswagen/WP66UEX
    │      │      │    Delay: 1s politeness (CarCheck rate limit)
    │      │      │
    │      │      ├─▶ Fetch & parse HTML
    │      │      │    Regex extraction:
    │      │      │      • bhp: /(\d+)\s*BHP/i → 150
    │      │      │      • engine_size: /(\d+(?:\.\d+)?)\s*cc/i → 1000
    │      │      │      • co2_emissions: /(\d+)\s*g\/km/i → 120
    │      │      │      • top_speed: /(\d+)\s*mph/i → 115
    │      │      │      • mpg: /(\d+(?:\.\d+)?)\s*mpg/i → 52.3
    │      │      │      • weight: /(\d+)\s*kg/i → 1200
    │      │      │      • dimensions: /(\d+)\s*mm\s*width/i → 1682mm
    │      │      │      • fuel_type: /(Diesel|Petrol|Hybrid|Electric)/i
    │      │      │      • transmission: /(Manual|Automatic)/i
    │      │      │      • colour: /(?:Colour|Color):\s*([A-Za-z]+)/i
    │      │      │
    │      │      └─▶ Return: array or null (if no data found)
    │      │           Stats: errors++ if timeout/failure
    │      │
    │      ├─▶ Step 3.2e: Find or create attribute record
    │      │    Method: findOrCreateAttribute($vehicle)
    │      │    • SELECT id FROM gyc_vehicle_attribute
    │      │      WHERE reg_no = ? OR (model = ? AND year = ?)
    │      │    • If NOT found: INSERT new row
    │      │
    │      ├─▶ Step 3.2f: Build trim JSON (CarCheck enrichment)
    │      │    $trimData = json_encode([
    │      │      'bhp' => 150,
    │      │      'mpg' => 52.3,
    │      │      'co2_emissions' => 120,
    │      │      'top_speed' => 115,
    │      │      'weight' => 1200,
    │      │      'dimensions' => '1682mm width'
    │      │    ])
    │      │
    │      └─▶ Step 3.2g: UPDATE gyc_vehicle_attribute
    │           SET make_id = ?, model = ?, year = ?,
    │               engine_size = ?, fuel_type = ?, transmission = ?,
    │               body_style = ?, gearbox = ?, trim = ?
    │           WHERE id = ?
    │           Result: attr_id = 456
    │
    ├─▶ Step 3.3: Save vehicle info (change detection)
    │    Method: saveVehicleInfoWithChangeDetection($vehicle, $attrId, $now)
    │      │
    │      ├─▶ Step 3.3a: Calculate data hash
    │      │    Method: calculateDataHash($vehicle)
    │      │    • Hash fields: reg_no, price, mileage, color, description
    │      │    • Algorithm: SHA256(concat(sorted_values))
    │      │    Result: hash = "a1b2c3d4..."
    │      │
    │      ├─▶ Step 3.3b: Get stored hash from database
    │      │    Method: getStoredDataHash($regNo)
    │      │    • SELECT data_hash FROM gyc_vehicle_info WHERE reg_no = ?
    │      │    Result: storedHash = "a1b2c3d4..." or null
    │      │
    │      ├─▶ Step 3.3c: Compare hashes
    │      │    If hash == storedHash:
    │      │      • Skip update (no changes)
    │      │      • Stats: skipped++
    │      │      • Return: ['action' => 'skipped', 'vehicleId' => existingId]
    │      │    Else:
    │      │      • Proceed to upsert
    │      │
    │      ├─▶ Step 3.3d: Resolve color IDs (cached lookups)
    │      │    Method: resolveColorId($color)
    │      │    • Check cache: $this->colorCache
    │      │    • If miss: SELECT id FROM gyc_vehicle_color WHERE color_name = ?
    │      │    • Used for: color_id, manufacturer_color_id
    │      │    Result: color_id = 789
    │      │
    │      ├─▶ Step 3.3e: Build engine_no (fallback logic)
    │      │    $engineNo = vehicle['engine_no'] ?? vehicle['reg_no']
    │      │    (If no VIN/chassis, use UK registration as identifier)
    │      │
    │      └─▶ Step 3.3f: UPSERT into gyc_vehicle_info
    │           INSERT INTO gyc_vehicle_info (
    │             attr_id, vendor_id, reg_no, engine_no,
    │             selling_price, regular_price, mileage,
    │             color, color_id, manufacturer_color_id,
    │             description, vehicle_url, data_hash,
    │             seats, doors, drive_system, v_condition,
    │             active_status, created_at, updated_at
    │           ) VALUES (...)
    │           ON DUPLICATE KEY UPDATE (if reg_no exists):
    │             selling_price = VALUES(selling_price),
    │             mileage = VALUES(mileage),
    │             data_hash = VALUES(data_hash),
    │             updated_at = NOW()
    │           Result: vehicleId = 12345
    │           Stats: inserted++ OR updated++
    │
    ├─▶ Step 3.4: Download & save images (if data changed)
    │    Method: downloadAndSaveImages($imageUrls, $vehicleId, $regNo)
    │      │
    │      For each image URL (33 images):
    │        │
    │        ├─▶ Step 3.4a: Check if image already exists
    │        │    SELECT serial FROM gyc_product_images
    │        │    WHERE vechicle_info_id = ? AND file_name = ?
    │        │    • Skip if found (avoid duplicates)
    │        │
    │        ├─▶ Step 3.4b: Get next serial number
    │        │    SELECT IFNULL(MAX(serial), 0) + 1
    │        │    FROM gyc_product_images
    │        │    WHERE vechicle_info_id = ?
    │        │    Result: serial = 1, 2, 3...
    │        │
    │        └─▶ Step 3.4c: INSERT into gyc_product_images
    │             INSERT INTO gyc_product_images (
    │               vechicle_info_id, file_name, serial, created_at
    │             ) VALUES (12345, 'https://...image.jpg', 1, NOW())
    │             Stats: images_stored++
    │
    └─▶ Step 3.5: Track active vehicle IDs
         $activeIds[] = vehicleId
         (Used later for publishing & stale detection)
```

### **Phase 4: Auto-Publish** (`CarSafariScraper.php` lines 725-743)
```php
Method: autoPublishVehicles($activeIds)
  │
  └─▶ UPDATE gyc_vehicle_info
       SET active_status = '1',  -- 1 = waiting/approved for publish
           publish_date = NOW()
       WHERE id IN (12345, 12346, ..., 12413)  -- 68 vehicle IDs
       Result: All scraped vehicles set to "waiting" status
```

### **Phase 5: Stale Vehicle Cleanup** (`CarSafariScraper.php` lines 1126-1156)
```php
Method: deactivateInvalidAndStaleVehicles($activeIds)
  │
  ├─▶ Step 5.1: Deactivate non-VRM records (URL slugs)
  │    UPDATE gyc_vehicle_info
  │    SET active_status = '4'  -- 4 = blocked/invalid
  │    WHERE vendor_id = 432
  │      AND reg_no REGEXP '^[a-z0-9-]+$'  -- slug format
  │      AND active_status != '4'
  │    Result: e.g., "Deactivated 0 invalid VRM records"
  │
  ├─▶ Step 5.2: Deactivate vehicles missing from current scrape
  │    UPDATE gyc_vehicle_info
  │    SET active_status = '0'  -- 0 = pending/inactive
  │    WHERE vendor_id = 432
  │      AND id NOT IN (12345, 12346, ..., 12413)  -- active IDs
  │      AND active_status IN ('1', '2')  -- only deactivate published
  │    Result: e.g., "Deactivated 0 stale vehicles"
  │    Stats: deactivated += affected rows
  │
  └─▶ Log: "Deactivated 0 invalid + 0 stale vehicles (total: 0)"
```

### **Phase 6: JSON Export** (`CarSafariScraper.php` lines 875-1063)
```php
Method: saveJsonSnapshot()
  │
  ├─▶ Step 6.1: Rotate old JSON files
  │    Method: rotateJsonFiles('data/vehicles.json')
  │    • vehicles.json → vehicles11.json (delete if exists)
  │    • vehicles.json → vehicles12.json (rename current)
  │    Result: Up to 12 historical snapshots
  │
  ├─▶ Step 6.2: Fetch all active vehicles from database
  │    SELECT vi.*, va.*
  │    FROM gyc_vehicle_info vi
  │    LEFT JOIN gyc_vehicle_attribute va ON vi.attr_id = va.id
  │    WHERE vi.active_status IN ('1', '2')
  │    ORDER BY vi.created_at DESC
  │
  ├─▶ Step 6.3: Fetch images for each vehicle
  │    SELECT file_name, serial
  │    FROM gyc_product_images
  │    WHERE vechicle_info_id = ?
  │    ORDER BY serial ASC
  │
  ├─▶ Step 6.4: Build comprehensive JSON structure
  │    $export = [
  │      'metadata' => [
  │        'exported_at' => '2025-12-18T14:30:49Z',
  │        'total_vehicles' => 68,
  │        'database' => 'carsafari',
  │        'vendor_id' => 432
  │      ],
  │      'vehicles' => [
  │        [
  │          'id' => 12345,
  │          'reg_no' => 'WP66UEX',
  │          'engine_no' => 'WP66UEX',
  │          'title' => '2016 Volkswagen Polo 1.0 TSI Match',
  │          'make_id' => 123,
  │          'model' => 'Polo',
  │          'year' => 2016,
  │          'selling_price' => 5490.00,
  │          'mileage' => 42523.0,
  │          'color' => 'Silver',
  │          'color_id' => 789,
  │          'manufacturer_color_id' => 789,
  │          'fuel_type' => 'Petrol',
  │          'transmission' => 'Manual',
  │          'body_style' => 'Hatchback',
  │          'engine_size' => '1.0L',
  │          'trim' => '{"bhp":150,"mpg":52.3,"co2":120}',
  │          'description' => 'Full cleaned description...',
  │          'images' => [
  │            'https://...image1.jpg',
  │            'https://...image2.jpg',
  │            ... (33 images)
  │          ],
  │          'vehicle_url' => 'https://systonautosltd.co.uk/vehicle/polo-12345/',
  │          'active_status' => '1',
  │          'created_at' => '2025-12-18 14:25:10',
  │          'updated_at' => '2025-12-18 14:30:45'
  │        ],
  │        ... (67 more vehicles)
  │      ]
  │    ]
  │
  └─▶ Step 6.5: Write JSON to file
       file_put_contents('data/vehicles.json', json_encode($export, JSON_PRETTY_PRINT))
       Result: "Saved 68 vehicles to data/vehicles.json"
```

### **Phase 7: Statistics & Logging** (`daily_refresh.php` lines 213-241)
```php
StatisticsManager::finalizeStatistics('completed')
  │
  ├─▶ Calculate metrics:
  │    • Duration: (endTime - startTime) / 60 = 12.45 minutes
  │    • Success rate: (inserted + updated) / found * 100 = 95.8%
  │    • Change rate: (inserted + updated) / (inserted + updated + skipped) * 100
  │
  ├─▶ INSERT INTO scraper_statistics (
  │     vendor_id, run_date, status,
  │     vehicles_found, vehicles_inserted, vehicles_updated,
  │     vehicles_skipped, vehicles_failed, images_stored,
  │     duration_minutes, stats_json, created_at
  │   ) VALUES (
  │     432, '2025-12-18', 'completed',
  │     71, 68, 0, 0, 2, 2244,
  │     12.45, '{"hash_skips":0,"db_hits":71}', NOW()
  │   )
  │
  └─▶ Log summary:
       "Scraping completed in 745.23 seconds"
       "Found: 71"
       "Inserted: 68"
       "Updated: 0"
       "Skipped: 0"
       "Errors: 2"  (CarCheck timeouts)
       "Images stored: 2244"
       "Active vehicles: 68"
```

### **Phase 8: Email Alerts** (`mail_alert.php` lines 13-100)
```php
Function: send_scrape_alert($vendorId, $stats, $success, $note)
  │
  ├─▶ Step 8.1: Load SMTP config
  │    • Host: smtp.gmail.com
  │    • Port: 587 (TLS)
  │    • Username: delwerhossain006@gmail.com
  │    • Password: lbtebnztuepfiuvr (app password)
  │    • Recipients: delwer.dev@gmail.com, delwerhossain006@gmail.com
  │
  ├─▶ Step 8.2: Build email
  │    Subject: [CarSafari] Vendor 432 scrape SUCCESS - ok: 68, fail: 2
  │    Body:
  │      Vendor: 432
  │      Status: SUCCESS
  │      Found: 71
  │      Inserted: 68
  │      Updated: 0
  │      Skipped: 0
  │      Errors: 2
  │      Images: 2244
  │      Note: Run completed with 2 failures (e.g., invalid VRMs or fetch errors).
  │
  ├─▶ Step 8.3: Connect to SMTP server
  │    Method: smtp_send() - custom minimal SMTP client
  │    • STARTTLS upgrade
  │    • AUTH LOGIN (base64 credentials)
  │    • MAIL FROM / RCPT TO / DATA
  │
  └─▶ Step 8.4: Send email
       • Success: "Alert sent to delwer.dev@gmail.com, delwerhossain006@gmail.com"
       • Failure: Fallback to mail() function (system mailer)
```

---

## 📂 **File Structure & Dependencies**

### **Execution Order**
```
1. daily_refresh.php (orchestrator)
   │
   ├─▶ config.php (loaded)
   │    └─▶ Database credentials, scraper settings, paths
   │
   ├─▶ CarScraper.php (parent class)
   │    └─▶ HTTP fetching, HTML parsing, text cleaning, base DB operations
   │
   ├─▶ CarSafariScraper.php (child class, extends CarScraper)
   │    └─▶ CarSafari schema mapping, image management, CarCheck integration
   │
   ├─▶ StatisticsManager.php (metrics tracking)
   │    └─▶ INSERT/UPDATE scraper_statistics table
   │
   └─▶ mail_alert.php (notifications)
        └─▶ SMTP email sending (Gmail app password)
```

### **Critical Methods by File**

#### **daily_refresh.php**
- `$purgeVendorData()` - Delete old vendor data (images, vehicles, attributes)
- CLI argument parsing - `--vendor`, `--force`, `--help`

#### **CarScraper.php** (Base Class)
- `fetchUrl($url)` - cURL HTTP client with SSL handling
- `parseListingPage($html)` - Extract vehicle cards from listing
- `parseVehicleCard($card, $xpath)` - Extract: title, price, mileage, URL, image
- `enrichWithDetailPages($vehicles)` - Fetch detail pages for each vehicle
- `extractVehicleDetails($html)` - Extract: VRM, colour, specs, images (ALL)
- `extractFullDescription($html)` - Extract full description text
- `cleanText($text)` - 7-step UTF-8 garbage removal
- `cleanImageUrls($urls)` - Deduplicate & validate image URLs
- `calculateDataHash($vehicle)` - SHA256 hash for change detection

#### **CarSafariScraper.php** (Child Class)
- `runWithCarSafari()` - Main orchestration method
- `saveVehiclesToCarSafari($vehicles)` - Loop through vehicles, save to DB
- `saveVehicleAttributes($regNo, $vehicle)` - Create/update gyc_vehicle_attribute
- `getCarCheckData($regNo)` - Fetch BHP/MPG/CO2 from CarCheck.co.uk
- `saveVehicleInfoWithChangeDetection()` - Smart upsert with hash comparison
- `downloadAndSaveImages($imageUrls, $vehicleId)` - Save to gyc_product_images
- `autoPublishVehicles($activeIds)` - Set active_status = 1
- `deactivateInvalidAndStaleVehicles($activeIds)` - Cleanup stale records
- `saveJsonSnapshot()` - Export to data/vehicles.json with rotation
- `resolveMakeId($make)` - Cached lookup for gyc_make.id
- `resolveColorId($color)` - Cached lookup for gyc_vehicle_color.id
- `extractMakeFromTitle($title)` - Parse "Volkswagen" from "2016 Volkswagen Polo..."
- `extractModelFromTitle($title)` - Parse "Polo" after removing make/year/trim
- `isValidVrm($regNo)` - Regex validation for UK registration format

#### **StatisticsManager.php**
- `initializeStatistics($vendorId)` - Start metrics tracking
- `recordVehicleAction($action, $data)` - Track: found, inserted, updated, skipped
- `recordError($type, $message, $severity)` - Log errors
- `recordImageStatistics($stored, $failed)` - Track image downloads
- `finalizeStatistics($status, $error)` - Calculate duration, success rate
- `saveStatistics()` - INSERT into scraper_statistics table

#### **mail_alert.php**
- `send_scrape_alert($vendorId, $stats, $success, $note)` - Main function
- `smtp_send($host, $port, $user, $pass, ...)` - Custom SMTP client
- `extract_email($address)` - Parse "Name <email>" format

---

## 🌐 **Data Sources**

### **1. systonautosltd.co.uk (Primary Source)**
**URL**: `https://systonautosltd.co.uk/vehicle/search/min_price/0/order/price/dir/DESC/limit/250/`

**Data Extracted**:
- **Listing Page** (250 vehicles max):
  - Vehicle URLs (detail page links)
  - Basic title, price, mileage
  - First thumbnail image

- **Detail Pages** (71 vehicles currently):
  - **VRM** (UK registration): `WP66UEX` ⭐ **CRITICAL**
  - Full description (cleaned UTF-8 text)
  - All images (carousel: 33 images per vehicle avg)
  - Specs: colour, transmission, fuel, body, doors, drive, engine_size
  - Price (confirmed from detail page)
  - Mileage (confirmed from detail page)

**Parsing Strategy**:
- DOMDocument + XPath for HTML parsing
- Regex for numeric extraction (price: £5,490 → 5490.00)
- Whitelist validation for colours (50+ valid colors)
- Consecutive failure detection (abort after 5 failures)

---

### **2. carcheck.co.uk (Enhancement Source)**
**URL Pattern**: `https://www.carcheck.co.uk/{make}/{vrm}`
**Example**: `https://www.carcheck.co.uk/volkswagen/WP66UEX`

**Data Extracted**:
- **Performance**:
  - BHP (brake horsepower): 150
  - Top speed: 115 mph

- **Efficiency**:
  - MPG (miles per gallon): 52.3
  - CO2 emissions: 120 g/km

- **Physical**:
  - Engine size: 1000cc
  - Weight: 1200 kg
  - Dimensions: 1682mm width

- **Specs (confirmation)**:
  - Fuel type: Petrol/Diesel/Hybrid/Electric
  - Transmission: Manual/Automatic
  - Colour: Exterior paint name

**Integration Logic**:
1. Extract VRM from systonautosltd detail page
2. Determine make from vehicle title
3. Build CarCheck URL: `/{make}/{vrm}`
4. Fetch with 1s delay (rate limiting)
5. Parse HTML with regex patterns
6. Store as JSON in `gyc_vehicle_attribute.trim` field
7. Update main specs if more accurate than dealer data

**Error Handling**:
- Timeout: counted in `errors` stat (currently 2 per run)
- No data found: return null, use dealer data only
- Invalid VRM: skip CarCheck lookup entirely

---

## 🗄️ **Database Operations**

### **Tables Modified**

#### **1. gyc_vehicle_attribute** (Specs/Model Data)
**Purpose**: Canonical vehicle specifications (shared across multiple listings)

**Operations**:
```sql
-- Find existing attribute by VRM or model+year
SELECT id FROM gyc_vehicle_attribute
WHERE reg_no = 'WP66UEX'
   OR (model = 'Polo' AND year = 2016);

-- Create new if not found
INSERT INTO gyc_vehicle_attribute (
  make_id, category_id, model, year,
  engine_size, fuel_type, transmission, body_style,
  gearbox, trim, derivative, active_status, created_at
) VALUES (123, 1, 'Polo', 2016, 1000, 'Petrol', 'Manual', 'Hatchback',
          'Manual', '{"bhp":150,"mpg":52.3,"co2":120}', '', 1, NOW());

-- Update with CarCheck enrichment
UPDATE gyc_vehicle_attribute
SET make_id = 123,
    model = 'Polo',
    engine_size = 1000,
    fuel_type = 'Petrol',
    transmission = 'Manual',
    trim = '{"bhp":150,"mpg":52.3,"co2":120,"top_speed":115,"weight":1200}',
    updated_at = NOW()
WHERE id = 456;
```

**Key Fields**:
- `make_id` (FK → gyc_make.id): Cached lookup, ~5 makes for 71 vehicles
- `trim` (JSON): CarCheck data storage (`{"bhp":150,"mpg":52.3,"co2":120}`)
- `active_status`: 1 = active, 0 = inactive

---

#### **2. gyc_vehicle_info** (Main Listing Data)
**Purpose**: Individual vehicle listing (price, mileage, description)

**Operations**:
```sql
-- Smart upsert with change detection
INSERT INTO gyc_vehicle_info (
  attr_id, vendor_id, reg_no, engine_no,
  selling_price, regular_price, mileage,
  color, color_id, manufacturer_color_id,
  description, vehicle_url, data_hash,
  seats, doors, drive_system, v_condition,
  active_status, publish_date, created_at, updated_at
) VALUES (
  456, 432, 'WP66UEX', 'WP66UEX',
  5490.00, 5490.00, 42523.0,
  'Silver', 789, 789,
  'Full description...', 'https://systonautosltd.co.uk/vehicle/polo-12345/',
  'a1b2c3d4...', 5, 5, 'Front', 'USED',
  '1', NOW(), NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
  attr_id = VALUES(attr_id),
  selling_price = VALUES(selling_price),
  mileage = VALUES(mileage),
  color_id = VALUES(color_id),
  manufacturer_color_id = VALUES(manufacturer_color_id),
  description = VALUES(description),
  data_hash = VALUES(data_hash),
  updated_at = NOW();

-- Auto-publish scraped vehicles
UPDATE gyc_vehicle_info
SET active_status = '1', publish_date = NOW()
WHERE id IN (12345, 12346, ..., 12413);

-- Deactivate stale vehicles (not in current scrape)
UPDATE gyc_vehicle_info
SET active_status = '0'
WHERE vendor_id = 432
  AND id NOT IN (12345, 12346, ..., 12413)
  AND active_status IN ('1', '2');

-- Deactivate invalid VRM records (URL slugs)
UPDATE gyc_vehicle_info
SET active_status = '4'
WHERE vendor_id = 432
  AND reg_no REGEXP '^[a-z0-9-]+$'
  AND active_status != '4';
```

**Key Fields**:
- `reg_no` (PK): UK VRM like "WP66UEX" (NOT URL slug "polo-12345")
- `engine_no`: VIN/chassis fallback to reg_no if missing
- `vendor_id`: 432 = systonautosltd
- `color_id`, `manufacturer_color_id` (FK → gyc_vehicle_color.id): Cached lookup
- `data_hash`: SHA256 for change detection (skip unchanged vehicles)
- `active_status`: 0=pending, 1=waiting, 2=published, 3=sold, 4=blocked

---

#### **3. gyc_product_images** (Image Storage)
**Purpose**: Multiple images per vehicle (ordered by serial)

**Operations**:
```sql
-- Check if image already exists (avoid duplicates)
SELECT serial FROM gyc_product_images
WHERE vechicle_info_id = 12345
  AND file_name = 'https://systonautosltd.co.uk/images/image1.jpg';

-- Get next serial number
SELECT IFNULL(MAX(serial), 0) + 1 AS next_serial
FROM gyc_product_images
WHERE vechicle_info_id = 12345;

-- Insert new image
INSERT INTO gyc_product_images (
  vechicle_info_id, file_name, serial, created_at
) VALUES (
  12345, 'https://systonautosltd.co.uk/images/image1.jpg', 1, NOW()
);

-- Delete old vendor images (during purge)
DELETE FROM gyc_product_images
WHERE vechicle_info_id IN (
  SELECT id FROM gyc_vehicle_info WHERE vendor_id = 432
);
```

**Key Fields**:
- `vechicle_info_id` (FK → gyc_vehicle_info.id): Links to main vehicle record
- `file_name`: Full image URL (NOT local filename)
- `serial`: Ordering (1, 2, 3, ... 33 for 33 images)

---

#### **4. scraper_statistics** (Metrics Tracking)
**Purpose**: Store performance metrics for each scrape run

**Operations**:
```sql
-- Insert run statistics
INSERT INTO scraper_statistics (
  vendor_id, run_date, status,
  vehicles_found, vehicles_inserted, vehicles_updated,
  vehicles_skipped, vehicles_failed, images_stored,
  requests_made, duration_minutes, stats_json, created_at
) VALUES (
  432, '2025-12-18', 'completed',
  71, 68, 0, 0, 2, 2244,
  142, 12.45,
  '{"hash_skips":0,"db_hits":71,"carcheck_timeouts":2}',
  NOW()
);

-- Query recent runs
SELECT * FROM scraper_statistics
WHERE vendor_id = 432
ORDER BY created_at DESC
LIMIT 5;
```

**Key Fields**:
- `vendor_id`: 432
- `status`: 'completed', 'failed', 'partial'
- `duration_minutes`: Total execution time
- `stats_json`: Additional metadata (JSON format)

---

### **Cleanup Operations** (During Purge Phase)
```sql
-- 1. Delete vendor images
DELETE FROM gyc_product_images
WHERE vechicle_info_id IN (
  SELECT id FROM gyc_vehicle_info WHERE vendor_id = 432
);
-- Result: Deleted 2244 images

-- 2. Delete vendor vehicles
DELETE FROM gyc_vehicle_info
WHERE vendor_id = 432;
-- Result: Deleted 68 vehicles

-- 3. Clean orphaned attributes
DELETE FROM gyc_vehicle_attribute
WHERE id NOT IN (SELECT DISTINCT attr_id FROM gyc_vehicle_info WHERE attr_id IS NOT NULL)
  AND active_status = 0;
-- Result: Cleaned 0 orphaned attributes

-- 4. Delete orphaned images (if vehicle deleted externally)
DELETE FROM gyc_product_images
WHERE vechicle_info_id NOT IN (SELECT id FROM gyc_vehicle_info);
```

---

## 🚨 **Error Handling & Statistics**

### **Error Types Tracked**
1. **Invalid VRM**: URL slugs like "polo-12345" → skip vehicle, errors++
2. **CarCheck Timeout**: No response from CarCheck API → use dealer data only, errors++
3. **Detail Page Failure**: HTTP error fetching vehicle detail → abort after 5 consecutive failures
4. **Missing Data**: No VRM, no price, no title → skip vehicle
5. **Database Error**: INSERT/UPDATE failure → log error, stats updated

### **Change Detection (Smart Skip)**
**Algorithm**: SHA256 hash comparison
- **Hash Fields**: reg_no, selling_price, mileage, color, description
- **Storage**: `gyc_vehicle_info.data_hash` column
- **Logic**:
  ```php
  $newHash = hash('sha256', implode('|', [
      $vehicle['reg_no'],
      $vehicle['selling_price'],
      $vehicle['mileage'],
      $vehicle['color'],
      $vehicle['description']
  ]));

  $storedHash = getStoredDataHash($regNo);  // SELECT data_hash WHERE reg_no = ?

  if ($newHash === $storedHash) {
      return ['action' => 'skipped', 'vehicleId' => $existingId];
  }
  ```
- **Result**: 100% skip rate for unchanged vehicles (e.g., 0 skipped in fresh run)

### **Performance Metrics**
- **Latest Run (Dec 18, 2025)**:
  - Found: 71 vehicles
  - Inserted: 68 vehicles (new)
  - Updated: 0 vehicles (no changes detected)
  - Skipped: 0 vehicles (all new data)
  - Errors: 2 (CarCheck timeouts)
  - Images: 2244 stored (33 avg per vehicle)
  - Duration: 12.45 minutes (745 seconds)
  - Success Rate: 95.8% (68/71)

- **Optimization Features**:
  - Cached lookups: make_id, color_id (99% hit rate)
  - Batch operations: Bulk INSERT for images
  - Smart change detection: Hash-based skip
  - Consecutive failure detection: Abort after 5 errors

### **Logging**
- **File**: `logs/scraper_YYYY-MM-DD.log`
- **Rotation**: Auto-cleanup logs older than 7 days
- **Sample Output**:
  ```
  [2025-12-18 14:25:10] Starting CarSafari scrape...
  [2025-12-18 14:25:12] Fetching listing page...
  [2025-12-18 14:25:15] Found 71 vehicles
  [2025-12-18 14:25:17] Fetching detail pages for full descriptions...
  [2025-12-18 14:25:19]   Processing 1/71: polo-12345
  [2025-12-18 14:25:21]     Found VRM: WP66UEX
  [2025-12-18 14:25:22]     Found 33 images (cleaned to: 33)
  [2025-12-18 14:25:23]     Found colour: Silver
  [2025-12-18 14:25:24]   Fetching CarCheck data: https://www.carcheck.co.uk/volkswagen/WP66UEX
  [2025-12-18 14:25:26]     CarCheck data: bhp=150, mpg=52.3, co2=120
  [2025-12-18 14:25:28]   [INSERTED] Vehicle WP66UEX saved (ID: 12345)
  ...
  [2025-12-18 14:37:55] CarSafari scrape completed successfully!
  [2025-12-18 14:37:55] Stats: {"found":71,"inserted":68,"updated":0,"skipped":0,"errors":2,"images_stored":2244}
  ```

---

## 📊 **Summary Statistics**

### **Execution Time Breakdown**
| Phase | Duration | % of Total |
|-------|----------|------------|
| Initialization | 2s | 0.3% |
| Data Purge | 5s | 0.7% |
| Listing Fetch | 3s | 0.4% |
| Detail Scrape | 620s | 83.2% |
| CarCheck Enrichment | 71s | 9.5% |
| Database Save | 35s | 4.7% |
| Image Processing | 10s | 1.3% |
| JSON Export | 2s | 0.3% |
| **TOTAL** | **745s (12.45 min)** | **100%** |

### **Database Impact**
| Operation | Count |
|-----------|-------|
| DELETE (images) | 2244 |
| DELETE (vehicles) | 68 |
| INSERT (attributes) | 68 |
| UPSERT (vehicle_info) | 68 |
| INSERT (images) | 2244 |
| UPDATE (auto-publish) | 68 |
| SELECT (make lookup) | 5 (cached) |
| SELECT (color lookup) | 8 (cached) |
| **TOTAL QUERIES** | **~2,770** |

### **Network Requests**
| Source | Requests | Avg Response Time |
|--------|----------|-------------------|
| systonautosltd (listing) | 1 | 3.2s |
| systonautosltd (details) | 71 | 8.7s |
| CarCheck API | 71 | 1.0s |
| **TOTAL** | **143** | **5.2s avg** |

---

## 🔧 **Configuration**

### **Key Settings** (config.php)
```php
'scraper' => [
    'listing_url' => 'https://systonautosltd.co.uk/vehicle/search/.../limit/250/',
    'request_delay' => 1.5,    // Politeness delay between requests
    'timeout' => 30,           // HTTP timeout in seconds
    'verify_ssl' => false,     // Disable for WAMP localhost
    'fetch_detail_pages' => true,  // Enable full detail scraping
],

'database' => [
    'host' => 'localhost',
    'dbname' => 'tst-car',
    'username' => 'root',
    'password' => '',
],
```

### **Vendor Configuration**
- **Vendor ID**: 432 (systonautosltd)
- **Default Status**: active_status = '1' (waiting/approved)
- **Purge Before Scrape**: Yes (delete old data first)

---

## 📚 **Related Documentation**
- [README.md](../README.md) - Quick start guide & deployment
- [vehicle_db.md](vehicle_db.md) - Database schema reference
- [live_DB_migrate.md](../live_DB_migrate.md) - Production migration steps
- [CLAUDE.md](../CLAUDE.md) - Development context & history
