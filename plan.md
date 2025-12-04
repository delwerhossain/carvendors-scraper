# 🚀 CarVendors Scraper - Optimization Plan

## Current Issues Identified

### Critical Bugs
1. **Database Column Name Typo**: `vechicle_info_id` vs `vehicle_info_id` - causes JSON generation to fail
2. **Inserted: 0, Updated: 0** - ON DUPLICATE KEY not triggering because reg_no lacks UNIQUE constraint
3. **Published: 0** - Auto-publish logic condition always false

### Performance Issues
1. No change detection - re-processes all 81 vehicles every run
2. No data comparison - can't tell if data actually changed
3. JSON files accumulating (vehicles1.json, vehicles2.json, vehicles3.json)
4. Log files accumulating without cleanup

### Missing Features
1. Smart insert/update with actual change detection
2. JSON file rotation (keep last 2 only)
3. Log file rotation (keep last 7 days)
4. Hash-based duplicate detection
5. Statistics tracking for actual inserts vs updates

---

## Implementation Plan (Step by Step)

### Phase 1: Critical Bug Fixes (15 min)

#### Step 1.1: Fix Column Name Typo
**File:** `CarSafariScraper.php`
**Issue:** `vehicle_info_id` should be `vechicle_info_id` (matching DB schema)
```php
// Line ~487 - Change:
(SELECT COUNT(*) FROM gyc_product_images WHERE vehicle_info_id = v.id)
// To:
(SELECT COUNT(*) FROM gyc_product_images WHERE vechicle_info_id = v.id)
```

#### Step 1.2: Add UNIQUE Index on reg_no
**File:** `sql/ADD_UNIQUE_REG_NO.sql` (new)
```sql
ALTER TABLE gyc_vehicle_info ADD UNIQUE INDEX idx_reg_no (reg_no);
```

#### Step 1.3: Fix Insert/Update Logic
**File:** `CarSafariScraper.php`
**Issue:** ON DUPLICATE KEY needs UNIQUE constraint to work
- Add proper change detection
- Track actual inserts vs updates

---

### Phase 2: Smart Change Detection (30 min)

#### Step 2.1: Create Data Hash Function
```php
private function generateVehicleHash(array $vehicle): string {
    $data = [
        'price' => $vehicle['price'],
        'mileage' => $vehicle['mileage'],
        'title' => $vehicle['title'],
        'description' => $vehicle['description_full'] ?? '',
    ];
    return md5(json_encode($data));
}
```

#### Step 2.2: Add Hash Column to Database
```sql
ALTER TABLE gyc_vehicle_info ADD COLUMN data_hash VARCHAR(32) NULL;
```

#### Step 2.3: Implement Change Detection Logic
```php
// Before insert/update:
$currentHash = $this->generateVehicleHash($vehicle);
$existingHash = $this->getExistingHash($vehicle['external_id']);

if ($existingHash === $currentHash) {
    $this->stats['skipped']++;
    continue; // No changes, skip
}
```

---

### Phase 3: File Management (20 min)

#### Step 3.1: JSON File Rotation
**Logic:** Keep only last 2 JSON snapshots
```php
private function rotateJsonFiles(): void {
    $pattern = 'data/vehicles_*.json';
    $files = glob($pattern);
    
    // Sort by modification time
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    
    // Keep last 2, delete rest
    foreach (array_slice($files, 2) as $file) {
        unlink($file);
        $this->log("Deleted old JSON: $file");
    }
}
```

#### Step 3.2: Log File Cleanup
**Logic:** Keep logs from last 7 days only
```php
private function cleanupOldLogs(): void {
    $pattern = 'logs/scraper_*.log';
    $files = glob($pattern);
    $cutoff = strtotime('-7 days');
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            unlink($file);
            $this->log("Deleted old log: $file");
        }
    }
}
```

#### Step 3.3: Timestamped JSON Naming
```php
// Change from: vehicles.json
// To: vehicles_20251204_142437.json
$filename = 'data/vehicles_' . date('Ymd_His') . '.json';
```

