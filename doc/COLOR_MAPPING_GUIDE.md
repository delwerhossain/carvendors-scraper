# 🎨 Color Mapping Reference Guide

## Quick Color ID Lookup Table

| ID | Canonical Color | Variants Matched |
|----|-----------------|------------------|
| 1 | **Beige** | Cream, Tan, Sand, Ecru, Linen, Taupe |
| 2 | **Black** | Jet Black, Pearl Black, Ebony, Raven |
| 3 | **Blue** | Light Blue, Sky Blue, Azure, Cobalt, Steel Blue, Peacock Blue, Cornflower, Denim |
| 4 | **Bronze** | Copper, Bronze Metallic |
| 5 | **Brown** | Tan Brown, Beige Brown, Chocolate, Mahogany, Chestnut, Coffee, Caramel, Walnut, Mocha |
| 6 | **Burgundy** | Maroon, Wine, Claret, Wine Red, Oxblood |
| 7 | **Gold** | Champagne, Golden, Champagne Gold, Beige Gold |
| 8 | **Green** | Light Green, Dark Green, Olive, Moss, Forest Green, Sage, Pistachio, Teal, Turquoise |
| 9 | **Grey** | Light Grey, Dark Grey, Charcoal, Gunmetal, Slate, Graphite, Ash, Silver Grey, Pewter, Stone, Concrete |
| 10 | **Indigo** | Deep Indigo |
| 11 | **Magenta** | Fuchsia |
| 12 | **Mcroon** | Mccroon, Macroon |
| 13 | **Multicolor** | Multi-Color, Mixed, Two-Tone |
| 14 | **Navy** | Navy Blue, Dark Navy |
| 15 | **Orange** | Burnt Orange, Apricot, Tangerine, Coral |
| 16 | **Pink** | Light Pink, Hot Pink, Rose, Salmon, Blush, Fuchsia Pink |
| 17 | **Purple** | Violet, Lilac, Lavender, Plum, Deep Purple, Dark Purple |
| 18 | **Red** | Dark Red, Bright Red, Crimson, Scarlet, Ruby, Cherry, Fire Red, Candy Red |
| 19 | **None** | (No variant mappings) |
| 20 | **White** | Off White, Off-White, Ivory, Cream White, Pearl White, Snow White, Pure White |
| 21 | **Silver** | Silver Metallic, Light Silver, Bright Silver, Polished Silver |
| 22 | **Yellow** | Light Yellow, Bright Yellow, Golden Yellow, Lemon, Banana, Sunshine |
| 23 | **Lime** | Lime Green, Neon, Neon Green, Neon Yellow |

---

## How Color Mapping Works in Scraper

### Step 1: Extract Color from Dealer HTML
```php
$color = "Red";  // or "red", "CRIMSON", "Ruby", etc.
```

### Step 2: Normalize
```php
// Lowercase
$normalized = "red";

// Remove finishes: metallic, pearl, matte, solid, gloss
// Remove suffixes: shade, tone, effect, sparkle
// Remove combos: "Red/Silver" → "red"

// Result: "red"
```

### Step 3: Match Against In-Memory Map
```php
$map = [
    'red' => 18,
    'crimson' => 18,
    'scarlet' => 18,
    'ruby' => 18,
    'cherry' => 18,
    'fire red' => 18,
    'candy red' => 18,
    ...
];

// MATCH! color_id = 18
```

### Step 4: If Not in Map, Query Database
```sql
SELECT id FROM gyc_vehicle_color 
WHERE LOWER(color_name) = "red";
-- Result: id = 18
```

### Step 5: Cache Result (for performance)
```php
$this->colorCache['red'] = 18;
// Next time "Red" appears, instant lookup!
```

---

## How to Fix `color_id: null` Issues

### Problem
Your JSON shows:
```json
{
  "color": "Red",
  "color_id": null,
  "manufacturer_color_id": null
}
```

### Root Cause
1. **Colors not seeded in database** - gyc_vehicle_color table is empty or missing "Red"
2. **Color name case mismatch** - Map expects lowercase, but DB has mixed case

### Solution

#### Option A: Seed Colors (Recommended)
```bash
# Local
php -r "require_once 'config.php'; require_once 'sql/COLOR_SEED_DATA.sql';"

# Or paste into phpMyAdmin
-- Copy SQL from live_DB_migrate.md Step 0
```

#### Option B: Add Missing Color to Database
```sql
-- If "Red" is missing:
INSERT INTO gyc_vehicle_color (color_name, active_status) 
VALUES ('Red', 1);

-- Then restart scraper
php daily_refresh.php --vendor=432 --force
```

#### Option C: Update Code to Handle Variant
If your dealer uses "Crimson" but it's not in the map:
```php
// In CarSafariScraper.php resolveColorId() method, add to $map:
'crimson' => 18,  // Maps to Red
```

---

## Testing Color Mapping

