-- Migration 1: Add UNIQUE constraint on reg_no for proper insert/update
-- This enables ON DUPLICATE KEY UPDATE to work correctly
-- Date: 2025-12-04

ALTER TABLE gyc_vehicle_info 
DROP INDEX IF EXISTS idx_reg_no,
ADD UNIQUE INDEX idx_reg_no (reg_no);

-- Migration 2: Add index for faster lookups by vendor and status
ALTER TABLE gyc_vehicle_info 
ADD INDEX idx_vendor_status (vendor_id, active_status);

-- Migration 3: Add data_hash column for change detection
ALTER TABLE gyc_vehicle_info 
ADD COLUMN data_hash VARCHAR(32) NULL COMMENT 'MD5 hash of vehicle data for change detection' AFTER vehicle_url;

-- Migration 4: Ensure charset is utf8mb4 for proper text handling
ALTER TABLE gyc_vehicle_info 
CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE gyc_vehicle_attribute 
CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE gyc_product_images 
CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
