-- ============================================
-- Car Listings Aggregator - Database Schema
-- ============================================

CREATE TABLE IF NOT EXISTS `vehicles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- Unique identifier: combination of source + external_id
    `source` VARCHAR(50) NOT NULL DEFAULT 'systonautosltd',
    `external_id` VARCHAR(255) NOT NULL,
    
    -- Core vehicle data
    `title` VARCHAR(500) NOT NULL,
    `price` VARCHAR(50) DEFAULT NULL,
    `price_numeric` DECIMAL(10, 2) DEFAULT NULL,  -- For sorting/filtering
    `location` VARCHAR(100) DEFAULT NULL,
    
    -- Vehicle specs
    `mileage` VARCHAR(50) DEFAULT NULL,
    `mileage_numeric` INT UNSIGNED DEFAULT NULL,  -- For sorting/filtering
    `colour` VARCHAR(50) DEFAULT NULL,
    `transmission` VARCHAR(50) DEFAULT NULL,
    `fuel_type` VARCHAR(50) DEFAULT NULL,
    `body_style` VARCHAR(50) DEFAULT NULL,
    `first_reg_date` VARCHAR(50) DEFAULT NULL,
    
    -- Descriptions
    `description_short` TEXT DEFAULT NULL,
    `description_full` TEXT DEFAULT NULL,
    
    -- URLs
    `image_url` VARCHAR(1000) DEFAULT NULL,
    `vehicle_url` VARCHAR(1000) NOT NULL,
    
    -- Status tracking
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_seen_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_source_external_id` (`source`, `external_id`),
    KEY `idx_is_active` (`is_active`),
    KEY `idx_price_numeric` (`price_numeric`),
    KEY `idx_source` (`source`),
    KEY `idx_last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Table to track scrape runs for debugging/monitoring
CREATE TABLE IF NOT EXISTS `scrape_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source` VARCHAR(50) NOT NULL,
    `started_at` DATETIME NOT NULL,
    `finished_at` DATETIME DEFAULT NULL,
    `vehicles_found` INT UNSIGNED DEFAULT 0,
    `vehicles_inserted` INT UNSIGNED DEFAULT 0,
    `vehicles_updated` INT UNSIGNED DEFAULT 0,
    `vehicles_deactivated` INT UNSIGNED DEFAULT 0,
    `status` ENUM('running', 'completed', 'failed') DEFAULT 'running',
    `error_message` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_source_started` (`source`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
