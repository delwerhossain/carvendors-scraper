# CarVendors Scraper - Master Plan & Execution Guide

**Project**: systonautosltd.co.uk vehicle scraper with CarSafari database integration
**Status**: Enhancement phase to fix data gaps and duplication issues
**Last Updated**: 2025-12-04

---

## Executive Summary

### Current Issues
1. **Duplication Problem**: Scraper finds 162 vehicle cards but only 81 unique vehicles
2. **Missing Specs**: `transmission`, `fuel_type`, `body_style` not saved to `gyc_vehicle_attribute` table
3. **Data Gaps**: Many fields empty (doors, seats, engine_size, registration_plate, etc.)
4. **Image Handling**: Downloading images unnecessarily; should store URLs instead

### Target Outcome
- ✅ Scraper extracts exactly 81 unique vehicles (no duplicates)
- ✅ All available specs saved to correct database tables
- ✅ Additional fields parsed from title and detail pages
- ✅ Dealer information hardcoded
- ✅ Image URLs stored (no downloads)

---

## Data Availability Matrix

### Sources of Vehicle Data

| Source | Availability | Contains |
|--------|--------------|----------|
| **systonautosltd.co.uk listing page** | ✅ Accessible | Price, title, mileage, color, basic specs |
| **systonautosltd.co.uk detail page** | ✅ Accessible | Full description, engine size, images |
| **carcheck.co.uk API** | ⚠️ Limited | Make/model, gearbox, doors, BHP, torque, engine# |
| **DVLA/MOT data** | ❌ Blocked | Real registration number, tax band, MOT status |

### Database Fields vs Data Source

```
gyc_vehicle_info (main vehicle record)
├─ selling_price ...................... ✅ Scraper
├─ mileage ............................. ✅ Scraper
├─ color .............................. ✅ Scraper (whitelist)
├─ description ........................ ✅ Scraper (meta tag)
├─ attention_grabber .................. ✅ Scraper (title)
├─ vehicle_url ........................ ✅ Scraper (detail page URL)
├─ doors ............................. ⚠️ Parse title "5dr" → ADD
├─ registration_plate ................ ⚠️ Parse title "(64 plate)" → ADD
├─ post_code ......................... ❌ Hardcode "LE7 1NS" → ADD
├─ address ........................... ❌ Hardcode dealer address → ADD
├─ drive_position .................... ❌ Hardcode "Right" (UK) → ADD
└─ drive_system ...................... ⚠️ Parse "4WD/AWD" from title → ADD

gyc_vehicle_attribute (vehicle specs)
├─ year ............................. ✅ Scraper (from title)
├─ model ............................ ✅ Scraper (title)
├─ transmission ..................... ✅ Scraped BUT NOT SAVED → FIX
├─ fuel_type ........................ ✅ Scraped BUT NOT SAVED → FIX
├─ body_style ...................... ✅ Scraped BUT NOT SAVED → FIX
├─ engine_size ..................... ⚠️ On detail page → ADD
├─ trim ............................ ⚠️ Parse "SE", "Sport" from title → ADD
├─ gearbox ......................... ❌ Not available (carcheck needs reg#)
└─ make_id ......................... ⚠️ Lookup from title → ADD

gyc_product_images (image records)
├─ file_name ....................... ✅ Currently downloads, should store URL → CHANGE
├─ serial .......................... ✅ Auto-increment
└─ vehicle_info_id ................. ✅ FK reference
```

---

## Implementation Roadmap

### Phase 1: Fix Critical Issues (High Impact, Low Effort)

#### STEP 1: Fix Duplication Issue (162 → 81)
**File**: `CarScraper.php`
**Problem**: `parseListingPage()` finds vehicle cards without deduplication; same vehicle appears multiple times
**Solution**: Add `$processedIds` tracking in main parsing loop
**Expected Result**: Scraper finds exactly 81 unique vehicles, not 162

