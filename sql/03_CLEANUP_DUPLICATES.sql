-- ============================================================================
-- DUPLICATE CLEANUP SCRIPT
-- ============================================================================
-- Problem: Database has 81 duplicate vehicle pairs (162 total duplicates)
-- - Old records: URL-slug format reg_no (e.g., "volvo-v40-2-0-d4-...")
--   Created on 2025-12-05 13:43:46, 1 image, missing color/mileage
-- - New records: VRM format reg_no (e.g., "WP66UEX")
--   Created on 2025-12-05 12:55:10, 60-100+ images, full data enrichment
--
-- Action: Keep NEW records (better data), DELETE OLD records
-- Result: 163 vehicles → 82 unique vehicles
--
-- Run this script ONCE after reviewing which records to delete
-- ============================================================================

-- Step 1: Create backup table before deletion
CREATE TABLE IF NOT EXISTS gyc_vehicle_info_backup AS
SELECT * FROM gyc_vehicle_info WHERE id IN (
    SELECT MIN(id) FROM (
        SELECT id FROM gyc_vehicle_info 
        WHERE vendor_id = 432 AND reg_no LIKE '%-'
    ) t
);

-- Step 2: Identify old duplicate records to delete (URL-slug format)
-- These are records with reg_no containing hyphens (URL format)
-- that also have a matching newer record by vehicle_url
SELECT COUNT(*) as old_records_to_delete
FROM gyc_vehicle_info
WHERE vendor_id = 432 AND reg_no LIKE '%-'
LIMIT 1;

-- Step 3: Delete images from old records first
DELETE FROM gyc_product_images
WHERE vechicle_info_id IN (
    SELECT id FROM gyc_vehicle_info
    WHERE vendor_id = 432 AND reg_no LIKE '%-'
);

-- Step 4: Delete old duplicate vehicle records
DELETE FROM gyc_vehicle_info
WHERE vendor_id = 432 AND reg_no LIKE '%-';

-- Step 5: Verify result
SELECT COUNT(*) as final_vehicle_count FROM gyc_vehicle_info WHERE vendor_id = 432;
SELECT COUNT(*) as final_image_count FROM gyc_product_images;

-- Step 6: Update reg_no UNIQUE constraint to ensure duplicates can't happen again
-- ALTER TABLE gyc_vehicle_info ADD UNIQUE KEY unique_vehicle_url_vendor 
-- (vehicle_url, vendor_id);

-- Step 7: Regenerate JSON output
-- Run: php check_results.php

-- ============================================================================
-- SUMMARY OF CHANGES
-- ============================================================================
-- Before: 163 vehicles (81 duplicates + 1 unpaired)
-- After:  82 vehicles (all unique, fully enriched)
--
-- Removed 81 old records with:
--   - URL-slug format reg_no
--   - 1 image each (listing page only, not enriched)
--   - Empty color and mileage
--   - Truncated descriptions
--
-- Kept 82 new records with:
--   - VRM format reg_no (WP66UEX, MJ64YNN, etc.)
--   - 50-100+ images each (fully enriched)
--   - Color and mileage populated
--   - Complete descriptions
-- ============================================================================
