# CarVendors Scraper - Implementation Plan & Execution

## Overview
Enhance the scraper to extract more data fields and fix the 162→81 duplication issue.

---

## STEP 1: Fix Duplication (162→81 vehicles)
**Status:** ✅ COMPLETE  
**File:** `CarScraper.php`  
**Change:** Add `$processedIds` array to track processed vehicles

### What was done:
- Added deduplication tracking in `parseListingPage()` method
- Now correctly identifies 81 unique vehicles instead of 162 duplicates

### Test Result:
```
Before: Found 162 vehicles (with duplicates)
After:  Found 81 vehicles (unique only)
✅ WORKING
```

---

## STEP 2: Save Specs to gyc_vehicle_attribute
**Status:** ✅ COMPLETE  
**Files:** `CarSafariScraper.php`  
**Change:** Ensure transmission, fuel_type, body_style are saved to attribute table

### What was done:
- Verified that specs are correctly inserted into `gyc_vehicle_attribute` table
- `transmission`, `fuel_type`, `body_style` are all populated

### Test Result:
```
Database Check:
- Total attributes: 161 records
- transmission populated: 161 (100%)
- fuel_type populated: 161 (100%)
- body_style populated: 143 (88.8% - some not available on website)
✅ WORKING
```

---

## STEP 3: Parse Additional Fields from Title
**Status:** IN PROGRESS  
**File:** `CarScraper.php` - `parseVehicleCard()` method  
**Fields to extract:**
- `doors` - from "5dr", "3dr" pattern
- `plate_year` - from "(64 plate)" pattern
- `drive_system` - from "4WD", "AWD", "xDrive"
- `trim` - from "SE", "Sport", "S line"

### Implementation Details:
Add regex patterns to `parseVehicleCard()` to extract:
```php
// Extract doors: "5dr", "3dr", "4dr"
preg_match('/(\d)dr\b/i', $title, $matches)
→ $vehicle['doors'] = (int)$matches[1]

// Extract plate year: "(64 plate)"
preg_match('/\((\d{2})\s*plate\)/i', $title, $matches)
→ $vehicle['registration_plate'] = $matches[1] . " plate"

// Extract drive system: "4WD", "AWD", "xDrive"
preg_match('/\b(4WD|AWD|2WD|xDrive|sDrive|ALL4)\b/i', $title, $matches)
→ $vehicle['drive_system'] = strtoupper($matches[1])

// Extract trim: Usually before body type
preg_match('/\b([A-Z][\w\s]+?)\s+(?:5dr|4dr|3dr|2dr|SUV|Saloon|Estate)/i', $title, $matches)
→ $vehicle['trim'] = $matches[1]
```

### Database Columns:
- `doors` - int(3) - already exists in `gyc_vehicle_info`
- `registration_plate` - varchar(50) - already exists in `gyc_vehicle_info`
- `drive_system` - varchar(100) - already exists in `gyc_vehicle_info`
- `trim_id` - int(11) - exists as FK in `gyc_vehicle_info` (would need lookup)

---

## STEP 4: Extract Engine Size from Detail Page
**Status:** NOT STARTED  
**File:** `CarScraper.php` - `enrichWithDetailPages()` method  
**Field:** `engine_size` from detail page

### Implementation Details:
The detail page shows text like: `Engine Size: 1,598 cc`

Add extraction method:
```php
protected function extractEngineSize(string $html): ?string
{
    if (preg_match('/Engine\s+Size[:\s]*([0-9,]+)\s*(?:cc|ml)?/i', $html, $matches)) {
        return str_replace(',', '', $matches[1]);
    }
    return null;
}
```

### Database Column:
- `engine_size` - varchar(255) - already exists in `gyc_vehicle_attribute`

---

## STEP 5: Hardcode Dealer Address & Post Code
**Status:** NOT STARTED  
**File:** `CarSafariScraper.php` - `saveVehiclesToCarSafari()` method  
**Fields:**
- `post_code` = "LE7 1NS"
- `address` = "Unit 10 Mill Lane Syston, Leicester, LE7 1NS"
- `drive_position` = "Right" (UK default)