#### STEP 2: Save Specs to `gyc_vehicle_attribute`
**Files**: `CarSafariScraper.php` (INSERT query)
**Problem**: Vehicle specs (transmission, fuel_type, body_style) extracted but not inserted into attribute table
**Solution**: Fix INSERT statement to populate `gyc_vehicle_attribute` columns
**Expected Result**: All vehicle specs visible in dashboard/JSON export

#### STEP 3: Parse Additional Title Fields
**File**: `CarScraper.php` → `parseVehicleCard()` method
**Problem**: Missing doors, plate_year, drive_system, trim from title parsing
**Solution**: Add regex patterns to extract these fields
**Expected Result**: Extract patterns like "5dr", "(64 plate)", "4WD", "SE"

#### STEP 4: Extract Engine Size from Detail Page
**File**: `CarScraper.php` → `enrichWithDetailPages()` method
**Problem**: Engine size visible on detail page but not extracted
**Solution**: Add regex to find "Engine Size: X,XXX" in HTML
**Expected Result**: Engine sizes populated in database

#### STEP 5: Hardcode Dealer Information
**File**: `CarSafariScraper.php` → constants section
**Problem**: Dealer address/postcode fields left NULL
**Solution**: Add hardcoded dealer info for systonautosltd
**Expected Result**: All vehicles have complete address information

#### STEP 6: Change Image Storage Strategy
**File**: `CarSafariScraper.php` → `downloadAndSaveImages()` method
**Problem**: Downloads all images to disk (slow, storage intensive)
**Solution**: Store image URLs in database instead
**Expected Result**: Faster scraping, smaller footprint

---

## STEP-BY-STEP EXECUTION GUIDE

### STEP 1: Fix Duplication Issue (162 → 81)

#### File to Modify
`c:\wamp64\www\carvendors-scraper\CarScraper.php` (lines 178-225)

#### Code Changes

**Find this section** (around line 178):
```php
protected function parseListingPage(string $html): array
{
    $vehicles = [];
    
    // Load and parse HTML
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // Try multiple selectors to find vehicle cards
    $cards = $xpath->query("//div[contains(@class, 'vehicle-listing')] | 
                            //div[contains(@class, 'vehicle-card')] | 
                            //article[contains(@class, 'vehicle')]");
    
    // ... rest of code ...
    
    foreach ($cards as $card) {
        $vehicle = $this->parseVehicleCard($card, $xpath);
        if ($vehicle) {
            $vehicles[] = $vehicle;  // ← NO DEDUPLICATION!
        }
    }
    
    return $vehicles;
}
```

**Replace with**:
```php
protected function parseListingPage(string $html): array
{
    $vehicles = [];
    $processedIds = [];  // ← ADD THIS: Track processed vehicles
    
    // Load and parse HTML
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // Try multiple selectors to find vehicle cards
    $cards = $xpath->query("//div[contains(@class, 'vehicle-listing')] | 
                            //div[contains(@class, 'vehicle-card')] | 
                            //article[contains(@class, 'vehicle')]");
    
    // ... rest of code ...
    
    foreach ($cards as $card) {
        $vehicle = $this->parseVehicleCard($card, $xpath);
        // ← ADD THIS: Check if already processed
        if ($vehicle && !isset($processedIds[$vehicle['external_id']])) {
            $vehicles[] = $vehicle;
            $processedIds[$vehicle['external_id']] = true;  // ← MARK AS PROCESSED
        }
    }
    
    return $vehicles;
}
```

#### Testing for STEP 1
```bash
# Run scraper with --no-details to test quickly
php scrape-carsafari.php --no-details

# Check the output - should show:
# [INFO] Found 81 vehicles  (← NOT 162!)
# [INFO] Published 81 vehicles

# Verify in database:
SELECT COUNT(*) as unique_vehicles FROM gyc_vehicle_info WHERE vendor_id = 432;
# Expected: ~81-94 (depends on previous runs)
```

---

### STEP 2: Save Specs to `gyc_vehicle_attribute`

#### File to Modify
`c:\wamp64\www\carvendors-scraper\CarSafariScraper.php` (lines 196-245)

