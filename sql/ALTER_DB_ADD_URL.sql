-- Add vehicle_url column to gyc_vehicle_info (safe to run multiple times)
ALTER TABLE gyc_vehicle_info ADD COLUMN vehicle_url VARCHAR(500) DEFAULT NULL;

-- Add INDEX for faster lookups (will be created even if column exists)
CREATE INDEX idx_vehicle_url ON gyc_vehicle_info(vehicle_url);
