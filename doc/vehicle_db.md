# Vehicle Database Schema Reference

Production database schema for CarSafari CarVendors scraper. Accurate column definitions from `sql/main_live_db.sql`.

---

## Core Tables

### gyc_vehicle_info (Main Vehicle Listing)
```
Column              Type              Role
─────────────────────────────────────────────
id                  INT(11) PK        Unique vehicle record
vendor_id           INT(11) FK        Dealer ID (432 = systonautosltd)
reg_no              VARCHAR(255)      UK registration (VRM) - UNIQUE per vendor
attr_id             INT(11) FK        Reference to gyc_vehicle_attribute (specs)
selling_price       INT(11)           Current asking price (£)
mileage             INT(11)           Odometer reading (miles)
color               VARCHAR(100)      Exterior color (text)
color_id            INT(11) FK        Color ID (gyc_vehicle_color)
description         TEXT              Full vehicle description
active_status       ENUM(0,1,2,3,4)   0=Pending, 1=Waiting, 2=Published, 3=Sold, 4=Blocked
created_at          DATETIME          Record creation timestamp
updated_at          DATETIME          Last modification timestamp
```

**Indexes**: 
- PRIMARY KEY: id
- UNIQUE: (vendor_id, reg_no)
- INDEX: vendor_id, active_status, created_at

### gyc_vehicle_attribute (Vehicle Specifications)
```
Column              Type              Role
─────────────────────────────────────────────
id                  INT(11) PK        Unique spec set
category_id         INT(11) FK        Vehicle category
make_id             INT(11) FK        Manufacturer (gyc_make)
model               VARCHAR(255)      Vehicle model name
generation          VARCHAR(255)      Model generation/series
trim                VARCHAR(255)      Trim level (e.g., "S", "SE", "Limited")
engine_size         VARCHAR(255)      Engine displacement (cc)
fuel_type           VARCHAR(255)      Petrol/Diesel/Hybrid/Electric
transmission        VARCHAR(255)      Manual/Automatic
derivative          VARCHAR(255)      Body style variant
gearbox             VARCHAR(255)      Gearbox type (5-speed, CVT, etc.)
year                INT(4)            Manufacture year (YYYY)
body_style          VARCHAR(50)       Hatchback/Sedan/SUV/Estate/MPV
active_status       ENUM(0,1)         0=Inactive, 1=Active
created_at          DATETIME          Creation timestamp
updated_at          DATETIME          Last modification timestamp
```

**Indexes**:
- PRIMARY KEY: id
- INDEX: make_id, category_id

### gyc_product_images (Vehicle Images)
```
Column              Type              Role
─────────────────────────────────────────────
id                  INT(11) PK        Unique image record
vechicle_info_id    INT(11) FK        Parent vehicle (gyc_vehicle_info.id)
file_name           VARCHAR(255)      Image URL (reference, not binary)
serial              INT(255)          Image order (1, 2, 3... per vehicle)
created_at          DATETIME          Upload timestamp
updated_at          DATETIME          Last modification timestamp
```

**Indexes**:
- PRIMARY KEY: id
- FK: vechicle_info_id → gyc_vehicle_info.id (ON DELETE CASCADE)
- INDEX: serial (for ordering)

**Note**: Images stored as URL references only, not binary files. Serial determines display order in listings.

### scraper_statistics (Performance Tracking)
```
Column              Type              Role
─────────────────────────────────────────────
id                  INT(11) PK        Unique run record
vendor_id           INT(11)           Dealer ID (432)
run_date            DATETIME          Execution timestamp
status              VARCHAR(50)       'success'/'failed'/'warning'
vehicles_found      INT(11)           Total vehicles discovered
vehicles_inserted   INT(11)           New vehicles added
vehicles_updated    INT(11)           Existing vehicles modified
vehicles_skipped    INT(11)           Unchanged (hash match)
images_stored       INT(11)           Total images saved
success_rate        DECIMAL(5,2)      (inserted+updated+skipped)/found %
inventory_ratio     DECIMAL(5,2)      new_vehicles/baseline_vehicles %
gates_passed        TINYINT(1)        0=Failed, 1=Both gates passed
duration_seconds    INT(11)           Execution time
error_message       TEXT              Failure details
stats_json          LONGTEXT          Full metrics as JSON
created_at          TIMESTAMP         Record creation
```

