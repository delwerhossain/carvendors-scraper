<?php
/**
 * CarSafari Scraper Integration
 *
 * Scrapes vehicle listings from dealer websites and publishes directly to:
 * - gyc_vehicle_attribute (specs)
 * - gyc_vehicle_info (main data)
 * - gyc_product_images (images)
 *
 * Auto-publishes vehicles with proper status codes.
 * Integrates with StatisticsManager for comprehensive metrics tracking.
 */

// Load StatisticsManager before use
if (file_exists(__DIR__ . '/src/StatisticsManager.php')) {
    require_once __DIR__ . '/src/StatisticsManager.php';
}

// Use namespace or create alias for backward compatibility
if (!class_exists('StatisticsManager')) {
    class_alias('CarVendors\Scrapers\StatisticsManager', 'StatisticsManager');
}

class CarSafariScraper extends CarScraper
{
    private string $dbName;
    private int $vendorId = 432;  // Default vendor ID for scraping data
    private ?StatisticsManager $statisticsManager = null;

    public function __construct(array $config, string $dbName = 'carsafari')
    {
        parent::__construct($config);
        $this->dbName = $dbName;

        // Initialize StatisticsManager
        try {
            $this->statisticsManager = new StatisticsManager($config);
        } catch (Exception $e) {
            // Fallback: disable if initialization fails
            $this->statisticsManager = null;
            $this->log("Warning: StatisticsManager initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Set vendor ID for this scrape run
     */
    public function setVendorId(int $vendorId): void
    {
        $this->vendorId = $vendorId;
    }

    /**
     * Main entry point - run the full scrape with CarSafari integration
     * Includes smart change detection, file rotation, and cleanup
     * Integrates with StatisticsManager for comprehensive metrics
     */
    public function runWithCarSafari(): array
    {
        $this->log("Starting CarSafari scrape...");
        $this->startScrapeLog();

        // Initialize statistics tracking
        if ($this->statisticsManager) {
            try {
                $this->statisticsManager->initializeStatistics($this->vendorId);
            } catch (Exception $e) {
                $this->log("Warning: Could not initialize statistics: " . $e->getMessage());
                $this->statisticsManager = null;  // Disable for this run
            }
        }

        try {
            // PHASE 3: Cleanup old files before starting
            $this->log("Cleaning up old log files...");
            $deletedLogs = $this->cleanupOldLogs();
            if ($deletedLogs > 0) {
                $this->log("Deleted {$deletedLogs} old log files (older than 7 days)");
            }

            // Step 1: Fetch and parse listing page
            $this->log("Fetching listing page...");
            $html = $this->fetchUrl($this->config['scraper']['listing_url']);

            if (!$html) {
                throw new Exception("Failed to fetch listing page");
            }

            // Step 2: Parse all vehicle cards
            $this->log("Parsing vehicle cards...");
            $vehicles = $this->parseListingPage($html);
            $this->stats['found'] = count($vehicles);
            if ($this->statisticsManager) {
                $this->statisticsManager->recordVehicleAction('found', count($vehicles));
            }
            $this->log("Found {$this->stats['found']} vehicles");

            // Step 3: Fetch detail pages
            if ($this->config['scraper']['fetch_detail_pages']) {
                $this->log("Fetching detail pages for full descriptions...");
                $vehicles = $this->enrichWithDetailPages($vehicles);
            }

            // Step 4: Save to CarSafari database with change detection
            $this->log("Saving to CarSafari database with change detection...");
            $activeIds = $this->saveVehiclesToCarSafari($vehicles);

            // Step 5: Auto-publish new vehicles
            $this->log("Setting auto-publish status...");
            $this->autoPublishVehicles($activeIds);

            // Step 6: Save JSON snapshot with rotation
            if ($this->config['output']['save_json']) {
                $this->log("Saving JSON snapshot with file rotation...");
                $this->saveJsonSnapshot();
            }

            // Step 7: Finalize statistics and save to database
            if ($this->statisticsManager) {
                $this->statisticsManager->recordImageStatistics(
                    $this->stats['images_stored'] ?? 0,
                    $this->stats['images_stored'] ?? 0
                );
                
                $stats = $this->statisticsManager->finalizeStatistics('completed');
                $statsId = $this->statisticsManager->saveStatistics();
                $this->log("Statistics saved with ID: {$statsId}");
            }

            // PHASE 3: Final stats display
            $this->finishScrapeLog('completed');
            $this->log("CarSafari scrape completed successfully!");
            $this->log("Stats: " . json_encode($this->stats));
            $this->logOptimizationStats();

            return [
                'success' => true,
                'stats' => $this->stats,
            ];

        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            
            // Record error in statistics
            if ($this->statisticsManager) {
                $this->statisticsManager->recordError('runtime', $e->getMessage(), 'EXCEPTION');
                try {
                    $this->statisticsManager->finalizeStatistics('failed', $e->getMessage());
                    $this->statisticsManager->saveStatistics();
                } catch (Exception $statError) {
                    $this->log("Warning: Could not save error statistics: " . $statError->getMessage());
                }
            }
            
            $this->finishScrapeLog('failed', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats,
            ];
        }
    }

    /**
     * Save vehicles to CarSafari database tables with smart change detection
     * Only processes/updates vehicles whose data has changed
     */
    private function saveVehiclesToCarSafari(array $vehicles): array
    {
        $activeIds = [];
        $now = date('Y-m-d H:i:s');

        foreach ($vehicles as $vehicle) {
            try {
                // Step 1: Find or create vehicle attribute
                $attrId = $this->findOrCreateAttribute($vehicle);
                if (!$attrId) {
                    $attrId = $this->createNewAttribute($vehicle);
                }

                // Step 2: Smart save with change detection
                // Returns array with vehicleId and action (inserted|updated|skipped)
                $result = $this->saveVehicleInfoWithChangeDetection($vehicle, $attrId, $now);
                $vehicleId = $result['vehicleId'];
                $action = $result['action'];

                if ($vehicleId && $action !== 'skipped') {
                    // Step 3: Download and save images only if data was inserted/updated
                    if (!empty($vehicle['image_urls']) && is_array($vehicle['image_urls'])) {
                        $this->downloadAndSaveImages($vehicle['image_urls'], $vehicleId);
                        $this->stats['images_stored'] = ($this->stats['images_stored'] ?? 0) + count($vehicle['image_urls']);
                    } elseif (!empty($vehicle['image_url'])) {
                        // Fallback for single image
                        $this->downloadAndSaveImage($vehicle['image_url'], $vehicleId);
                        $this->stats['images_stored'] = ($this->stats['images_stored'] ?? 0) + 1;
                    }

                    if ($vehicleId) {
                        $activeIds[] = $vehicleId;
                    }
                } elseif ($vehicleId && $action === 'skipped') {
                    // For skipped vehicles, still add to activeIds so they stay published
                    $activeIds[] = $vehicleId;
                }

            } catch (Exception $e) {
                $this->stats['errors'] = ($this->stats['errors'] ?? 0) + 1;
                $this->log("  Error saving vehicle {$vehicle['external_id']}: " . $e->getMessage());
            }
        }

        return $activeIds;
    }

    /**
     * Find existing vehicle attribute by specs
     */
    private function findOrCreateAttribute(array $vehicle): ?int
    {
        // Try to find matching attribute by model and year
        $modelYear = date('Y');

        $sql = "SELECT id FROM gyc_vehicle_attribute
                WHERE model LIKE ?
                AND transmission = ?
                AND fuel_type = ?
                AND active_status = '1'
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $modelSearch = '%' . substr($vehicle['title'], 0, 30) . '%';

        $stmt->execute([$modelSearch, $vehicle['transmission'] ?? '', $vehicle['fuel_type'] ?? '']);
        $result = $stmt->fetch();

        return $result ? (int)$result['id'] : null;
    }

    /**
     * Create new vehicle attribute record
     */
    private function createNewAttribute(array $vehicle): int
    {
        $sql = "INSERT INTO gyc_vehicle_attribute
                (category_id, make_id, model, fuel_type, transmission, body_style, year, engine_size, active_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, '1', NOW())";

        $stmt = $this->db->prepare($sql);

        // Extract year from title if possible, default to current year
        $year = date('Y');
        preg_match('/(\d{4})/', $vehicle['title'], $matches);
        if ($matches) {
            $year = (int)$matches[1];
        }

        // Format engine size (stored as string in DB, e.g., "1969")
        $engineSize = null;
        if (!empty($vehicle['engine_size'])) {
            $engineSize = (string)$vehicle['engine_size'];
        }

        $stmt->execute([
            1,  // category_id
            1,  // make_id (default, should be updated with real make)
            substr($vehicle['title'], 0, 255),  // model
            $vehicle['fuel_type'] ?? 'Unknown',
            $vehicle['transmission'] ?? 'Unknown',
            $vehicle['body_style'] ?? NULL,
            $year,
            $engineSize,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Insert or update vehicle in gyc_vehicle_info
     */
    private function saveVehicleInfo(array $vehicle, int $attrId, string $now): ?int
    {
        // CRITICAL: Use actual VRM (reg_no) if available, otherwise fall back to external_id
        $regNo = $vehicle['reg_no'] ?? $vehicle['external_id'];
        
        // Check if vehicle already exists by reg_no
        $checkSql = "SELECT id FROM gyc_vehicle_info WHERE reg_no = ? LIMIT 1";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([$regNo]);
        $existing = $checkStmt->fetch();

        // STEP 3: Add new fields (doors, registration_plate, drive_system, post_code, address, drive_position)
        $sql = "INSERT INTO gyc_vehicle_info (
                    attr_id, reg_no, selling_price, regular_price, mileage,
                    color, description, attention_grabber, vendor_id, v_condition,
                    active_status, vehicle_url, doors, registration_plate, drive_system,
                    post_code, address, drive_position, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 'USED', '1', ?, ?, ?, ?, 'LE7 1NS', 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS', 'Right', ?, ?
                ) ON DUPLICATE KEY UPDATE
                    attr_id = VALUES(attr_id),
                    selling_price = VALUES(selling_price),
                    regular_price = VALUES(regular_price),
                    mileage = VALUES(mileage),
                    color = VALUES(color),
                    description = VALUES(description),
                    attention_grabber = VALUES(attention_grabber),
                    vehicle_url = VALUES(vehicle_url),
                    doors = VALUES(doors),
                    registration_plate = VALUES(registration_plate),
                    drive_system = VALUES(drive_system),
                    post_code = 'LE7 1NS',
                    address = 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS',
                    drive_position = 'Right',
                    active_status = '1',
                    updated_at = VALUES(updated_at)";

        $stmt = $this->db->prepare($sql);

        // Extract numeric price
        $price = $this->extractNumericPrice($vehicle['price']);
        
        // CRITICAL: Use actual VRM (reg_no) if available, otherwise fall back to external_id  
        $regNo = $vehicle['reg_no'] ?? $vehicle['external_id'];

        $result = $stmt->execute([
            $attrId,                                                    // 1: attr_id
            $regNo,                                                     // 2: reg_no (actual UK VRM like WP66UEX)
            $price,                                                     // 3: selling_price
            $price,                                                     // 4: regular_price
            $this->extractNumericMileage($vehicle['mileage']),         // 5: mileage
            $vehicle['colour'],                                         // 6: color
            $vehicle['description_full'] ?? $vehicle['description_short'],  // 7: description
            $vehicle['attention_grabber'],                             // 8: attention_grabber (short subtitle only, NULL if not present)
            $this->vendorId,                                            // 9: vendor_id
            // 10: v_condition='USED' (hardcoded)
            // 11: active_status='1' (hardcoded)
            $vehicle['vehicle_url'] ?? null,                           // 12: vehicle_url
            $vehicle['doors'] ?? null,                                 // 13: doors
            $vehicle['registration_plate'] ?? null,                    // 14: registration_plate
            $vehicle['drive_system'] ?? null,                          // 15: drive_system
            // 16: post_code='LE7 1NS' (hardcoded)
            // 17: address='Unit 10...' (hardcoded)
            // 18: drive_position='Right' (hardcoded)
            $now,                                                       // 19: created_at
            $now,                                                       // 20: updated_at
        ]);

        if (!$result) {
            return null;
        }

        // Return the vehicle ID
        if ($existing) {
            return (int)$existing['id'];
        } else {
            return (int)$this->db->lastInsertId();
        }
    }

    /**
     * Download multiple images and save to gyc_product_images with serial numbering
     */
    private function downloadAndSaveImages(array $imageUrls, int $vehicleId): void
    {
        if (empty($imageUrls)) {
            return;
        }

        $serial = 1;

        foreach ($imageUrls as $imageUrl) {
            try {
                // STEP 6: Store image URL instead of downloading
                // This is faster and keeps images up-to-date
                $filename = $imageUrl;  // Store full URL in database
                
                // Validate URL
                if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $this->log("  Warning: Invalid image URL for vehicle $vehicleId: $imageUrl");
                    $serial++;
                    continue;
                }

                // Save URL record to database with serial number
                // Store the full URL instead of a local file path
                $sql = "INSERT INTO gyc_product_images (file_name, vechicle_info_id, serial, cratead_at)
                        VALUES (?, ?, ?, NOW())
                        ON DUPLICATE KEY UPDATE 
                          file_name = VALUES(file_name),
                          updated_at = NOW()";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$filename, $vehicleId, $serial]);

                $this->log("  Stored image URL #{$serial} for vehicle $vehicleId");
                $serial++;

            } catch (Exception $e) {
                $this->log("  Warning: Could not save image #{$serial} for vehicle $vehicleId: " . $e->getMessage());
                $serial++;
            }
        }
    }

    /**
     * Save image URL to gyc_product_images (STEP 6: Store URLs instead of downloading)
     */
    private function downloadAndSaveImage(string $imageUrl, int $vehicleId): void
    {
        try {
            // Validate URL
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $this->log("  Warning: Invalid image URL for vehicle $vehicleId: $imageUrl");
                return;
            }

            // Save URL record to database (store full URL instead of local path)
            $sql = "INSERT INTO gyc_product_images (file_name, vechicle_info_id, serial, cratead_at)
                    VALUES (?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE 
                      file_name = VALUES(file_name),
                      updated_at = NOW()";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$imageUrl, $vehicleId]);

            $this->log("  Stored primary image URL for vehicle $vehicleId");

        } catch (Exception $e) {
            $this->log("  Warning: Could not save image URL for vehicle $vehicleId: " . $e->getMessage());
        }
    }

    /**
     * Download image from URL
     */
    private function downloadImage(string $url): ?string
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => $this->config['scraper']['user_agent'],
                CURLOPT_SSL_VERIFYPEER => $this->config['scraper']['verify_ssl'] ?? true,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                return $response;
            }
            return null;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Auto-publish vehicles with status = 1 (Waiting for Publish)
     */
    private function autoPublishVehicles(array $vehicleIds): void
    {
        if (empty($vehicleIds)) {
            return;
        }

        // Set status to 1 (Waiting for Publish) for newly inserted vehicles
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));

        // Only publish vehicles that are not already published
        $sql = "UPDATE gyc_vehicle_info
                SET active_status = '1', publish_date = CURDATE()
                WHERE id IN ($placeholders)
                AND active_status != '1'";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($vehicleIds);

        $this->stats['published'] = $stmt->rowCount();
        $this->log("Published {$this->stats['published']} vehicles");
    }

    /**
     * Extract numeric price (inherited from parent, reuse)
     */
    protected function extractNumericPrice(?string $price): ?float
    {
        if (!$price) {
            return null;
        }
        $numeric = preg_replace('/[^0-9.]/', '', $price);
        return $numeric ? (float)$numeric : null;
    }

    /**
     * Extract numeric mileage (inherited from parent, reuse)
     */
    protected function extractNumericMileage(?string $mileage): ?float
    {
        if (!$mileage) {
            return null;
        }
        $numeric = preg_replace('/[^0-9.]/', '', $mileage);
        return ($numeric && $numeric !== '') ? (int)(float)$numeric : null;
    }

    /**
     * Override JSON saving to use CarSafari data
     */
    protected function saveJsonSnapshot(): void
    {
        // Fetch from CarSafari tables instead
        $sql = "SELECT
                    gvi.id,
                    gvi.attr_id,
                    gvi.reg_no,
                    gvi.selling_price,
                    gvi.mileage,
                    gvi.color,
                    gvi.description,
                    gvi.attention_grabber,
                    gvi.created_at,
                    gvi.updated_at,
                    gva.model,
                    gva.year
                FROM gyc_vehicle_info gvi
                LEFT JOIN gyc_vehicle_attribute gva ON gvi.attr_id = gva.id
                WHERE gvi.active_status IN ('1', '2')
                ORDER BY gvi.selling_price DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $vehicles = $stmt->fetchAll();

        // Enhanced: Generate COMPLETE JSON with ALL fields from database
        $this->generateCompleteVehicleJSON($vehicles);
    }

    /**
     * Generate complete JSON export with ALL vehicle data fields
     * Includes: color, doors, transmission, fuel_type, body_style, engine_size, etc.
     * FIXED: Use separate queries for images to avoid GROUP_CONCAT truncation at 1024 bytes
     * @param array $vehicles - From database query
     */
    private function generateCompleteVehicleJSON(array $vehicles)
    {
        // Fetch enriched vehicle data with all fields (WITHOUT GROUP_CONCAT to avoid truncation)
        $sql = "
            SELECT 
                v.id,
                v.attr_id,
                v.reg_no,
                v.selling_price,
                v.regular_price,
                v.mileage,
                v.color,
                v.description,
                v.attention_grabber,
                v.doors,
                v.registration_plate,
                v.drive_system,
                v.post_code,
                v.address,
                v.vehicle_url,
                v.created_at,
                v.updated_at,
                a.model,
                a.year,
                a.transmission,
                a.fuel_type,
                a.body_style,
                a.engine_size,
                (SELECT COUNT(*) FROM gyc_product_images WHERE vechicle_info_id = v.id) as image_count
            FROM gyc_vehicle_info v
            LEFT JOIN gyc_vehicle_attribute a ON v.attr_id = a.id
            WHERE v.vendor_id = {$this->vendorId}
            ORDER BY v.created_at DESC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $allVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch all images separately (NO GROUP_CONCAT truncation)
            $imageStmt = $this->db->prepare("
                SELECT vechicle_info_id, file_name 
                FROM gyc_product_images 
                ORDER BY vechicle_info_id ASC, serial ASC
            ");
            $imageStmt->execute();
            $allImages = $imageStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build image map: vehicle_id => [urls...]
            $imageMap = [];
            foreach ($allImages as $img) {
                $vehicleId = $img['vechicle_info_id'];
                if (!isset($imageMap[$vehicleId])) {
                    $imageMap[$vehicleId] = [];
                }
                // Add .jpg if missing (handles GROUP_CONCAT truncation damage)
                $url = $img['file_name'];
                if (substr($url, -4) !== '.jpg') {
                    $url .= '.jpg';
                }
                $imageMap[$vehicleId][] = str_ireplace('/medium/', '/large/', $url);
            }

            // Count statistics
            $stats = [
                'total_vehicles' => count($allVehicles),
                'with_color' => count(array_filter($allVehicles, fn($v) => !empty($v['color']))),
                'with_doors' => count(array_filter($allVehicles, fn($v) => !empty($v['doors']))),
                'with_transmission' => count(array_filter($allVehicles, fn($v) => !empty($v['transmission']))),
                'with_fuel_type' => count(array_filter($allVehicles, fn($v) => !empty($v['fuel_type']))),
                'with_body_style' => count(array_filter($allVehicles, fn($v) => !empty($v['body_style']))),
                'with_images' => count(array_filter($allVehicles, fn($v) => $v['image_count'] > 0)),
                'total_images' => array_sum(array_column($allVehicles, 'image_count'))
            ];

            // Format JSON with complete data
            $jsonData = [
                'generated_at' => date('c'),
                'source' => 'carsafari_scraper_complete',
                'version' => '2.0',
                'count' => count($allVehicles),
                'last_update' => date('Y-m-d H:i:s'),
                'statistics' => $stats,
                'vehicles' => array_map(function($v) use ($imageMap) {
                    // Get images from map (no GROUP_CONCAT truncation)
                    $images = $imageMap[$v['id']] ?? [];

                    return [
                        'id' => (int)$v['id'],
                        'attr_id' => (int)$v['attr_id'],
                        'reg_no' => $v['reg_no'],
                        'title' => $v['model'] . ' ' . $v['year'],  // Build title from model and year
                        'attention_grabber' => $v['attention_grabber'],  // Include attention_grabber field
                        'model' => $v['model'],
                        'year' => !empty($v['year']) ? (int)$v['year'] : null,
                        'plate_year' => $v['registration_plate'],
                        'doors' => !empty($v['doors']) ? (int)$v['doors'] : null,
                        'drive_system' => $v['drive_system'],
                        'engine_size' => !empty($v['engine_size']) ? (int)$v['engine_size'] : null,
                        'selling_price' => !empty($v['selling_price']) ? (int)$v['selling_price'] : 0,
                        'regular_price' => !empty($v['regular_price']) ? (int)$v['regular_price'] : null,
                        'mileage' => !empty($v['mileage']) ? (int)$v['mileage'] : 0,
                        'color' => $v['color'],
                        'transmission' => $v['transmission'],
                        'fuel_type' => $v['fuel_type'],
                        'body_style' => $v['body_style'],
                        'description' => $v['description'],
                        'postcode' => $v['post_code'],
                        'address' => $v['address'],
                        'vehicle_url' => $v['vehicle_url'],
                        'images' => [
                            'count' => (int)$v['image_count'],
                            'urls' => $images
                        ],
                        'dealer' => [
                            'vendor_id' => 432,
                            'name' => 'Systonautos Ltd',
                            'postcode' => $v['post_code'],
                            'address' => $v['address']
                        ],
                        'published' => true,
                        'created_at' => $v['created_at'],
                        'updated_at' => $v['updated_at']
                    ];
                }, $allVehicles)
            ];

            // Save complete JSON file
            $path = $this->config['output']['json_path'] ?? 'data/vehicles.json';
            $jsonContent = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            if (file_put_contents($path, $jsonContent)) {
                $this->log("✓ Complete JSON saved to: {$path}");
                $this->log("  Total vehicles: {$stats['total_vehicles']}");
                $this->log("  With color: {$stats['with_color']}");
                $this->log("  With transmission: {$stats['with_transmission']}");
                $this->log("  Total images: {$stats['total_images']}");
            } else {
                $this->log("✗ Failed to save JSON to: {$path}");
            }

        } catch (Exception $e) {
            $this->log("Error generating complete JSON: " . $e->getMessage());
        }
    }

    /**
     * Log optimization statistics after scrape
     * Shows efficiency of change detection and file management
     */
    protected function logOptimizationStats(): void
    {
        $this->log("\n========== OPTIMIZATION REPORT ==========");
        $this->log("Processing Efficiency:");
        $this->log("  Found:     {$this->stats['found']}");
        $this->log("  Inserted:  {$this->stats['inserted']}");
        $this->log("  Updated:   {$this->stats['updated']}");
        $this->log("  Skipped:   {$this->stats['skipped']}");
        
        $total = ($this->stats['inserted'] + $this->stats['updated'] + $this->stats['skipped']);
        if ($total > 0) {
            $skipPercentage = round(($this->stats['skipped'] / $total) * 100, 1);
            $this->log("  Skip Rate: {$skipPercentage}%");
        }
        
        if ($this->stats['deactivated'] > 0) {
            $this->log("  Deactivated: {$this->stats['deactivated']}");
        }
        
        $this->log("\nDatabase Operations:");
        $this->log("  Published: {$this->stats['published']}");
        $this->log("  Images:    {$this->stats['images_stored']}");
        $this->log("  Errors:    {$this->stats['errors']}");
        
        if (isset($this->stats['startTime'])) {
            $duration = time() - $this->stats['startTime'];
            $this->log("\nPerformance:");
            $this->log("  Duration: {$duration}s");
            if ($duration > 0) {
                $vehiclesPerSecond = $this->stats['found'] / $duration;
                $this->log("  Rate: " . round($vehiclesPerSecond, 2) . " vehicles/sec");
            }
        }
        
        $this->log("=========================================\n");
    }

    /**
     * Get stored data hash from database for change detection
     * Queries gyc_vehicle_info.data_hash column
     *
     * @param string $registrationNumber Vehicle registration number
     * @return string|null Stored data hash or null if not found
     */
    protected function getStoredDataHash(string $registrationNumber): ?string
    {
        try {
            $sql = "SELECT data_hash FROM gyc_vehicle_info WHERE reg_no = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$registrationNumber]);
            $result = $stmt->fetch();
            
            return $result ? ($result['data_hash'] ?? null) : null;
        } catch (Exception $e) {
            $this->log("Warning: Could not retrieve stored hash for $registrationNumber: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Intelligently save vehicle with change detection
     * Only updates if data has actually changed (hash-based)
     * Tracks statistics: inserted, updated, skipped
     *
     * @param array $vehicle Vehicle data array
     * @param int $attrId Attribute ID
     * @param string $now Current timestamp
     * @return array ['vehicleId' => int|null, 'action' => 'inserted|updated|skipped']
     */
    protected function saveVehicleInfoWithChangeDetection(array $vehicle, int $attrId, string $now): array
    {
        // CRITICAL: Use actual VRM (reg_no) if available, otherwise fall back to external_id
        $regNo = $vehicle['reg_no'] ?? $vehicle['external_id'];
        
        // Check if vehicle exists
        $checkSql = "SELECT id, data_hash FROM gyc_vehicle_info WHERE reg_no = ? LIMIT 1";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([$regNo]);
        $existing = $checkStmt->fetch();

        // Calculate current data hash
        $currentHash = $this->calculateDataHash($vehicle);
        $storedHash = $existing ? ($existing['data_hash'] ?? null) : null;

        // Check if data has changed
        if ($existing && $currentHash === $storedHash) {
            $this->stats['skipped']++;
            $this->log("  [SKIP] Vehicle {$regNo} - no changes detected");
            return [
                'vehicleId' => (int)$existing['id'],
                'action' => 'skipped'
            ];
        }

        // Data has changed or new vehicle - save it
        $vehicleId = $this->saveVehicleInfoAndHash($vehicle, $attrId, $now, $currentHash);
        
        if (!$vehicleId) {
            return ['vehicleId' => null, 'action' => 'error'];
        }

        if ($existing) {
            $this->stats['updated']++;
            $action = 'updated';
        } else {
            $this->stats['inserted']++;
            $action = 'inserted';
        }

        $this->log("  [{$action}] Vehicle {$regNo} - Hash: {$currentHash}");

        return [
            'vehicleId' => $vehicleId,
            'action' => $action
        ];
    }

    /**
     * Save vehicle info with data hash column update
     * Modified version of saveVehicleInfo that also updates data_hash
     *
     * @param array $vehicle Vehicle data
     * @param int $attrId Attribute ID
     * @param string $now Current timestamp
     * @param string $dataHash Calculated data hash
     * @return int|null Vehicle ID or null on failure
     */
    private function saveVehicleInfoAndHash(array $vehicle, int $attrId, string $now, string $dataHash): ?int
    {
        // CRITICAL: Use actual VRM (reg_no) if available, otherwise fall back to external_id
        $regNo = $vehicle['reg_no'] ?? $vehicle['external_id'];
        
        // Check if vehicle already exists by reg_no
        $checkSql = "SELECT id FROM gyc_vehicle_info WHERE reg_no = ? LIMIT 1";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([$regNo]);
        $existing = $checkStmt->fetch();

        // STEP 3: Add new fields + data_hash for change detection
        $sql = "INSERT INTO gyc_vehicle_info (
                    attr_id, reg_no, selling_price, regular_price, mileage,
                    color, description, attention_grabber, vendor_id, v_condition,
                    active_status, vehicle_url, doors, registration_plate, drive_system,
                    post_code, address, drive_position, data_hash, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, 'USED', '1', ?, ?, ?, ?, 'LE7 1NS', 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS', 'Right', ?, ?, ?
                ) ON DUPLICATE KEY UPDATE
                    attr_id = VALUES(attr_id),
                    selling_price = VALUES(selling_price),
                    regular_price = VALUES(regular_price),
                    mileage = VALUES(mileage),
                    color = VALUES(color),
                    description = VALUES(description),
                    attention_grabber = VALUES(attention_grabber),
                    vehicle_url = VALUES(vehicle_url),
                    doors = VALUES(doors),
                    registration_plate = VALUES(registration_plate),
                    drive_system = VALUES(drive_system),
                    post_code = 'LE7 1NS',
                    address = 'Unit 10 Mill Lane Syston, Leicester, LE7 1NS',
                    drive_position = 'Right',
                    data_hash = VALUES(data_hash),
                    active_status = '1',
                    updated_at = VALUES(updated_at)";

        $stmt = $this->db->prepare($sql);

        // Extract numeric price
        $price = $this->extractNumericPrice($vehicle['price']);

        $result = $stmt->execute([
            $attrId,                                                    // 1: attr_id
            $regNo,                                                     // 2: reg_no (actual UK VRM like WP66UEX)
            $price,                                                     // 3: selling_price
            $price,                                                     // 4: regular_price
            $this->extractNumericMileage($vehicle['mileage']),         // 5: mileage
            $vehicle['colour'],                                         // 6: color
            $vehicle['description_full'] ?? $vehicle['description_short'],  // 7: description
            $vehicle['attention_grabber'],                             // 8: attention_grabber (short subtitle only, NULL if not present)
            $this->vendorId,                                            // 9: vendor_id
            // 10: v_condition='USED' (hardcoded)
            // 11: active_status='1' (hardcoded)
            $vehicle['vehicle_url'] ?? null,                           // 12: vehicle_url
            $vehicle['doors'] ?? null,                                 // 13: doors
            $vehicle['registration_plate'] ?? null,                    // 14: registration_plate
            $vehicle['drive_system'] ?? null,                          // 15: drive_system
            // 16: post_code='LE7 1NS' (hardcoded)
            // 17: address='Unit 10...' (hardcoded)
            // 18: drive_position='Right' (hardcoded)
            $dataHash,                                                  // 19: data_hash
            $now,                                                       // 20: created_at
            $now,                                                       // 21: updated_at
        ]);

        if (!$result) {
            return null;
        }

        // Return the vehicle ID
        if ($existing) {
            return (int)$existing['id'];
        } else {
            return (int)$this->db->lastInsertId();
        }
    }

    /**
     * Create timestamped JSON file with rotation
     * Overrides parent method to use CarSafari JSON format
     *
     * @param string $outputFile Base output file path
     * @return string New timestamped filename
     */
    protected function getTimestampedJsonFileForCarSafari(string $outputFile): string
    {
        $dir = dirname($outputFile);
        $basename = basename($outputFile, '.json');
        $timestamp = date('YYYYMMDDHHmmss');
        
        // Rotate old files first (keep last 2)
        $this->rotateJsonFiles($outputFile);
        
        // Return new timestamped filename
        $newFile = $dir . '/' . $basename . '_' . $timestamp . '.json';
        $this->log("New JSON file: {$newFile}");
        
        return $newFile;
    }

    /**
     * Get statistics manager instance
     *
     * @return StatisticsManager|null The statistics manager or null if not initialized
     */
    public function getStatisticsManager(): ?StatisticsManager
    {
        return $this->statisticsManager;
    }

    /**
     * Get statistics for a date range
     *
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * @return array Array of statistics or empty array
     */
    public function getStatisticsForDateRange(string $startDate, string $endDate): array
    {
        if (!$this->statisticsManager) {
            $this->log("Warning: StatisticsManager not available");
            return [];
        }

        return $this->statisticsManager->getStatisticsForDateRange($startDate, $endDate, $this->vendorId);
    }

    /**
     * Get daily statistics summary
     *
     * @param int $days Number of days to retrieve
     * @return array Array of daily statistics
     */
    public function getDailyStatistics(int $days = 30): array
    {
        if (!$this->statisticsManager) {
            $this->log("Warning: StatisticsManager not available");
            return [];
        }

        return $this->statisticsManager->getDailyStatistics($days, $this->vendorId);
    }

    /**
     * Generate weekly report
     *
     * @param string $weekStartDate Week start date (YYYY-MM-DD)
     * @return array Weekly report data
     */
    public function getWeeklyReport(string $weekStartDate): array
    {
        if (!$this->statisticsManager) {
            $this->log("Warning: StatisticsManager not available");
            return [];
        }

        return $this->statisticsManager->generateWeeklyReport($weekStartDate, $this->vendorId);
    }

    /**
     * Generate monthly report
     *
     * @param int $year Year
     * @param int $month Month (1-12)
     * @return array Monthly report data
     */
    public function getMonthlyReport(int $year, int $month): array
    {
        if (!$this->statisticsManager) {
            $this->log("Warning: StatisticsManager not available");
            return [];
        }

        return $this->statisticsManager->generateMonthlyReport($year, $month, $this->vendorId);
    }

    /**
     * Get error trends
     *
     * @param int $days Number of days to analyze
     * @return array Array of error trends
     */
    public function getErrorTrends(int $days = 7): array
    {
        if (!$this->statisticsManager) {
            $this->log("Warning: StatisticsManager not available");
            return [];
        }

        return $this->statisticsManager->getErrorTrends($days, $this->vendorId);
    }

    /**
     * Get triggered alerts
     *
     * @param string $status Alert status filter
     * @return array Array of alerts
     */
    public function getAlerts(string $status = 'triggered'): array
    {
        if (!$this->statisticsManager) {
            $this->log("Warning: StatisticsManager not available");
            return [];
        }

        return $this->statisticsManager->getAlerts($status);
    }

    /**
     * Generate and save a report in specified format
     *
     * @param string $format Report format (text, json, csv, html)
     * @param array $data Data to include in report
     * @param string $outputFile Optional output file path
     * @return string The formatted report content
     */
    public function generateReport(string $format, array $data, string $outputFile = null): string
    {
        if (!$this->statisticsManager) {
            throw new Exception("StatisticsManager not available");
        }

        $report = $this->statisticsManager->generateReport($format, $data);

        if ($outputFile) {
            file_put_contents($outputFile, $report);
            $this->log("Report saved to: {$outputFile}");
        }

        return $report;
    }
}