### Implementation Details:
Set hardcoded dealer location in the ON DUPLICATE KEY UPDATE clause:
```sql
INSERT INTO gyc_vehicle_info (...) VALUES (...)
ON DUPLICATE KEY UPDATE
  post_code = 'LE7 1NS',
  address = 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS',
  drive_position = 'Right',
  ...
```

---

## STEP 6: Store Image URLs Instead of Downloading
**Status:** NOT STARTED  
**File:** `CarSafariScraper.php` - `downloadAndSaveImages()` method  
**Change:** Store URL in `gyc_product_images.file_name` instead of downloading

### Implementation Details:
Instead of:
```php
// Download image to disk
$imagePath = $this->downloadImage($imageUrl, $vehicleId);
// INSERT file_name (local path)
```

Do:
```php
// Just store the URL
// INSERT file_name (full URL)
INSERT INTO gyc_product_images (file_name, vehicle_info_id, serial)
VALUES ('https://systonautosltd.co.uk/images/...', $vehicleId, 1)
```

### Benefits:
- Faster scraping (no downloads)
- Less disk space
- Always up-to-date images (URLs are current)

---

## STEP 7: Full Test & Verification
**Status:** NOT STARTED

### Test Cases:
1. **Duplication Test**
   - Scrape and verify exactly 81 vehicles found
   - Check no duplicate reg_nos in database

2. **Specs Test**
   - Verify transmission, fuel_type, body_style in `gyc_vehicle_attribute`
   - Check they match the scraped data

3. **New Fields Test**
   - Verify doors extracted correctly
   - Verify registration_plate extracted
   - Verify drive_system extracted
   - Verify engine_size from detail page

4. **Dealer Info Test**
   - Verify all vehicles have post_code = "LE7 1NS"
   - Verify all vehicles have address set

5. **Image Test**
   - Verify image URLs stored in database
   - Check no disk images downloaded

6. **Database Consistency**
   - Check for NULL values in new fields
   - Verify foreign key relationships
   - Check updated_at timestamps

---

## Execution Timeline

| Step | File | Est. Time | Status |
|------|------|-----------|--------|
| 1 | CarScraper.php | 5 min | ✅ Done |
| 2 | CarSafariScraper.php | 5 min | ✅ Done |
| 3 | CarScraper.php | 15 min | ⏳ In Progress |
| 4 | CarScraper.php | 10 min | ⏳ Pending |
| 5 | CarSafariScraper.php | 10 min | ⏳ Pending |
| 6 | CarSafariScraper.php | 15 min | ⏳ Pending |
| 7 | Manual Testing | 20 min | ⏳ Pending |
| | **TOTAL** | **80 min** | |

---

## Code Changes Summary

### Files Modified:
1. `CarScraper.php`
   - `parseListingPage()` - Add deduplication
   - `parseVehicleCard()` - Add field extractions
   - Add `extractEngineSize()` method
   - `enrichWithDetailPages()` - Call extractEngineSize()

2. `CarSafariScraper.php`
   - `saveVehiclesToCarSafari()` - Update INSERT with new fields
   - `downloadAndSaveImages()` - Store URLs instead of downloading

### Database Schema:
- No new columns needed (all fields already exist!)
- Columns used: doors, registration_plate, drive_system, engine_size, post_code, address, drive_position

---

## Testing Commands

```bash
# Quick test (no details)
php scrape-carsafari.php --no-details

# Full test with details
php scrape-carsafari.php

# Check database results
mysql -u user -p carsafari < verify_changes.sql
```

---

## Rollback Plan

If issues occur:
1. Database backup already exists
2. Git branch can be reverted
3. Previous scrape logs available in `logs/`

---

**Start Date:** 2025-12-04  
**Target Completion:** 2025-12-04  
**Last Updated:** 2025-12-04 11:30