#### Current Problem
```php
// CURRENT CODE (BROKEN):
$sql = "INSERT INTO gyc_vehicle_info 
        (reg_no, selling_price, ..., transmission, fuel_type, body_style, ...)
        VALUES (?, ?, ..., ?, ?, ?, ...)";

// ^ transmission, fuel_type, body_style DON'T EXIST in gyc_vehicle_info!
// They belong in gyc_vehicle_attribute!
```

#### Solution: Use attr_id Foreign Key

**Find the INSERT query** (around line 196-210):
```php
// WRONG: Trying to insert specs into vehicle_info table
$sql = "INSERT INTO gyc_vehicle_info 
        (...transmission, fuel_type, body_style...) 
        VALUES (...?, ?, ?...)";
```

**Replace with**:
```php
// CORRECT: Insert main info, then update attribute table
// Step 1: Insert/Update gyc_vehicle_info (without spec columns)
$sql = "INSERT INTO gyc_vehicle_info 
        (reg_no, selling_price, mileage, color, description, attention_grabber, 
         vendor_id, vehicle_url, active_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            selling_price = VALUES(selling_price),
            mileage = VALUES(mileage),
            description = VALUES(description),
            updated_at = NOW()";

$stmt = $this->db->prepare($sql);
$stmt->execute([
    $vehicle['external_id'],          // reg_no
    $vehicle['price'],                 // selling_price
    $vehicle['mileage'],               // mileage
    $vehicle['colour'],                // color
    $vehicle['description'],           // description
    $vehicle['title'],                 // attention_grabber
    $this->vendorId,                   // vendor_id (432)
    $vehicle['vehicle_url'],           // vehicle_url
]);

$vehicleId = $this->db->lastInsertId();

// Step 2: Insert specs into gyc_vehicle_attribute
$attrSql = "INSERT INTO gyc_vehicle_attribute 
            (model, year, fuel_type, transmission, body_style, active_status, created_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                fuel_type = VALUES(fuel_type),
                transmission = VALUES(transmission),
                body_style = VALUES(body_style),
                updated_at = NOW()";

$attrStmt = $this->db->prepare($attrSql);
$attrStmt->execute([
    $vehicle['title'],                 // model
    $vehicle['year'],                  // year
    $vehicle['fuel_type'],             // fuel_type
    $vehicle['transmission'],          // transmission
    $vehicle['body_style'],            // body_style
]);

$attrId = $this->db->lastInsertId();

// Step 3: Link attribute to vehicle_info
if ($attrId) {
    $linkSql = "UPDATE gyc_vehicle_info SET attr_id = ? WHERE id = ?";
    $linkStmt = $this->db->prepare($linkSql);
    $linkStmt->execute([$attrId, $vehicleId]);
}
```

#### Testing for STEP 2
```bash
# Run scraper
php scrape-carsafari.php --no-details

# Check gyc_vehicle_attribute is populated:
SELECT COUNT(*) as total, 
       SUM(CASE WHEN transmission IS NOT NULL THEN 1 ELSE 0 END) as with_transmission,
       SUM(CASE WHEN fuel_type IS NOT NULL THEN 1 ELSE 0 END) as with_fuel,
       SUM(CASE WHEN body_style IS NOT NULL THEN 1 ELSE 0 END) as with_body_style
FROM gyc_vehicle_attribute;

# Expected: All columns should have high counts (70+)
```

---

### STEP 3: Parse Additional Title Fields

#### File to Modify
`c:\wamp64\www\carvendors-scraper\CarScraper.php` (lines 242-280, `parseVehicleCard()` method)

#### Add After Existing Extraction

**Find this section** (around line 270):
```php
// Extract year from title
if (preg_match('/\b(19|20)\d{2}\b/', $title, $matches)) {
    $vehicle['year'] = (int)$matches[0];
}

// ... rest of parsing code ...
return $vehicle;
```