---

### Phase 4: Project Structure Optimization (20 min)

#### Step 4.1: New Directory Structure
```
carvendors-scraper/
├── src/                    # Core classes
│   ├── CarScraper.php
│   ├── CarSafariScraper.php
│   └── CarCheckIntegration.php
├── config/                 # Configuration
│   └── config.php
├── data/                   # JSON outputs (auto-rotated)
│   └── .gitkeep
├── logs/                   # Log files (auto-cleaned)
│   └── .gitkeep
├── sql/                    # Database migrations
│   └── migrations/
├── bin/                    # CLI scripts
│   ├── scrape.php
│   └── optimize.php
├── docs/                   # Documentation
│   ├── README.md
│   └── PLAN.md
└── tests/                  # Future: Unit tests
```

#### Step 4.2: Autoloader Setup
```php
// autoload.php
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
```

---

### Phase 5: Enhanced Statistics (15 min)

#### Step 5.1: New Stats Array
```php
protected array $stats = [
    'found' => 0,
    'inserted' => 0,
    'updated' => 0,
    'skipped' => 0,      // No changes detected
    'deactivated' => 0,
    'images_stored' => 0,
    'errors' => 0,
];
```

#### Step 5.2: Summary Report
```php
private function generateSummaryReport(): void {
    $report = "
=== SCRAPE SUMMARY ===
Date: " . date('Y-m-d H:i:s') . "
Duration: {$this->duration} seconds

VEHICLES:
  Found on website: {$this->stats['found']}
  New (inserted): {$this->stats['inserted']}
  Changed (updated): {$this->stats['updated']}
  Unchanged (skipped): {$this->stats['skipped']}
  Removed (deactivated): {$this->stats['deactivated']}
  
IMAGES:
  Stored: {$this->stats['images_stored']}
  
ERRORS: {$this->stats['errors']}
======================
";
    $this->log($report);
}
```

---

## Execution Order (Fast Implementation)

### Immediate Fixes (Do Now) - 10 min
1. ✅ Fix `vechicle_info_id` column name in SQL queries
2. ✅ Add stats tracking for actual changes
3. ✅ Simplify JSON output to single file

### Quick Wins (Do Next) - 20 min
4. Add JSON rotation (keep last 2)
5. Add log cleanup (keep last 7 days)
6. Add better summary output

### Full Optimization (Do Later) - 30 min
7. Add hash-based change detection
8. Restructure project folders
9. Add proper error handling

---

## SQL Migrations Needed

```sql
-- Migration 1: Fix unique constraint
ALTER TABLE gyc_vehicle_info 
ADD UNIQUE INDEX idx_reg_no (reg_no);

-- Migration 2: Add hash column for change detection
ALTER TABLE gyc_vehicle_info 
ADD COLUMN data_hash VARCHAR(32) NULL AFTER vehicle_url;

-- Migration 3: Add index for faster lookups
ALTER TABLE gyc_vehicle_info 
ADD INDEX idx_vendor_status (vendor_id, active_status);
```

---

## Expected Results After Optimization

| Metric | Before | After |
|--------|--------|-------|
| Insert/Update Detection | ❌ Broken | ✅ Working |
| Change Detection | ❌ None | ✅ Hash-based |
| Duplicate Processing | All 81 every run | Only changed |
| JSON Files | Accumulating | Max 2 files |
| Log Files | Accumulating | Max 7 days |
| Run Output | Confusing | Clear summary |
| Error Handling | Basic | Comprehensive |

---

## Commands After Implementation

```bash
# Run daily scrape
php bin/scrape.php

# Run with verbose output
php bin/scrape.php --verbose

# Dry run (no database changes)
php bin/scrape.php --dry-run

# Force full refresh (ignore hashes)
php bin/scrape.php --force
```

---

## Ready to Implement?

Say **"go"** and I'll implement all these changes step by step, starting with the critical bug fixes.

**Estimated Total Time: 1 hour**
