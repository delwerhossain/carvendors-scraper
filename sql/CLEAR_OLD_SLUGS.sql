-- Clear old scraped data with wrong reg_no format (URL slugs)
-- This removes vehicles where reg_no looks like URL slugs instead of UK VRM

-- First, delete images for these vehicles
DELETE pi FROM gyc_product_images pi
JOIN gyc_vehicle_info vi ON pi.vechicle_info_id = vi.id
WHERE vi.vendor_id = 432
  AND vi.reg_no LIKE '%-%-%';  -- URL slugs have multiple dashes

-- Then delete attributes
DELETE va FROM gyc_vehicle_attribute va
JOIN gyc_vehicle_info vi ON va.id = vi.attr_id
WHERE vi.vendor_id = 432
  AND vi.reg_no LIKE '%-%-%';

-- Finally delete vehicles with URL slug reg_no
DELETE FROM gyc_vehicle_info 
WHERE vendor_id = 432 
  AND reg_no LIKE '%-%-%';

-- Show remaining vehicles
SELECT COUNT(*) AS remaining_vehicles FROM gyc_vehicle_info WHERE vendor_id = 432;