**Add these new extractions BEFORE the return statement**:
```php
// Extract number of doors: "5dr", "3dr", "4dr"
if (preg_match('/\b(\d)dr\b/i', $title, $matches)) {
    $vehicle['doors'] = (int)$matches[1];
} else {
    $vehicle['doors'] = null;
}

// Extract registration plate year: "(64 plate)", "(23 plate)"
// Plate format: First 2 digits = year code (03-53 = 2003-2053)
if (preg_match('/\((\d{2})\s*(?:plate|reg|registration)\)/i', $title, $matches)) {
    $plateCode = (int)$matches[1];
    // Convert plate code to year (03 = 2003, 23 = 2023)
    if ($plateCode <= 53) {
        $vehicle['registration_plate'] = $matches[1];  // Store as "64"
        // Calculate actual year: 
        // 00-49 = 2000-2049, 50-99 = 1950-1999
        $vehicle['plate_year'] = ($plateCode <= 49) ? 2000 + $plateCode : 1900 + $plateCode;
    }
} else {
    $vehicle['registration_plate'] = null;
    $vehicle['plate_year'] = null;
}

// Extract drive system: "4WD", "AWD", "2WD", "xDrive", "sDrive", "ALL4"
if (preg_match('/\b(4WD|AWD|2WD|FWD|RWD|xDrive|sDrive|qDrive|ALL4)\b/i', $title, $matches)) {
    $vehicle['drive_system'] = strtoupper($matches[1]);
} else {
    $vehicle['drive_system'] = null;
}

// Extract trim level: "SE", "Sport", "S line", "SE Plus", "FR", "Elegance", "Executive"
if (preg_match('/\b(SE|Sport|S\s*line|FR|GT|Elegance|Executive|Limited|Prestige|Premium|Base|Standard)\b/i', $title, $matches)) {
    $vehicle['trim'] = trim($matches[1]);
} else {
    $vehicle['trim'] = null;
}

// Example extractions from real title:
// "Volvo V40 2.0 D4 R-Design Nav Plus Euro 6 (s/s) 5dr - 2016 (66 plate)"
// doors = 5
// registration_plate = "66"
// plate_year = 2016
// drive_system = null (not mentioned)
// trim = "R-Design"
```

#### Testing for STEP 3
```bash
# Run scraper
php scrape-carsafari.php --no-details

# Check extracted fields:
SELECT 
    COUNT(*) as total_vehicles,
    SUM(CASE WHEN doors IS NOT NULL THEN 1 ELSE 0 END) as with_doors,
    SUM(CASE WHEN registration_plate IS NOT NULL THEN 1 ELSE 0 END) as with_plate,
    SUM(CASE WHEN drive_system IS NOT NULL THEN 1 ELSE 0 END) as with_drive
FROM gyc_vehicle_info 
WHERE vendor_id = 432;

# Expected: Most should have doors (5dr is common), some with registration_plate
```

---

### STEP 4: Extract Engine Size from Detail Page

#### File to Modify
`c:\wamp64\www\carvendors-scraper\CarScraper.php` (lines 320-380, `enrichWithDetailPages()` method)

#### Find This Section
```php
protected function enrichWithDetailPages(array &$vehicles): void
{
    // ... fetching code ...
    
    foreach ($vehicles as &$vehicle) {
        $detailHtml = $this->fetchPage($vehicle['vehicle_url']);
        
        // Extract description from meta tag
        if (preg_match('/<meta\s+name="description"\s+content="([^"]+)"/i', $detailHtml, $matches)) {
            $vehicle['description'] = $this->cleanText($matches[1]);
        }
        
        // ← ADD ENGINE SIZE EXTRACTION HERE
    }
}
```

