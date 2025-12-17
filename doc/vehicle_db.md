# Vehicle Database Map

This document summarizes the core vehicle data model from `sql/full_DB.sql` / `sql/main.sql` for quick reference when writing scrapers or data loaders.

## Core Tables

- **gyc_vehicle_attribute**
  - Purpose: Canonical spec set (make/model/trim/etc.) that multiple vehicle records can reference.
  - Key columns: `id` (PK), `category_id` (→ gyc_category.id), `make_id` (→ gyc_make.id), `model`, `generation`, `trim`, `engine_size`, `fuel_type`, `transmission`, `derivative`, `gearbox`, `year`, `body_style`, `active_status`, timestamps.

- **gyc_vehicle_info**
  - Purpose: The per-vehicle listing row.
  - Key columns: `id` (PK), `attr_id` (→ gyc_vehicle_attribute.id), `reg_no`, `reg_date`, `selling_price`, `regular_price`, `post_code`, `address`, `mileage`, `registration_plate`, `tax_band`, `color` (text), `color_id` / `manufacturer_color_id` / `interior_color_id` (→ gyc_vehicle_color.id), `seats`, `doors`, `v_condition` (USED/NEW), `feature_id` (comma-separated gyc_features ids), `tag_id` (→ gyc_vehicle_tag.id), `trim_id` (→ gyc_vehicle_trim.id), `exterior_finish_id` (→ gyc_vehicle_exterior_finish.id), `vendor_id` (→ gyc_vendor_info.id), `engine_no`, `drive_system`, `drive_position`, `description`, `attention_grabber`, `youtube_link`, `service_history`, `last_service_date`, `miles_service`, `mot_expiry_date`, `mot_included`, `mot_insurance`, `warranty`, `owner`, `interior_condition`, `exterior_condition`, `active_status` (0 pending / 1 waiting / 2 published / 3 sold / 4 blocked), `publish_date`, visit counters, `vehicle_url`, timestamps.

- **gyc_product_images**
  - Purpose: Stores listing images.
  - Relationship: `vechicle_info_id` (FK to gyc_vehicle_info.id), `file_name`, `serial` (ordering).

## Reference Tables

- **gyc_make**: `id`, `name`, `cat_id`, `active_status`, timestamps.
- **gyc_category**: vehicle category lookup (id/name).
- **gyc_vehicle_color**: `id`, `color_name`, `active_status`, timestamps.
- **gyc_vehicle_trim**: `id`, `trim_name`, `active_status`, timestamps.
- **gyc_vehicle_tag**: tagging metadata (`id`, `name`, `tag_color`, `active_status`, timestamps).
- **gyc_vehicle_exterior_finish**: `id`, `exterior_finish_name`, `active_status`, timestamps.
- **gyc_vehicle_condition**: `id`, `condition_name`, `active_status`, timestamps.
- **gyc_vehicle_service_history**: `id`, `service_history_name`, `active_status`, timestamps.
- **gyc_features** (referenced via comma-separated ids in `feature_id`).
- **gyc_vendor_info**: vendors/dealers; `vendor_id` in gyc_vehicle_info points here.

## Important View

- **gyc_v_vechicle_info**
  - Joins `gyc_vehicle_info` to: `gyc_vehicle_attribute` (specs), `gyc_make`, `gyc_category`, `gyc_vehicle_color` (color/manufacturer/interior), `gyc_vehicle_exterior_finish`, `gyc_vehicle_tag`, `gyc_vendor_info`, `gyc_features` (via find_in_set on feature_id), and primary image from `gyc_product_images` (`serial = 1`).
  - Provides a denormalized row with vendor status, make/model/year/specs, colors, drive, price, tag/feature names, and first image.

## Common Relationships (practical guide)

- `gyc_vehicle_info.attr_id` → `gyc_vehicle_attribute.id`
- `gyc_vehicle_attribute.make_id` → `gyc_make.id`
- `gyc_vehicle_attribute.category_id` → `gyc_category.id`
- `gyc_vehicle_info.color_id` / `manufacturer_color_id` / `interior_color_id` → `gyc_vehicle_color.id`
- `gyc_vehicle_info.trim_id` → `gyc_vehicle_trim.id`
- `gyc_vehicle_info.exterior_finish_id` → `gyc_vehicle_exterior_finish.id`
- `gyc_vehicle_info.tag_id` → `gyc_vehicle_tag.id`
- `gyc_vehicle_info.vendor_id` → `gyc_vendor_info.id`
- `gyc_product_images.vechicle_info_id` → `gyc_vehicle_info.id`
- `gyc_vehicle_info.feature_id` holds comma-separated `gyc_features.id` values (parsed via find_in_set in the view).

## Notes for Integrations

- Use `attr_id` to store structured specs (make/model/trim/body/fuel/transmission/engine_size/year) in `gyc_vehicle_attribute`; keep listing-specific fields in `gyc_vehicle_info`.
- Prefer `color_id`/`manufacturer_color_id`/`interior_color_id` over free-text `color` when possible.
- Images should be saved to `gyc_product_images` with `serial` to preserve ordering (legacy `gyc_vehicle_image` exists but view uses `gyc_product_images`).
- `active_status` in `gyc_vehicle_info`: 0 pending, 1 waiting, 2 published, 3 sold, 4 blocked.
- The denormalized view `gyc_v_vechicle_info` is useful for exports/search; it already brings make/category/color names and the first product image.
