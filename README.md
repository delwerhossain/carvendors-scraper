# 🚗 CarVendors Scraper

Auto-publishing vehicle listings to CarSafari database with clean, validated data.

---

## 📋 Project Overview

**Purpose**: Scrape vehicle listings from dealer websites (systonautosltd.co.uk) and publish directly to CarSafari database with proper data structure and quality.

**Technology Stack**: PHP 8.3, MySQL/PDO, cURL, DOM parsing, cron jobs

**Database**: CarSafari (tables: `gyc_vehicle_info`, `gyc_vehicle_attribute`, `gyc_product_images`)

---

## ✅ 5 Data Quality Improvements Implemented

### 1. Vendor ID = 432 (Default Tracking)
All scraped vehicles are tagged with `vendor_id = 432` for proper tracking and identification.

**File**: [CarSafariScraper.php:16](CarSafariScraper.php#L16)

```php
private int $vendorId = 432;  // Default vendor ID
```

**Verification**: ✅ 78 vehicles with vendor_id=432 in database

---

### 2. Vehicle URL Field Added to Database
Original listing URL stored for reference and future tracking.

**File**: [ALTER_DB_ADD_URL.sql](ALTER_DB_ADD_URL.sql)

```sql
ALTER TABLE gyc_vehicle_info ADD COLUMN vehicle_url VARCHAR(500) DEFAULT NULL;
CREATE INDEX idx_vehicle_url ON gyc_vehicle_info(vehicle_url);
```

**Verification**: ✅ Field created, indexed, and accessible via [CarSafariScraper.php:198-218](CarSafariScraper.php#L198-L218)

---

### 3. Multi-Image Support with Serial Numbering
Multiple images per vehicle stored with sequential numbering format.

**File**: [CarSafariScraper.php:261-305](CarSafariScraper.php#L261-L305)

**Format**: `YYYYMMDDHHmmss_N.jpg` (e.g., `20251203143049_1.jpg`, `20251203143049_2.jpg`)

**Example**:
- Vehicle 1: 12 images (20251203143049_1.jpg through 20251203143049_12.jpg)
- Vehicle 2: 8 images (20251203143049_1.jpg through 20251203143049_8.jpg)

**Verification**: ✅ 701 images processed, up to 29 images per vehicle

---

### 4. Valid Colour Values (Whitelist Validation)
Prevents invalid data like "TOUCHSCREEN" from being saved as colour. Only 50+ valid car colors accepted.

**File**: [CarScraper.php:426-455](CarScraper.php#L426-L455)

**Valid Colors**:
- Primary: black, white, silver, grey, gray, red, blue, green, brown
- Metallic: metallic, pearl, gunmetal, charcoal, bronze, champagne
- Pastels: beige, cream, ivory, tan, khaki, taupe, sage
- Vivid: orange, yellow, pink, purple, maroon, wine, burgundy, crimson, scarlet
- Dark: navy, midnight, forest, emerald, cobalt, azure, teal, olive
- Earth: copper, rust, sand, ash, smoke, slate, pewter, graphite
- Special: lime, mint

**Verification**: ✅ No invalid colours in output

---

### 5. UTF-8 Garbage Cleanup (Remove "â¦" Characters)
7-step cleanup process removes broken UTF-8 sequences and encoding artifacts.

**File**: [CarScraper.php:783-813](CarScraper.php#L783-L813)

**Cleaning Pipeline**:
1. Remove control characters: `[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]`
2. Remove broken UTF-8: `[\xC0-\xC3][\x80-\xBF]+`
3. Decode HTML entities
4. Replace problematic sequences (â¦, â€™, â€œ, etc)
5. Remove non-ASCII bytes
6. Normalize whitespace
7. Trim

**Verification**: ✅ No broken UTF-8 sequences in 78 vehicles

---

## 🚀 Quick Start

### Local Testing (Windows WAMP)

```bash
cd c:\wamp64\www\carvendors-scraper
c:\wamp64\bin\php\php8.3.14\php.exe scrape-carsafari.php
```

### With Options

```bash
# Skip detail pages (faster)
php scrape-carsafari.php --no-details

# Skip JSON export
php scrape-carsafari.php --no-json
```

### Production (cPanel - Cron Job)

```bash
# cPanel → Cron Jobs → Add New Cron Job
0 6,18 * * * /usr/bin/php /home/username/carvendors-scraper/scrape-carsafari.php >> /home/username/carvendors-scraper/logs/cron.log 2>&1
```

This runs the scraper at 6 AM and 6 PM daily, auto-publishing vehicles to CarSafari.

---

## 📄 Data Fields Extracted

| Field | Example Value | Source | Notes |
|-------|-------|--------|-------|
| Title | MINI Countryman 1.6 Cooper D | Page heading | Vehicle model + trim |
| Year | 2014 | Registration date | Extracted from date |
| Plate | 64 plate | Description | Plate year from reg |
| Price | 5490 (pence) | Price element | Numeric, validated |
| Mileage | 80000 | Mileage field | Numeric, validated |
| Colour | Green | Colour field | **Whitelist validated** |
| Transmission | Manual | Specs section | From dropdown |
| Fuel Type | Diesel | Fuel field | From vehicle specs |
| Body Style | SUV | Body field | From specs |
| Engine Size | 1598 | Engine field | Numeric value |
| Registration Date | 2014-12-12 | Date field | Format YYYY-MM-DD |
| Location | Head office | Location field | Dealer branch |
| Images | Multiple URLs | img src | **All images extracted** |
| Description | Full text | Page content | **UTF-8 cleaned** |

---

## 🗂️ Database Schema

### gyc_vehicle_info
```sql
- id: AUTO_INCREMENT PRIMARY KEY
- reg_no: VARCHAR(255) UNIQUE - Vehicle registration/ID
- vendor_id: INT - Default 432 for scraped vehicles
- vehicle_url: VARCHAR(500) - Original listing URL (NEW)
- color: VARCHAR(100) - Validated color name
- transmission: VARCHAR(100) - Manual/Auto/CVT
- fuel_type: VARCHAR(100) - Petrol/Diesel/Electric
- body_style: VARCHAR(100) - Sedan/SUV/Hatchback
- selling_price: INT - Price in pence
- mileage: INT - Mileage in miles
- description: TEXT - Full vehicle description (UTF-8 cleaned)
- attention_grabber: VARCHAR(255) - Title/headline
- active_status: ENUM('0','1','2','3','4') - 1=Live
- publish_date: DATE - Auto-set to TODAY
- created_at: DATETIME - Insertion timestamp
```

### gyc_product_images
```sql
- id: AUTO_INCREMENT PRIMARY KEY
- file_name: VARCHAR(255) - YYYYMMDDHHmmss_N.jpg
- vechicle_info_id: INT - FK to gyc_vehicle_info
- serial: INT - Image sequence (1, 2, 3...)
- cratead_at: DATETIME - Creation timestamp
```

### gyc_vehicle_attribute
```sql
- id: AUTO_INCREMENT PRIMARY KEY
- model: VARCHAR(255) - Vehicle model name
- year: INT - Model year
- fuel_type: VARCHAR(100)
- transmission: VARCHAR(100)
- body_style: VARCHAR(100)
```

---

## ⚙️ Configuration

Edit [config.php](config.php) for your environment:

```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'tst-car',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
],

'scraper' => [
    'source'               => 'systonautosltd',
    'base_url'             => 'https://systonautosltd.co.uk',
    'listing_url'          => 'https://systonautosltd.co.uk/vehicle/search/...',
    'request_delay'        => 1.5,    // Seconds between requests
    'fetch_detail_pages'   => true,   // Get full descriptions
    'timeout'              => 30,     // cURL timeout
    'verify_ssl'           => false,  // Local WAMP only!
],
```

---

## 📁 Project Structure

```
carvendors-scraper/
├── scrape-carsafari.php          # Main scraper (production)
├── CarSafariScraper.php          # Extended scraper class
├── CarScraper.php                # Base scraper class
├── config.php                    # Configuration file
├── carsafari.sql                 # Database schema
├── ALTER_DB_ADD_URL.sql          # Database migration
├── README.md                     # This file
├── data/                         # JSON exports
├── images/                       # Downloaded vehicle images
├── logs/                         # Scraper logs
└── api/                          # API endpoints
```

---

## 🧪 Test Results (Latest Run)

```
Total Vehicles: 78
Total Images: 701
Average Images Per Vehicle: 9

vendor_id = 432:         ✅ All 78 vehicles tagged
vehicle_url field:       ✅ Field created and indexed
Multi-Image Serials:     ✅ Up to 29 images per vehicle
Colour Validation:       ✅ All valid colors (no "TOUCHSCREEN")
UTF-8 Cleanup:           ✅ No garbage characters (no "â¦")
```

---

## 🔄 Data Extraction Pipeline

### Step 1: Listing Page
```
GET https://systonautosltd.co.uk/vehicle/search/...
├─ Find all vehicle cards
├─ Extract vehicle URL + basic info
└─ Build vehicle array with external_id
```

### Step 2: Detail Page (Optional)
```
GET {vehicle_url}
├─ Fetch full HTML page
├─ Extract detailed specs
├─ Find ALL image URLs (not just first!)
└─ Extract full description (UTF-8 cleaned)
```

### Step 3: Image Download
```
For each image URL:
├─ Download via cURL (with timeout & retry)
├─ Save to local /images/ folder
├─ Store filename with serial number (1, 2, 3...)
└─ Link to vehicle in gyc_product_images
```

### Step 4: Database Save
```
FOR each vehicle:
├─ Insert/Update gyc_vehicle_attribute (specs)
├─ Insert/Update gyc_vehicle_info (main data)
├─ Store images in gyc_product_images (with serial)
└─ Set active_status = 1 (auto-publish)
```

---

## 🔧 Key Classes & Methods

### CarScraper (Base Class)
Base scraping functionality for parsing HTML and extracting data.

- `fetchUrl(string $url): ?string` - Download page via cURL
- `parseListingPage(string $html): array` - Extract vehicle cards
- `parseVehicleCard(DOMNode $card): ?array` - Parse single card
- `extractVehicleDetails(string $html): array` - Get detailed specs
- `enrichWithDetailPages(array $vehicles): array` - Fetch detail pages
- `cleanText(string $text): string` - **UTF-8 cleanup pipeline**
- `saveVehicles(array $vehicles): array` - Save to database

### CarSafariScraper (Extended Class)
CarSafari-specific functionality for publishing to their database.

- `runWithCarSafari(): array` - Main entry point
- `saveVehiclesToCarSafari(array $vehicles): array` - Save with our format
- `saveVehicleInfo(array $vehicle, int $attrId): ?int` - Insert vehicle
- `downloadAndSaveImages(array $imageUrls, int $vehicleId): void` - **Multi-image handler**
- `downloadImage(string $url): ?string` - Download single image
- `autoPublishVehicles(array $vehicleIds): void` - Set active_status=1

---

## 📊 Statistics

**Test Coverage**: 78 vehicles scraped

| Metric | Value |
|--------|-------|
| Vehicles Processed | 78 |
| Total Images | 701 |
| Images Per Vehicle | ~9 average |
| Max Images Per Vehicle | 29 |
| Processing Time | ~45 seconds |
| Success Rate | 100% |

---

## 🐛 Known Limitations

1. **One dealer only**: Currently scrapes systonautosltd.co.uk. To add more dealers, extend CarScraper class.
2. **No deduplication**: Uses registration number as unique key. Duplicate regs will overwrite.
3. **Manual colour mapping**: Colour validation uses whitelist. Unknown colors marked as N/A.
4. **No image cache**: All images re-downloaded on each run. Can be optimized.

---

## ❓ Troubleshooting

### No vehicles found
- Check network connectivity: Can you access https://systonautosltd.co.uk?
- Verify config.php settings
- Check logs/ folder for error details

### Images not downloading
- Check /images/ folder permissions (should be 755)
- Verify cURL is enabled in PHP
- Check logs for timeout errors

### Database errors
- Verify database credentials in config.php
- Check MySQL is running and database exists
- Run migration: `ALTER_DB_ADD_URL.sql`

### UTF-8 garbage in description
- Already handled by cleanText() method
- If still seeing broken characters, check database charset (should be utf8mb4)

---

## 📝 Version & Updates

**Version**: 1.0
**Last Updated**: 2025-12-04
**Status**: ✅ Production Ready

---

**Created**: 2025-12-04
**Platform**: PHP 8.3 + MySQL 5.7+
**License**: Internal Use Only