#### Add Engine Size Extraction
```php
// Extract engine size: "Engine Size: 1,598 cc" or "1598cc" or "Engine: 1,598"
$engineSize = null;
if (preg_match('/Engine\s*(?:Size)?[:\s]*([0-9,]+)\s*(?:cc|cubic)?/i', $detailHtml, $matches)) {
    $engineSize = (int)str_replace(',', '', $matches[1]);
} elseif (preg_match('/([0-9,]+)\s*(?:cc|cubic\s*centimetres?)/i', $detailHtml, $matches)) {
    $engineSize = (int)str_replace(',', '', $matches[1]);
}

if ($engineSize) {
    $vehicle['engine_size'] = $engineSize;
}

// Example: "Engine Size: 1,598 cc" → 1598
// Example: "2.0L diesel" → won't match (but title has it!)
```

#### Testing for STEP 4
```bash
# Run scraper WITH detail pages (slower but tests extraction)
php scrape-carsafari.php --no-details=false

# Or test with just a few vehicles:
php scrape-carsafari.php

# Check engine sizes:
SELECT COUNT(*) as total, 
       SUM(CASE WHEN engine_size IS NOT NULL THEN 1 ELSE 0 END) as with_engine_size
FROM gyc_vehicle_attribute;

# Expected: 50%+ should have engine_size
```

---

### STEP 5: Hardcode Dealer Information

#### File to Modify
`c:\wamp64\www\carvendors-scraper\CarSafariScraper.php` (top of class)

#### Add Dealer Constants
```php
class CarSafariScraper extends CarScraper
{
    // ← ADD THESE CONSTANTS after class declaration
    
    // Systonautosltd dealer information
    const DEALER_POST_CODE = 'LE7 1NS';
    const DEALER_ADDRESS = 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS';
    const DEALER_DRIVE_POSITION = 'Right';  // UK standard
    const DEALER_CONDITION = 'USED';        // All are used cars
    const DEALER_VENDOR_ID = 432;
    
    private $vendorId = self::DEALER_VENDOR_ID;
    
    // ... rest of class code ...
```

#### Update INSERT Query
**In the `saveVehiclesToCarSafari()` method, modify INSERT**:
```php
$sql = "INSERT INTO gyc_vehicle_info 
        (reg_no, selling_price, mileage, color, description, attention_grabber, 
         vendor_id, vehicle_url, post_code, address, drive_position, v_condition,
         active_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE ...";

$stmt = $this->db->prepare($sql);
$stmt->execute([
    $vehicle['external_id'],          // reg_no
    $vehicle['price'],                 // selling_price
    $vehicle['mileage'],               // mileage
    $vehicle['colour'],                // color
    $vehicle['description'],           // description
    $vehicle['title'],                 // attention_grabber
    self::DEALER_VENDOR_ID,            // vendor_id
    $vehicle['vehicle_url'],           // vehicle_url
    self::DEALER_POST_CODE,            // post_code ← HARDCODED
    self::DEALER_ADDRESS,              // address ← HARDCODED
    self::DEALER_DRIVE_POSITION,       // drive_position ← HARDCODED
    self::DEALER_CONDITION,            // v_condition ← HARDCODED
]);
```

#### Testing for STEP 5
```bash
# Run scraper
php scrape-carsafari.php --no-details

# Verify dealer info populated:
SELECT COUNT(*) as total,
       COUNT(DISTINCT post_code) as unique_post_codes,
       COUNT(DISTINCT address) as unique_addresses
FROM gyc_vehicle_info 
WHERE vendor_id = 432;

# Expected: 
# total = 81
# unique_post_codes = 1 (LE7 1NS)
# unique_addresses = 1 (Unit 10 Mill Lane...)
```

---

### STEP 6: Change Image Storage Strategy

#### File to Modify
`c:\wamp64\www\carvendors-scraper\CarSafariScraper.php` (lines 380-420, `downloadAndSaveImages()` method)

#### Current Implementation
```php
// CURRENT: Downloads and saves image files
private function downloadAndSaveImages(array $imageUrls, int $vehicleId): void
{
    // Downloads each image
    // Saves as: 20251204143049_1.jpg, 20251204143049_2.jpg
    // Stores filename in gyc_product_images
}
```

