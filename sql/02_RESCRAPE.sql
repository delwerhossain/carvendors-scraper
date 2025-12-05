-- ============================================================================
-- CarVendors Scraper - Re-Scrape & Maintenance Queries
-- ============================================================================
-- Run these queries to reset data for a fresh scrape or clean up old data
--
-- Usage:
--   Option A: Reset everything and start fresh
--     mysql -u root -p database_name < sql/02_RESCRAPE.sql
--
--   Option B: Delete only old vehicles (from 30+ days ago)
--     mysql -u root -p -e "DELETE FROM gyc_vehicle_info WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);"
--
-- ============================================================================

-- ============================================================================
-- OPTION 1: FULL RESET (Delete all vehicles, keep table structure)
-- ============================================================================
-- Use this when you want to start completely fresh
-- WARNING: This deletes ALL vehicle data!

-- Step 1: Delete all images (dependent table first)
DELETE FROM `gyc_product_images` WHERE 1=1;

-- Step 2: Delete all vehicles
DELETE FROM `gyc_vehicle_info` WHERE 1=1;

-- Step 3: Delete all specs
DELETE FROM `gyc_vehicle_attribute` WHERE 1=1;

-- Step 4: Reset auto-increment counters (optional, for cleaner IDs)
ALTER TABLE `gyc_product_images` AUTO_INCREMENT = 1;
ALTER TABLE `gyc_vehicle_info` AUTO_INCREMENT = 1;
ALTER TABLE `gyc_vehicle_attribute` AUTO_INCREMENT = 1;

-- ============================================================================
-- OPTION 2: DELETE ONLY OLD VEHICLES (Keep recent data)
-- ============================================================================
-- Use this to remove vehicles scraped more than 30 days ago
-- Keeps recent vehicles in database

-- Delete images for old vehicles (vehicles created more than 30 days ago)
DELETE FROM `gyc_product_images`
WHERE vehicle_info_id IN (
  SELECT id FROM `gyc_vehicle_info` 
  WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
);

-- Delete old vehicles
DELETE FROM `gyc_vehicle_info`
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- ============================================================================
-- OPTION 3: SOFT DELETE - Archive OLD Vehicles (Recommended)
-- ============================================================================
-- Use this to mark old vehicles as archived instead of deleting
-- Keeps historical data while removing from active listings

-- Mark vehicles from 30+ days ago as archived
UPDATE `gyc_vehicle_info`
SET active_status = 3
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) 
  AND active_status != 2;  -- Don't re-archive sold vehicles

-- ============================================================================
-- UTILITY QUERIES (Run individual queries for specific tasks)
-- ============================================================================

-- CHECK 1: Show database statistics
-- SELECT 
--   (SELECT COUNT(*) FROM gyc_vehicle_info) as total_vehicles,
--   (SELECT COUNT(*) FROM gyc_product_images) as total_images,
--   (SELECT AVG(COUNT(*)) FROM gyc_product_images GROUP BY vehicle_info_id) as avg_images_per_vehicle,
--   (SELECT COUNT(*) FROM gyc_vehicle_info WHERE active_status = 1) as live_vehicles,
--   (SELECT COUNT(*) FROM gyc_vehicle_info WHERE active_status = 2) as sold_vehicles,
--   (SELECT COUNT(*) FROM gyc_vehicle_info WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)) as added_today;

-- CHECK 2: Find duplicate registrations
-- SELECT reg_no, COUNT(*) as count 
-- FROM gyc_vehicle_info 
-- GROUP BY reg_no HAVING COUNT(*) > 1;

-- CHECK 3: Find vehicles with no images
-- SELECT id, reg_no, attention_grabber 
-- FROM gyc_vehicle_info 
-- WHERE id NOT IN (SELECT DISTINCT vehicle_info_id FROM gyc_product_images);

-- CHECK 4: Find vehicles with NULL/empty critical fields
-- SELECT id, reg_no, color, transmission 
-- FROM gyc_vehicle_info 
-- WHERE color IS NULL OR transmission IS NULL;

-- CHECK 5: Count vehicles by status
-- SELECT active_status, 
--   CASE active_status 
--     WHEN 0 THEN 'Draft'
--     WHEN 1 THEN 'Live'
--     WHEN 2 THEN 'Sold'
--     WHEN 3 THEN 'Archived'
--     ELSE 'Unknown'
--   END as status_name,
--   COUNT(*) as count
-- FROM gyc_vehicle_info
-- GROUP BY active_status;

-- ============================================================================
-- MAINTENANCE TASKS
-- ============================================================================

-- TASK 1: Mark sold vehicles as inactive (if vendor marks them)
-- UPDATE gyc_vehicle_info 
-- SET active_status = 2 
-- WHERE reg_no IN ('WP66UEX', 'YD16EAS');  -- Replace with actual reg numbers

-- TASK 2: Re-publish a vehicle (set from draft to live)
-- UPDATE gyc_vehicle_info 
-- SET active_status = 1 
-- WHERE reg_no = 'WP66UEX';

-- TASK 3: Update vehicle price (if needed)
-- UPDATE gyc_vehicle_info 
-- SET selling_price = 9990 
-- WHERE reg_no = 'WP66UEX';

-- TASK 4: Backup data before scraping
-- Run from terminal:
-- mysqldump -u root -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql

-- TASK 5: Check scraper statistics (if table exists)
-- SELECT scrape_date, found, inserted, updated, images_stored, duration_seconds, status 
-- FROM scraper_statistics 
-- ORDER BY scrape_date DESC LIMIT 10;

-- ============================================================================
-- BEFORE YOU RUN FULL RESET
-- ============================================================================
--
-- 1. BACKUP your database first:
--    mysqldump -u root -p database_name > backup_before_reset.sql
--
-- 2. Choose your reset strategy:
--    - OPTION 1 (Full Reset): Use if starting completely fresh
--    - OPTION 2 (Old Delete): Use if you want to keep recent data
--    - OPTION 3 (Soft Archive): Use if you want to preserve history
--
-- 3. Only uncomment and run the queries you need
--
-- 4. After reset, run the scraper:
--    php scrape-carsafari.php
--
-- ============================================================================