**Indexes**:
- PRIMARY KEY: id
- INDEX: (vendor_id, run_date) - for daily queries

---

## Reference Tables

### gyc_make (Manufacturers)
```
Column              Type              Role
─────────────────────────────────────────────
id                  INT(11) PK        Manufacturer ID
name                VARCHAR(255)      Make name (e.g., "Volkswagen")
cat_id              INT(11) FK        Category
active_status       ENUM(0,1)         0=Inactive, 1=Active
created_at          DATETIME          Timestamp
```

### gyc_vehicle_color (Color Standardization)
```
Column              Type              Role
─────────────────────────────────────────────
id                  INT(11) PK        Color ID
color_name          VARCHAR(100)      Standardized name
active_status       ENUM(0,1)         0=Inactive, 1=Active
created_at          DATETIME          Timestamp
```

### Other Reference Tables
- **gyc_category**: Vehicle category (SUV, Sedan, etc.)
- **gyc_vehicle_condition**: Condition lookup (Good/Fair/Excellent)
- **gyc_vehicle_exterior_finish**: Paint/exterior type
- **gyc_vendor_info**: Dealers/vendors master list

---

## Key Relationships

```
gyc_vehicle_info (vehicle listing)
  ├─ vendor_id → gyc_vendor_info.id
  ├─ attr_id → gyc_vehicle_attribute.id (specs)
  │   ├─ make_id → gyc_make.id
  │   └─ category_id → gyc_category.id
  └─ color_id / manufacturer_color_id → gyc_vehicle_color.id

gyc_product_images
  └─ vechicle_info_id → gyc_vehicle_info.id (images per vehicle)

scraper_statistics
  └─ vendor_id → gyc_vendor_info.id (metrics per vendor)
```

---

## Data Type Notes

| Field | Type | Notes |
|-------|------|-------|
| Price | INT(11) | Stored as pence (£10,000 = 1000000) |
| Mileage | INT(11) | Stored as miles (50,000 = 50000) |
| VRM/reg_no | VARCHAR(255) | Format: UK format only (valid by scraper) |
| Fuel Type | VARCHAR(255) | Free text: "Petrol", "Diesel", "Hybrid" |
| Transmission | VARCHAR(255) | Free text: "Manual", "Automatic" |
| Engine Size | VARCHAR(255) | Free text: "1.6", "2.0", etc. (cc) |
| URL References | VARCHAR(255) | Images stored as URLs, not files |
| JSON Fields | LONGTEXT | stats_json, feature_id (comma-separated) |

---

## Views

### gyc_v_vechicle_info (Denormalized Vehicle View)
Joins vehicle, specs, manufacturer, color, and first image for reporting/export:
```sql
SELECT 
  gvi.id, gvi.reg_no, gvi.selling_price, gvi.mileage,
  gva.model, gva.year, gva.fuel_type, gva.transmission,
  gm.name as make_name,
  gvc.color_name,
  gpi.file_name as first_image,
  gvi.active_status
FROM gyc_vehicle_info gvi
LEFT JOIN gyc_vehicle_attribute gva ON gva.id = gvi.attr_id
LEFT JOIN gyc_make gm ON gm.id = gva.make_id
LEFT JOIN gyc_vehicle_color gvc ON gvc.id = gvi.color_id
LEFT JOIN gyc_product_images gpi ON gpi.vechicle_info_id = gvi.id AND gpi.serial = 1
WHERE gvi.vendor_id = 432
```

---

## Integration Notes for Scrapers

1. **Unique Constraint**: (vendor_id, reg_no) ensures no duplicate registrations per dealer
2. **Image Ordering**: Use `serial` (1, 2, 3...) to maintain image order
3. **Status Workflow**: Start with active_status=0 (pending) → auto-publish to 1 or 2 based on scraper config
4. **Specs Reuse**: Look up attr_id before INSERT to avoid duplicate spec entries
5. **Hash-Based Updates**: Store md5 hash of key fields (price, mileage, description, image_count) for change detection
6. **FK Constraints**: Delete from gyc_product_images BEFORE gyc_vehicle_info (images reference vehicles)