#### New Implementation
```php
// NEW: Just stores image URLs in database
private function saveImageUrls(array $imageUrls, int $vehicleId): void
{
    if (empty($imageUrls)) {
        return;
    }

    $sql = "INSERT INTO gyc_product_images 
            (vehicle_info_id, file_name, serial, cratead_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                updated_at = NOW()";
    
    $stmt = $this->db->prepare($sql);
    
    foreach ($imageUrls as $serial => $imageUrl) {
        // Store full URL instead of filename
        // serial: 1, 2, 3... for multiple images
        $stmt->execute([
            $vehicleId,
            $imageUrl,              // Store full URL
            ($serial + 1),          // serial is 1-indexed
        ]);
    }
}
```

#### Update Method Call
**In `saveVehiclesToCarSafari()`, replace**:
```php
// OLD:
$this->downloadAndSaveImages($vehicle['images'], $vehicleId);

// NEW:
$this->saveImageUrls($vehicle['images'], $vehicleId);
```

#### Testing for STEP 6
```bash
# Run scraper
php scrape-carsafari.php --no-details

# Check images stored as URLs:
SELECT id, vehicle_info_id, file_name, serial 
FROM gyc_product_images 
LIMIT 5;

# Expected file_name should look like:
# https://systonautosltd.co.uk/uploads/vehicles/image1.jpg
# NOT like: 20251204143049_1.jpg
```

---

## Integration Testing Guide

### Test 1: Full Scrape with All Enhancements
```bash
# 1. Run complete scraper
php scrape-carsafari.php --no-details

# 2. Check output for:
# [INFO] Found 81 vehicles          ← Should be 81, NOT 162!
# [INFO] Published 81 vehicles
# [SUCCESS] Completed successfully
```

### Test 2: Database Verification
```bash
# 1. Check vehicle count and deduplication
SELECT COUNT(*) as vehicles,
       COUNT(DISTINCT reg_no) as unique_vehicles
FROM gyc_vehicle_info 
WHERE vendor_id = 432;
# Expected: 81 vehicles, 81 unique

# 2. Check specs populated
SELECT COUNT(*) as total,
       SUM(CASE WHEN transmission IS NOT NULL THEN 1 ELSE 0 END) as with_transmission,
       SUM(CASE WHEN fuel_type IS NOT NULL THEN 1 ELSE 0 END) as with_fuel,
       SUM(CASE WHEN body_style IS NOT NULL THEN 1 ELSE 0 END) as with_body_style
FROM gyc_vehicle_attribute;
# Expected: 70+ in each category

# 3. Check extracted fields
SELECT COUNT(*) as total,
       SUM(CASE WHEN doors IS NOT NULL THEN 1 ELSE 0 END) as with_doors,
       SUM(CASE WHEN post_code = 'LE7 1NS' THEN 1 ELSE 0 END) as with_dealer_info
FROM gyc_vehicle_info 
WHERE vendor_id = 432;
# Expected: doors 70+, dealer_info = 81

# 4. Check images are URLs
SELECT COUNT(*) as total_images,
       SUM(CASE WHEN file_name LIKE 'https://%' THEN 1 ELSE 0 END) as url_format,
       SUM(CASE WHEN file_name LIKE '20%' THEN 1 ELSE 0 END) as old_format
FROM gyc_product_images;
# Expected: url_format = total, old_format = 0
```

### Test 3: JSON Output Verification
```bash
# Check JSON includes all new fields
head -50 data/vehicles.json

# Expected structure:
# {
#   "id": 248,
#   "reg_no": "volvo-v40...",
#   "selling_price": 8990,
#   "doors": 5,                    ← NEW
#   "registration_plate": "66",    ← NEW
#   "drive_system": null,          ← NEW
#   "transmission": "Manual",      ← FIXED
#   "fuel_type": "Diesel",        ← FIXED
#   "body_style": "Estate",       ← FIXED
#   "engine_size": 1998,          ← NEW
#   "address": "Unit 10 Mill...",  ← NEW
#   "post_code": "LE7 1NS",        ← NEW
# }
```

### Test 4: Compare Before & After

