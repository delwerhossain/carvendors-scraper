# Safe Daily Refresh Implementation

## Overview

This document describes the **safe daily refresh system** implemented in `daily_refresh.php` and `CarSafariScraper.php`. The system protects your live website from inventory dropping to zero during bad scrape runs.

**Key Principle**: Scrape first → validate health → cleanup only if healthy

---

## The Problem It Solves

**Before**: If a scrape failed or returned bad data, the cleanup phase would delete all old vehicle data, leaving the website with 0 inventory.

**After**: The system validates data quality before any cleanup. If metrics fall below thresholds, cleanup is skipped and a warning email is sent. Live inventory remains intact.

---

## Safety Gates (Health Checks)

Two metrics must both pass before cleanup is approved:

### 1. Success Rate (Default: 85%)
```php
$successRate = $processed / $found
// where: processed = inserted + updated + skipped
//        found = vehicles scraped

// GATE: successRate >= 0.85 (configurable)
```

**What it means**: If you scrape 100 vehicles but can only successfully process 84 of them, cleanup is skipped.

### 2. Inventory Ratio (Default: 80%)
```php
$inventoryRatioPct = $newActiveCount / $currentActiveCount

// GATE: newActiveCount >= (currentActiveCount * 0.80)
```

**What it means**: New inventory must be at least 80% of previous inventory. If current site has 70 vehicles and new data only has 50, cleanup is skipped.

---

## Execution Flow

### Phase 1: Scraping (No live impact)
- Fetch data from dealer website
- Parse vehicle cards, enrich with detail pages
- Save to database with change detection (only updated vehicles)
- Auto-publish new vehicles (active_status = 1)
- **Database state**: Live tables updated with new/changed data only

### Phase 2: Safety Validation
- Calculate success rate (must be >= 85%)
- Count new active inventory (must be >= 80% of current)
- **Decision point**: Is data healthy?

### Phase 3: Cleanup (Only if healthy)
If both metrics pass:
- Deactivate vehicles not in current scrape (soft-delete)
- Remove old inactive vehicles (>30 days, hard delete)
- Optimize tables (weekly)

If metrics fail:
- **DO NOTHING** - keep all existing data
- Send alert email with failure reason
- Exit with code 2 (non-critical failure)

---

## Configuration

Edit the thresholds in `daily_refresh.php`:

```php
$minSuccessRate = 0.85;    // 85% (change to 0.90 for 90%)
$minInventoryRatio = 0.80;  // 80% (change to 0.85 for 85%)
```

### Recommended Thresholds
- **Development**: 0.70 / 0.70 (permissive)
- **Staging**: 0.80 / 0.75 (moderate)
- **Production**: 0.85 / 0.80 (strict)

---

## Usage

### Standard Daily Refresh
```bash
php daily_refresh.php --vendor=432
```

Output example:
```
============================================
Safe Daily Refresh - 2025-12-20 14:30:45
Vendor ID: 432
Force Mode: NO
============================================

Current active inventory: 70 vehicles

Phase 1: Scraping new data...
Scraping completed in 1.32 seconds
  Found: 71
  Inserted: 2
  Updated: 1
  Skipped: 68
  Errors: 0

Phase 2: Safety validation...
  Success Rate: 100.0% (required: 85.0%)
  Inventory Ratio: 101.4% (required: 80.0%)
  Previous inventory: 70 → Current: 71

Phase 3: CLEANUP APPROVED - Metrics are healthy
  Deactivating vehicles not in current scrape...
  Deactivated 0 vehicles not in current scrape
  Deleted 0 old inactive vehicles (>30 days)
  Optimizing tables...
  Tables optimized

=============================================
DAILY REFRESH COMPLETED SUCCESSFULLY
=============================================
```

### With Force Mode
```bash
php daily_refresh.php --vendor=432 --force
```

Note: `--force` does NOT bypass safety gates; it only forces weekly table optimization.

### Help
```bash
php daily_refresh.php --help
```

---

## Failure Scenario Example

**Situation**: Website has 70 vehicles, but scrape only finds 30 (maybe dealer website is down).

```
Phase 1: Scraping new data...
  Found: 30
  Inserted: 5
  Updated: 0
  Skipped: 25
  Errors: 0

Phase 2: Safety validation...
  Success Rate: 100.0% (required: 85.0%)
  Inventory Ratio: 42.9% (required: 80.0%)   <-- FAILS
  Previous inventory: 70 → Current: 30

Phase 3: CLEANUP SKIPPED - Safety thresholds NOT met
  Reason: Inventory ratio 42.9% < 80.0%
  LIVE INVENTORY PRESERVED - No deactivation performed
```

