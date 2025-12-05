-- ============================================================================
-- CarVendors Scraper - Initial Database Setup
-- ============================================================================
-- Run this ONCE on a fresh database to create all required tables
-- 
-- Usage:
--   mysql -u root -p database_name < sql/01_INITIAL_SETUP.sql
--
-- Tables created:
--   - gyc_vehicle_info       (Main vehicle records)
--   - gyc_vehicle_attribute  (Specifications)
--   - gyc_product_images     (Image URLs)
--   - scraper_statistics     (Performance tracking)
-- ============================================================================

-- ============================================================================
-- 1. VEHICLE INFO TABLE (Main Records)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `gyc_vehicle_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `attr_id` int(11) DEFAULT NULL COMMENT 'Foreign key to gyc_vehicle_attribute',
  `reg_no` varchar(20) NOT NULL UNIQUE COMMENT 'UK registration number (WP66UEX)',
  `vendor_id` int(11) DEFAULT 432 COMMENT '432 = Systonautos Ltd',
  `selling_price` int(11) DEFAULT NULL COMMENT 'Numeric price in pounds',
  `regular_price` int(11) DEFAULT NULL COMMENT 'Original price',
  `mileage` int(11) DEFAULT NULL COMMENT 'Numeric mileage in miles',
  `color` varchar(100) DEFAULT NULL COMMENT 'Vehicle color (validated against whitelist)',
  `description` longtext COMMENT 'Full vehicle description with specs',
  `attention_grabber` varchar(255) DEFAULT NULL COMMENT 'Title/headline',
  `vehicle_url` varchar(500) DEFAULT NULL COMMENT 'Link to detail page',
  `doors` int(2) DEFAULT NULL COMMENT 'Number of doors',
  `registration_plate` varchar(10) DEFAULT NULL COMMENT 'Plate code (66 from 66 plate)',
  `drive_system` varchar(50) DEFAULT NULL COMMENT 'AWD/FWD/RWD/4WD',
  `post_code` varchar(20) DEFAULT 'LE7 1NS' COMMENT 'Dealer postcode',
  `address` varchar(255) DEFAULT 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS' COMMENT 'Dealer address',
  `drive_position` varchar(20) DEFAULT 'Right' COMMENT 'Left/Right hand drive',
  `v_condition` varchar(50) DEFAULT 'USED' COMMENT 'USED/NEW/RECONDITIONED',
  `active_status` int(1) DEFAULT 0 COMMENT '0=Draft, 1=Live (published), 2=Sold, 3=Archived',
  `data_hash` varchar(64) DEFAULT NULL COMMENT 'MD5 hash for change detection',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- INDEXES for performance
  KEY `idx_reg_no` (`reg_no`),
  KEY `idx_vendor_id` (`vendor_id`),
  KEY `idx_active_status` (`active_status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. VEHICLE ATTRIBUTE TABLE (Specifications)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `gyc_vehicle_attribute` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `category_id` int(11) DEFAULT NULL COMMENT 'Vehicle category ID',
  `make_id` int(11) DEFAULT NULL COMMENT 'Make ID (Volvo, Nissan, etc)',
  `model` varchar(255) DEFAULT NULL COMMENT 'Model name',
  `year` int(4) DEFAULT NULL COMMENT 'Registration year',
  `transmission` varchar(50) DEFAULT NULL COMMENT 'Manual/Automatic/CVT',
  `fuel_type` varchar(50) DEFAULT NULL COMMENT 'Diesel/Petrol/Hybrid/Electric',
  `body_style` varchar(100) DEFAULT NULL COMMENT 'Hatchback/Sedan/SUV/Coupe',
  `engine_size` int(6) DEFAULT NULL COMMENT 'Engine displacement in CC',
  `active_status` int(1) DEFAULT 1 COMMENT '0=Inactive, 1=Active',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  KEY `idx_make_id` (`make_id`),
  KEY `idx_year` (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. PRODUCT IMAGES TABLE (Image URLs)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `gyc_product_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `vehicle_info_id` int(11) NOT NULL COMMENT 'Foreign key to gyc_vehicle_info',
  `file_name` varchar(500) DEFAULT NULL COMMENT 'Full image URL from CDN',
  `serial` int(3) DEFAULT 1 COMMENT 'Image serial: 1, 2, 3, ... (supports multiple images)',
  `cratead_at` timestamp DEFAULT CURRENT_TIMESTAMP COMMENT 'Created timestamp',
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- INDEXES
  KEY `idx_vehicle_info_id` (`vehicle_info_id`),
  FOREIGN KEY (`vehicle_info_id`) REFERENCES `gyc_vehicle_info`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. SCRAPER STATISTICS TABLE (Performance Tracking)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `scraper_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `scrape_date` date NOT NULL COMMENT 'Date of scrape run',
  `found` int(11) DEFAULT 0 COMMENT 'Total vehicles found',
  `inserted` int(11) DEFAULT 0 COMMENT 'New vehicles inserted',
  `updated` int(11) DEFAULT 0 COMMENT 'Existing vehicles updated',
  `skipped` int(11) DEFAULT 0 COMMENT 'Vehicles skipped (no changes)',
  `images_stored` int(11) DEFAULT 0 COMMENT 'Total image URLs stored',
  `errors` int(11) DEFAULT 0 COMMENT 'Number of errors',
  `duration_seconds` int(11) DEFAULT 0 COMMENT 'Scrape duration in seconds',
  `memory_used_mb` decimal(10,2) DEFAULT 0 COMMENT 'Peak memory usage in MB',
  `status` varchar(50) DEFAULT 'success' COMMENT 'success/failed/partial',
  `log_file` varchar(255) DEFAULT NULL COMMENT 'Path to log file',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  
  KEY `idx_scrape_date` (`scrape_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. CREATE SAMPLE DATA (Optional)
-- ============================================================================
-- This is just an example of what data looks like in the database
-- The scraper will populate this automatically

INSERT IGNORE INTO `gyc_vehicle_attribute` 
(`id`, `category_id`, `model`, `year`, `transmission`, `fuel_type`, `body_style`, `engine_size`, `active_status`)
VALUES 
(613, 1, 'Volvo V40 2.0 D4', 2016, 'Manual', 'Diesel', 'Hatchback', 1969, 1),
(614, 1, 'Nissan Micra 1.2', 2014, 'Automatic', 'Petrol', 'Hatchback', 1198, 1),
(615, 1, 'Mercedes E-Class 2.1', 2012, 'Automatic', 'Diesel', 'Sedan', 2143, 1);

INSERT IGNORE INTO `gyc_vehicle_info` 
(`id`, `attr_id`, `reg_no`, `vendor_id`, `selling_price`, `mileage`, `color`, `attention_grabber`, `vehicle_url`, `doors`, `v_condition`, `active_status`)
VALUES 
(1222, 613, 'WP66UEX', 432, 8990, 75000, 'Silver', 'Volvo V40 2.0 D4 5dr - 2016 (66 plate)', 'https://systonautosltd.co.uk/vehicle/name/volvo-v40-2-0-d4-r-design-nav-plus-euro-6-s-s-5dr/', 5, 'USED', 1),
(1223, 614, 'MJ64YNN', 432, 7750, 62000, 'Green', 'Nissan Micra 1.2 Acenta CVT 5dr - 2014 (64 plate)', 'https://systonautosltd.co.uk/vehicle/name/nissan-micra-1-2-acenta-cvt-euro-5-5dr/', 5, 'USED', 1),
(1224, 615, 'ML62YDR', 432, 12500, 45000, 'Black', 'Mercedes E-Class 2.1 BlueTec 4dr - 2012 (62 plate)', 'https://systonautosltd.co.uk/vehicle/name/mercedes-benz-e-class-2-1-e300dh-bluetec/', 4, 'USED', 1);

INSERT IGNORE INTO `gyc_product_images` 
(`vehicle_info_id`, `file_name`, `serial`)
VALUES 
(1222, 'https://aacarsdna.com/images/vehicles/03/large/388d92d46a822dc623439124bcabd18f.jpg', 1),
(1222, 'https://aacarsdna.com/images/vehicles/03/large/f32442a8934ebdeed3475d5e72672b10.jpg', 2),
(1223, 'https://aacarsdna.com/images/vehicles/04/large/abc123def456ghi789.jpg', 1),
(1224, 'https://aacarsdna.com/images/vehicles/05/large/xyz999abc888def777.jpg', 1);

-- ============================================================================
-- 6. VERIFICATION QUERIES
-- ============================================================================
-- Run these to verify setup completed successfully:

-- CHECK 1: Verify all tables exist
-- SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE();

-- CHECK 2: Verify vehicle count
-- SELECT COUNT(*) as total_vehicles FROM gyc_vehicle_info;

-- CHECK 3: Verify image count
-- SELECT COUNT(*) as total_images FROM gyc_product_images;

-- CHECK 4: Sample data
-- SELECT reg_no, color, transmission FROM gyc_vehicle_info WHERE vendor_id = 432 LIMIT 5;

-- ============================================================================
-- DATABASE SETUP COMPLETE!
-- ============================================================================
-- 
-- You can now run the scraper:
--   php scrape-carsafari.php
--
-- ============================================================================