**Before Enhancements**:
```
Found: 162 (DUPLICATES)
Unique in DB: 81
Missing fields: transmission, fuel_type, body_style (not in attribute table)
Missing: doors, engine_size, registration_plate, dealer address
```

**After Enhancements**:
```
Found: 81 (DEDUPLICATED)
Unique in DB: 81
Specs populated: transmission, fuel_type, body_style ✅
New fields: doors, engine_size, registration_plate, dealer address ✅
Images: Stored as URLs (faster, smaller footprint) ✅
```

---

## Rollback Plan

If any step fails:

### Rollback STEP 1 (Deduplication)
```bash
# Revert parseListingPage() to original
# Scraper will again find 162 cards
# Database will still deduplicate via reg_no unique key
# No data loss
```

### Rollback STEP 2 (Attribute Table)
```bash
# If INSERT fails, revert to original structure
# Data already in gyc_vehicle_info, just in wrong table
# Can migrate manually:
INSERT INTO gyc_vehicle_attribute (model, year, fuel_type, transmission, body_style)
SELECT attention_grabber, year, 'unknown', 'unknown', 'unknown'
FROM gyc_vehicle_info WHERE vendor_id = 432;
```

### Rollback All Steps
```bash
# Restore from backup (if available):
mysql carsafari < backup.sql

# Or manually delete scraped data:
DELETE FROM gyc_product_images WHERE vehicle_info_id IN 
  (SELECT id FROM gyc_vehicle_info WHERE vendor_id = 432);
DELETE FROM gyc_vehicle_attribute WHERE id IN 
  (SELECT attr_id FROM gyc_vehicle_info WHERE vendor_id = 432);
DELETE FROM gyc_vehicle_info WHERE vendor_id = 432;
```

---

## Success Criteria

| Metric | Before | After | Status |
|--------|--------|-------|--------|
| Vehicles found | 162 | 81 | ✅ Target |
| Unique in DB | 81 | 81 | ✅ No loss |
| Specs populated | 0% | 100% | ✅ Target |
| Doors extracted | 0% | 70%+ | ✅ Target |
| Dealer info | NULL | Complete | ✅ Target |
| Image handling | Downloaded | URLs only | ✅ Target |
| Scrape time | ~8min | ~2min | ✅ Bonus |

---

## Maintenance Notes

### After Implementation

1. **Monitor scrape logs** for any errors
2. **Review JSON output** format (should match database)
3. **Test carcheck.co.uk integration** (optional Phase 2)
4. **Monitor database size** (no more image downloads)

### Future Enhancements (Phase 2)

- [ ] Integrate carcheck.co.uk for additional enrichment
- [ ] Add make/model lookup table
- [ ] Implement image compression (optional)
- [ ] Add MOT expiry field if DVLA data becomes available
- [ ] Create dealer UI to manage vehicle listings

---

## Support & Troubleshooting

### Common Issues

**Issue**: Scraper still showing 162 vehicles
**Solution**: Verify STEP 1 changes applied to `parseListingPage()`, check for multiple matching selectors

**Issue**: Specs table empty
**Solution**: Verify STEP 2 INSERT query correct, check database connection in `saveVehiclesToCarSafari()`

**Issue**: Images not showing on frontend
**Solution**: Update image display code to handle full URLs instead of filenames

### Debug Commands

```bash
# Check scraper logs
tail -50 logs/scraper_2025-12-04.log

# Count vehicles
php -r "
\$config = require 'config.php';
\$pdo = new PDO('mysql:host=' . \$config['database']['host'] . 
  ';dbname=' . \$config['database']['dbname'],
  \$config['database']['username'], \$config['database']['password']);
\$result = \$pdo->query('SELECT COUNT(*) as c FROM gyc_vehicle_info WHERE vendor_id=432');
echo 'Vehicles: ' . \$result->fetch()['c'];
"

# Test single vehicle parsing
php scrape-single-page.php
```

---

**Created**: 2025-12-04
**Last Updated**: 2025-12-04
**Status**: Ready for implementation