**Result**: 
- Website still shows all 70 original vehicles
- New 30 vehicles merged with existing 70
- Alert email sent: "Inventory dropped 42.9% - investigation needed"
- No data loss

---

## Database Impact

### Tables Modified
- `gyc_vehicle_info` - vehicles saved/deactivated
- `gyc_product_images` - images for new/updated vehicles
- `gyc_vehicle_attribute` - specs for new vehicles

### No Staging Tables
This implementation uses **ZERO staging tables**. All data is written directly to live tables:
1. ✅ No `gyc_vehicle_info_stage`
2. ✅ No `gyc_vehicle_attribute_stage`
3. ✅ No `gyc_product_images_stage`

The safety gate is implemented via health metrics, not via separate staging.

---

## Deactivation Strategy

When cleanup is approved, vehicles are marked inactive in two scenarios:

### 1. Vehicles Not in Scrape (Soft-Deactivate)
```sql
UPDATE gyc_vehicle_info 
SET active_status = '0'
WHERE vendor_id = 432 
  AND id NOT IN (vehicles from current scrape)
  AND active_status IN ('1', '2')
```

### 2. Old Inactive Vehicles (Hard-Delete)
```sql
DELETE FROM gyc_vehicle_info 
WHERE vendor_id = 432 
  AND active_status = '0' 
  AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAYS)
LIMIT 1000
```

The second query ensures old, never-activated vehicles are cleaned up gradually.

---

## Error Handling

### Scrape Fails Completely
```
if (!$result['success']) {
    throw new Exception("Scraping failed: ...");
}
```
- **Action**: Send alert, exit code 2, keep all data
- **Email**: "Scrape failed: [error reason]"

### Health Check Fails
- **Action**: Log reason, skip cleanup, send alert, exit code 0 (success - data preserved)
- **Email**: "Health check failed: Success rate 60% < 85%, Inventory ratio 50% < 80%"

### Database Errors During Commit
- **Action**: PHP exception → caught → alert email, exit code 1
- **Email**: "Daily refresh failed: [SQL error]"

---

## Email Alerts

All runs send an alert email with format:
```
Subject: CarVendors Scraper Alert - [Vendor] - [Status]

Status: [HEALTHY | UNHEALTHY | FAILED]
Success Rate: 100.0% (required: 85.0%)
Inventory: 70 → 71
Cleanup: APPROVED | SKIPPED (protection enabled)
Errors: 0
Timestamp: 2025-12-20 14:30:45
```

See `mail_alert.php` for customization.

---

## Tuning and Monitoring

### Monitor Success Rate Trend
```sql
SELECT 
    execution_date,
    (inserted + updated + skipped) / found * 100 AS success_rate_pct
FROM scraper_statistics
WHERE vendor_id = 432
ORDER BY execution_date DESC
LIMIT 30;
```

### Detect Why Cleanup Skipped
Check logs and email alerts:
```bash
tail -50 logs/scraper_2025-12-20.log
```

### Adjust Thresholds Over Time
If you consistently get warnings at 85% success:
- Option 1: Increase threshold to 0.90 (stricter)
- Option 2: Investigate root causes and fix parsing

---

## Key Files

| File | Purpose | What Changed |
|------|---------|--------------|
| **daily_refresh.php** | Orchestrator | Now checks health before cleanup |
| **CarSafariScraper.php** | Scraper logic | Removed cleanup logic (moved to daily_refresh) |
| **.github/copilot-instructions.md** | AI guidance | Updated with safety gate details |

---

## Comparison: Old vs New

| Aspect | Old | New |
|--------|-----|-----|
| **Timing** | Cleanup → Scrape | Scrape → Validate → Cleanup |
| **Risk** | Zero inventory if bad run | Inventory preserved if bad run |
| **Deactivation** | Unconditional | Only if healthy metrics |
| **Alert** | After completion | On failure with details |
| **Tables** | Live only | Live only (no staging) |
| **Control** | In CarSafariScraper | In daily_refresh.php |

---

## FAQ

**Q: Will cleanup run automatically?**
A: Only if both health metrics pass. Otherwise skipped silently with alert email.

**Q: Can I force cleanup even if metrics fail?**
A: Not with `--force` flag (it only forces optimization). Manual intervention required.

**Q: What if thresholds are too strict?**
A: Adjust in daily_refresh.php line 43-44, then re-run.

**Q: Why count inventory after scrape?**
A: Because new vehicles are auto-published (active_status=1), so we count before cleanup decision.

**Q: Can I use this with other vendors?**
A: Yes. Pass `--vendor=ID` to isolate data: `php daily_refresh.php --vendor=433`

---

**Status**: ✅ Production Ready  
**Last Updated**: December 20, 2025  
**Database Impact**: None (no new tables, existing schema only)