### Local Test
```bash
# Run scraper with --force flag to reprocess all colors
php daily_refresh.php --vendor=432 --force

# Check logs for color resolution
grep "Resolved color" logs/scraper_2025-12-18.log
# Expected: "Resolved color 'Red' → ID 18"

# Check JSON export
grep -o '"color_id": [0-9]*' data/vehicles.json | head -5
# Expected: "color_id": 18, "color_id": 2, "color_id": 3, etc.
# NOT: "color_id": null
```

### Database Verification
```sql
-- Check how many vehicles have color_id populated
SELECT 
  COUNT(*) as total,
  COUNT(CASE WHEN color_id IS NOT NULL THEN 1 END) as with_color,
  COUNT(CASE WHEN color_id IS NULL THEN 1 END) as without_color
FROM gyc_vehicle_info 
WHERE vendor_id = 432;

-- Expected: with_color = 68, without_color = 0

-- Check which colors are still unresolved
SELECT DISTINCT color, color_id 
FROM gyc_vehicle_info 
WHERE vendor_id = 432 
  AND color_id IS NULL 
ORDER BY color;

-- If results show colors, they need to be added to the color map
```

### JSON Export Check
```bash
# Extract color_id from JSON
jq '.vehicles[0] | {color, color_id, manufacturer_color_id}' data/vehicles.json

# Expected:
# {
#   "color": "Silver",
#   "color_id": 21,
#   "manufacturer_color_id": 21
# }

# NOT: "color_id": null
```

---

## Adding New Colors

If dealer uses a color not in the list:

### Step 1: Add to In-Memory Map
Edit `CarSafariScraper.php`, method `resolveColorId()`:
```php
// Find the appropriate section, e.g., for new red variant:
'fire engine red' => 18,  // Add this line

// Or if it's a completely new color:
'turquoise' => 3,  // Map to Blue (closest match)
```

### Step 2: Add to Database
```sql
-- Add new color if truly unique
INSERT INTO gyc_vehicle_color (color_name, active_status) 
VALUES ('Fire Engine Red', 1);

-- Then map it in code:
'fire engine red' => (SELECT id FROM gyc_vehicle_color WHERE color_name='Fire Engine Red');
```

### Step 3: Re-run Scraper
```bash
php daily_refresh.php --vendor=432 --force
```

---

## Performance Notes

- **First run**: All 71 colors are looked up and cached (~0.5 seconds)
- **Subsequent runs**: 99% hit rate from in-memory cache (instant)
- **DB lookups**: Only for colors NOT in hardcoded map (fallback)
- **Cache size**: ~50-100 entries (negligible memory)

---

## Live Database Color Seeding Steps

### 1. Via phpMyAdmin (Easiest)
```
1. Login to cPanel
2. Click phpMyAdmin
3. Select database: youruser_carsafari
4. Click "SQL" tab
5. Paste the INSERT statements from live_DB_migrate.md
6. Click "Go"
7. Done!
```

### 2. Via SSH (Recommended for automation)
```bash
# Connect
ssh user@yourdomain.com

# Download color seed if needed
cd ~/public_html/carvendors-scraper
git pull origin main

# Run seed
mysql -u youruser_dbuser -p youruser_carsafari < sql/COLOR_SEED_DATA.sql

# Verify
mysql -u youruser_dbuser -p youruser_carsafari -e "SELECT COUNT(*) FROM gyc_vehicle_color;"
```

### 3. One-Liner (If you trust inline SQL)
```bash
mysql -u youruser_dbuser -p youruser_carsafari <<EOF
INSERT IGNORE INTO gyc_vehicle_color (id, color_name, active_status) VALUES
(1,'Beige',1),(2,'Black',1),(3,'Blue',1),(4,'Bronze',1),(5,'Brown',1),(6,'Burgundy',1),
(7,'Gold',1),(8,'Green',1),(9,'Grey',1),(10,'Indigo',1),(11,'Magenta',1),(12,'Mcroon',1),
(13,'Multicolor',1),(14,'Navy',1),(15,'Orange',1),(16,'Pink',1),(17,'Purple',1),(18,'Red',1),
(19,'None',1),(20,'White',1),(21,'Silver',1),(22,'Yellow',1),(23,'Lime',1);
SELECT COUNT(*) as total_colors FROM gyc_vehicle_color;
EOF
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| `color_id: null` in JSON | Run color seed from Step 0 of live_DB_migrate.md |
| "Unknown color" in logs | Add color variant to $map in resolveColorId() |
| Color lookup still slow | Verify cache is working: `$this->colorCache` should grow |
| Dealer uses unique color | Add to gyc_vehicle_color table + update $map |
| Case sensitivity fails | Use `LOWER()` in resolveColorId() (already implemented) |

---

## References

- **Seed Data**: [sql/COLOR_SEED_DATA.sql](sql/COLOR_SEED_DATA.sql)
- **Migration Guide**: [live_DB_migrate.md](live_DB_migrate.md) (Step 0)
- **Code**: [CarSafariScraper.php](CarSafariScraper.php) → `resolveColorId()` method
- **Test JSON**: [data/vehicles.json](data/vehicles.json)
