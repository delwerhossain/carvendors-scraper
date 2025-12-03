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
 */

class CarSafariScraper extends CarScraper
{
    private string $dbName;
    private int $vendorId = 1;  // Default vendor ID - change as needed

    public function __construct(array $config, string $dbName = 'carsafari')
    {
        parent::__construct($config);
        $this->dbName = $dbName;
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
     */
    public function runWithCarSafari(): array
    {
        $this->log("Starting CarSafari scrape...");
        $this->startScrapeLog();

        try {
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
            $this->log("Found {$this->stats['found']} vehicles");

            // Step 3: Fetch detail pages
            if ($this->config['scraper']['fetch_detail_pages']) {
                $this->log("Fetching detail pages for full descriptions...");
                $vehicles = $this->enrichWithDetailPages($vehicles);
            }

            // Step 4: Save to CarSafari database
            $this->log("Saving to CarSafari database...");
            $activeIds = $this->saveVehiclesToCarSafari($vehicles);

            // Step 5: Auto-publish new vehicles
            $this->log("Setting auto-publish status...");
            $this->autoPublishVehicles($activeIds);

            // Step 6: Save JSON snapshot
            if ($this->config['output']['save_json']) {
                $this->log("Saving JSON snapshot...");
                $this->saveJsonSnapshot();
            }

            $this->finishScrapeLog('completed');
            $this->log("CarSafari scrape completed successfully!");
            $this->log("Stats: " . json_encode($this->stats));

            return [
                'success' => true,
                'stats' => $this->stats,
            ];

        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
            $this->finishScrapeLog('failed', $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats,
            ];
        }
    }

    /**
     * Save vehicles to CarSafari database tables
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

                // Step 2: Insert or update vehicle info
                $vehicleId = $this->saveVehicleInfo($vehicle, $attrId, $now);

                if ($vehicleId) {
                    // Step 3: Download and save images
                    if (!empty($vehicle['image_url'])) {
                        $this->downloadAndSaveImage($vehicle['image_url'], $vehicleId);
                    }

                    $activeIds[] = $vehicleId;
                }

            } catch (Exception $e) {
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
                (category_id, make_id, model, fuel_type, transmission, body_style, year, active_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, '1', NOW())";

        $stmt = $this->db->prepare($sql);

        // Extract year from title if possible, default to current year
        $year = date('Y');
        preg_match('/(\d{4})/', $vehicle['title'], $matches);
        if ($matches) {
            $year = (int)$matches[1];
        }

        $stmt->execute([
            1,  // category_id
            1,  // make_id (default, should be updated with real make)
            substr($vehicle['title'], 0, 255),  // model
            $vehicle['fuel_type'] ?? 'Unknown',
            $vehicle['transmission'] ?? 'Unknown',
            $vehicle['body_style'] ?? NULL,
            $year,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Insert or update vehicle in gyc_vehicle_info
     */
    private function saveVehicleInfo(array $vehicle, int $attrId, string $now): ?int
    {
        // Check if vehicle already exists by reg_no
        $checkSql = "SELECT id FROM gyc_vehicle_info WHERE reg_no = ? LIMIT 1";
        $checkStmt = $this->db->prepare($checkSql);
        $checkStmt->execute([$vehicle['external_id']]);
        $existing = $checkStmt->fetch();

        $sql = "INSERT INTO gyc_vehicle_info (
                    attr_id, reg_no, selling_price, regular_price, mileage,
                    color, transmission, fuel_type, body_style, description,
                    attention_grabber, vendor_id, v_condition, active_status,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NEW', '1', ?, ?
                ) ON DUPLICATE KEY UPDATE
                    attr_id = VALUES(attr_id),
                    selling_price = VALUES(selling_price),
                    regular_price = VALUES(regular_price),
                    mileage = VALUES(mileage),
                    color = VALUES(color),
                    transmission = VALUES(transmission),
                    fuel_type = VALUES(fuel_type),
                    body_style = VALUES(body_style),
                    description = VALUES(description),
                    attention_grabber = VALUES(attention_grabber),
                    active_status = '1',
                    updated_at = VALUES(updated_at)";

        $stmt = $this->db->prepare($sql);

        // Extract numeric price
        $price = $this->extractNumericPrice($vehicle['price']);

        $result = $stmt->execute([
            $attrId,
            $vehicle['external_id'],
            $price,  // selling_price
            $price,  // regular_price
            $this->extractNumericMileage($vehicle['mileage']),
            $vehicle['colour'],
            $vehicle['transmission'],
            $vehicle['fuel_type'],
            $vehicle['body_style'],
            $vehicle['description_full'] ?? $vehicle['description_short'],
            $vehicle['title'],  // attention_grabber
            $this->vendorId,
            $now,
            $now,
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
     * Download image and save to gyc_product_images
     */
    private function downloadAndSaveImage(string $imageUrl, int $vehicleId): void
    {
        try {
            // Generate unique filename with timestamp
            $timestamp = date('YmdHis');
            $filename = $timestamp . '_' . rand(1, 999) . '.jpg';

            // Download image
            $imageData = $this->downloadImage($imageUrl);
            if (!$imageData) {
                $this->log("  Warning: Could not download image for vehicle $vehicleId");
                return;
            }

            // Save to filesystem (you'll need to configure this path)
            $uploadPath = dirname($this->config['output']['json_path']) . '/images/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            file_put_contents($uploadPath . $filename, $imageData);

            // Save record to database
            $sql = "INSERT INTO gyc_product_images (file_name, vechicle_info_id, serial, cratead_at)
                    VALUES (?, ?, 1, NOW())";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$filename, $vehicleId]);

        } catch (Exception $e) {
            $this->log("  Warning: Could not save image for vehicle $vehicleId: " . $e->getMessage());
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

        $sql = "UPDATE gyc_vehicle_info
                SET active_status = '1', publish_date = CURDATE()
                WHERE id IN ($placeholders)
                AND active_status = '1'";

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
    protected function extractNumericMileage(?string $mileage): ?int
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
                    gvi.transmission,
                    gvi.fuel_type,
                    gvi.body_style,
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

        // Ensure numeric types
        foreach ($vehicles as &$vehicle) {
            if ($vehicle['selling_price']) {
                $vehicle['selling_price'] = (float)$vehicle['selling_price'];
            }
            if ($vehicle['mileage']) {
                $vehicle['mileage'] = (int)$vehicle['mileage'];
            }
            $vehicle['id'] = (int)$vehicle['id'];
        }

        $json = json_encode([
            'generated_at' => date('c'),
            'source' => 'carsafari_scraper',
            'count' => count($vehicles),
            'vehicles' => $vehicles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = $this->config['output']['json_path'];
        file_put_contents($path, $json);

        $this->log("  Saved JSON to: $path");
    }
}
