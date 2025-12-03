<?php
/**
 * CarScraper - PHP class for scraping used car listings
 * 
 * Designed for scraping Syston Autos Ltd listings and saving to MySQL.
 * Can be adapted for other dealers by modifying the parsing methods.
 */

class CarScraper
{
    private PDO $db;
    private array $config;
    private int $logId = 0;
    private array $stats = [
        'found' => 0,
        'inserted' => 0,
        'updated' => 0,
        'deactivated' => 0,
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initDatabase();
        $this->ensureDirectories();
    }

    /**
     * Initialize database connection
     */
    private function initDatabase(): void
    {
        $dbConfig = $this->config['database'];
        
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $dbConfig['host'],
            $dbConfig['dbname'],
            $dbConfig['charset']
        );

        $this->db = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Ensure required directories exist
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            $this->config['output']['log_path'],
            dirname($this->config['output']['json_path']),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Main entry point - run the full scrape
     */
    public function run(): array
    {
        $this->log("Starting scrape...");
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

            // Step 3: Optionally fetch detail pages
            if ($this->config['scraper']['fetch_detail_pages']) {
                $this->log("Fetching detail pages for full descriptions...");
                $vehicles = $this->enrichWithDetailPages($vehicles);
            }

            // Step 4: Save to database
            $this->log("Saving to database...");
            $activeIds = $this->saveVehicles($vehicles);

            // Step 5: Mark missing vehicles as inactive
            $this->log("Marking removed vehicles as inactive...");
            $this->stats['deactivated'] = $this->deactivateMissingVehicles($activeIds);

            // Step 6: Optionally save JSON snapshot
            if ($this->config['output']['save_json']) {
                $this->log("Saving JSON snapshot...");
                $this->saveJsonSnapshot();
            }

            $this->finishScrapeLog('completed');
            $this->log("Scrape completed successfully!");
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
     * Fetch a URL with proper headers and error handling
     */
    private function fetchUrl(string $url): ?string
    {
        $this->log("  Fetching: $url");

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->config['scraper']['timeout'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $this->config['scraper']['user_agent'],
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-GB,en;q=0.9',
                'Cache-Control: no-cache',
            ],
            CURLOPT_SSL_VERIFYPEER => $this->config['scraper']['verify_ssl'] ?? true,
            CURLOPT_ENCODING => '', // Accept all encodings
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            $this->log("  CURL error: $error");
            return null;
        }

        if ($httpCode !== 200) {
            $this->log("  HTTP error: $httpCode");
            return null;
        }

        return $response;
    }

    /**
     * Parse the main listing page and extract all vehicle cards
     */
    private function parseListingPage(string $html): array
    {
        $vehicles = [];
        
        // Create a DOMDocument for parsing
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
        $xpath = new DOMXPath($dom);

        // Find all vehicle cards - adjust selector as needed
        // Looking for common patterns in dealer websites
        $cards = $xpath->query("//div[contains(@class, 'vehicle-listing')] | //div[contains(@class, 'vehicle-card')] | //article[contains(@class, 'vehicle')]");
        
        // If that doesn't work, try a more generic approach
        if ($cards->length === 0) {
            // Try finding links to vehicle detail pages and work backwards
            $vehicleLinks = $xpath->query("//a[contains(@href, '/vehicle/name/')]");
            $processedUrls = [];
            
            foreach ($vehicleLinks as $link) {
                $href = $link->getAttribute('href');
                
                // Skip if already processed
                if (isset($processedUrls[$href])) {
                    continue;
                }
                $processedUrls[$href] = true;
                
                // Find the parent card container
                $card = $this->findParentCard($link, $xpath);
                if ($card) {
                    $vehicle = $this->parseVehicleCard($card, $xpath, $href);
                    if ($vehicle) {
                        $vehicles[] = $vehicle;
                    }
                }
            }
        } else {
            foreach ($cards as $card) {
                $vehicle = $this->parseVehicleCard($card, $xpath);
                if ($vehicle) {
                    $vehicles[] = $vehicle;
                }
            }
        }

        return $vehicles;
    }

    /**
     * Find the parent card container for a vehicle link
     */
    private function findParentCard(DOMNode $node, DOMXPath $xpath): ?DOMNode
    {
        $current = $node->parentNode;
        $maxDepth = 10;
        $depth = 0;
        
        while ($current && $depth < $maxDepth) {
            if ($current instanceof DOMElement) {
                $class = $current->getAttribute('class');
                // Look for common card container patterns
                if (preg_match('/vehicle|card|listing|item|product/i', $class)) {
                    return $current;
                }
                // Also check for structural elements that might be cards
                if ($current->tagName === 'article' || $current->tagName === 'li') {
                    return $current;
                }
            }
            $current = $current->parentNode;
            $depth++;
        }
        
        // Fallback: return an ancestor div
        $current = $node->parentNode;
        $depth = 0;
        while ($current && $depth < 5) {
            if ($current instanceof DOMElement && $current->tagName === 'div') {
                return $current;
            }
            $current = $current->parentNode;
            $depth++;
        }
        
        return null;
    }

    /**
     * Parse a single vehicle card and extract data
     */
    private function parseVehicleCard(DOMNode $card, DOMXPath $xpath, ?string $vehicleUrl = null): ?array
    {
        $cardHtml = $card->ownerDocument->saveHTML($card);
        
        // Extract vehicle URL if not provided
        if (!$vehicleUrl) {
            preg_match('/href=["\']([^"\']*\/vehicle\/name\/[^"\'#]+)/i', $cardHtml, $matches);
            $vehicleUrl = $matches[1] ?? null;
        }
        
        if (!$vehicleUrl) {
            return null;
        }

        // Clean up the URL
        $vehicleUrl = $this->normalizeUrl($vehicleUrl);
        
        // Extract external_id from URL
        $externalId = $this->extractExternalId($vehicleUrl);
        if (!$externalId) {
            return null;
        }

        // Extract image URL
        $imageUrl = null;
        if (preg_match('/src=["\']([^"\']+\.(jpg|jpeg|png|webp)[^"\']*)/i', $cardHtml, $matches)) {
            $imageUrl = $this->normalizeUrl($matches[1]);
        }
        // Also check for data-src (lazy loading)
        if (!$imageUrl && preg_match('/data-src=["\']([^"\']+\.(jpg|jpeg|png|webp)[^"\']*)/i', $cardHtml, $matches)) {
            $imageUrl = $this->normalizeUrl($matches[1]);
        }
        // Check for background-image style
        if (!$imageUrl && preg_match('/background-image:\s*url\(["\']?([^"\')\s]+)/i', $cardHtml, $matches)) {
            $imageUrl = $this->normalizeUrl($matches[1]);
        }

        // Extract title - usually in a heading or strong tag
        $title = '';
        $titleNodes = $xpath->query(".//h2 | .//h3 | .//h4 | .//a[contains(@href, '/vehicle/name/')]", $card);
        foreach ($titleNodes as $titleNode) {
            $text = trim($titleNode->textContent);
            if (strlen($text) > strlen($title) && strlen($text) < 500) {
                $title = $text;
            }
        }
        $title = $this->cleanText($title);

        // Extract price
        $price = null;
        if (preg_match('/£[\d,]+(?:\.\d{2})?/', $cardHtml, $matches)) {
            $price = $matches[0];
        }

        // Extract vehicle details (mileage, colour, etc.)
        $details = $this->extractVehicleDetails($cardHtml);

        // Extract short description
        $descriptionShort = '';
        $descNodes = $xpath->query(".//*[contains(@class, 'description') or contains(@class, 'desc')]", $card);
        foreach ($descNodes as $descNode) {
            $text = $this->cleanText($descNode->textContent);
            if (strlen($text) > strlen($descriptionShort)) {
                $descriptionShort = $text;
            }
        }

        // Extract location - be more precise with patterns
        $location = null;
        if (preg_match('/(?:location|branch|office)[:\s]*([a-zA-Z\s,\.]+?)(?:[\s<;]|$)/i', $cardHtml, $matches)) {
            $loc = trim($matches[1]);
            // Filter out HTML artifacts and empty matches
            $loc = preg_replace('/[<>"\';\\\\]+/', '', $loc);
            $loc = trim($loc);
            // Only accept if looks like actual location (real town/city names)
            if (strlen($loc) > 3 && strlen($loc) < 100 && preg_match('/^[a-zA-Z\s,\.]+$/', $loc)) {
                $location = $this->cleanText($loc);
            }
        }
        // Common location patterns - from structured data
        if (!$location && preg_match('/<(?:span|div)[^>]*class=["\']?(?:location|branch|office)["\']?[^>]*>([a-zA-Z\s,\.]+?)<\/(?:span|div)>/i', $cardHtml, $matches)) {
            $loc = trim($matches[1]);
            $loc = preg_replace('/[<>"\';\\\\]+/', '', $loc);
            $location = $this->cleanText($loc);
        }

        return [
            'external_id' => $externalId,
            'title' => $title,
            'price' => $price,
            'price_numeric' => $this->extractNumericPrice($price),
            'location' => $location,
            'mileage' => $details['mileage'] ?? null,
            'mileage_numeric' => $this->extractNumericMileage($details['mileage'] ?? null),
            'colour' => $details['colour'] ?? null,
            'transmission' => $details['transmission'] ?? null,
            'fuel_type' => $details['fuel_type'] ?? null,
            'body_style' => $details['body_style'] ?? null,
            'first_reg_date' => $details['first_reg_date'] ?? null,
            'description_short' => $descriptionShort,
            'description_full' => null, // Will be filled from detail page
            'image_url' => $imageUrl,
            'vehicle_url' => $vehicleUrl,
        ];
    }

    /**
     * Extract vehicle details from HTML text
     */
    private function extractVehicleDetails(string $html): array
    {
        $details = [
            'mileage' => null,
            'colour' => null,
            'transmission' => null,
            'fuel_type' => null,
            'body_style' => null,
            'first_reg_date' => null,
        ];

        // Mileage patterns - try multiple formats
        if (preg_match('/(?:mileage|miles)[:\s]*([0-9,]+(?:\s*miles)?)/i', $html, $matches)) {
            $details['mileage'] = $this->cleanText($matches[1]);
        } elseif (preg_match('/([0-9,]+)\s*miles/i', $html, $matches)) {
            $details['mileage'] = $matches[1] . ' miles';
        } elseif (preg_match('/\b([0-9]{2,})[,\s]*([0-9]{3})\b\s*miles/i', $html, $matches)) {
            // Match numbers like "75,000 miles" or "75 000 miles"
            $details['mileage'] = str_replace([',', ' '], '', $matches[0]);
        } elseif (preg_match('/\b\d{4,}\s*(?:k|K)\b/', $html, $matches)) {
            // Match numbers like "75K" (thousand miles)
            preg_match('/\d+/', $matches[0], $numMatch);
            if ($numMatch) {
                $details['mileage'] = ($numMatch[0] * 1000) . ' miles';
            }
        }

        // Colour patterns - avoid matching JavaScript variables and HTML code
        if (preg_match('/(?:colou?r)[:\s]*([a-zA-Z\s\-]+?)(?:[\s<;]|$)/i', $html, $matches)) {
            $colour = trim($matches[1]);
            // Filter out common false positives and code artifacts
            $blacklist = ['var', 'function', 'window', 'document', 'this', 'const', 'let', 'type', 'class', 'style', 'id', 'name'];
            if (!in_array(strtolower($colour), $blacklist) && strlen($colour) > 2 && strlen($colour) < 30 && preg_match('/^[a-zA-Z\s\-]+$/', $colour)) {
                $details['colour'] = $this->cleanText($colour);
            }
        }

        // Transmission patterns
        if (preg_match('/(automatic|manual|semi-auto|cvt)/i', $html, $matches)) {
            $details['transmission'] = ucfirst(strtolower($matches[1]));
        }

        // Fuel type patterns
        if (preg_match('/(petrol|diesel|electric|hybrid|plug-in hybrid|phev)/i', $html, $matches)) {
            $details['fuel_type'] = ucfirst(strtolower($matches[1]));
        }

        // Body style patterns
        if (preg_match('/(hatchback|saloon|estate|suv|coupe|convertible|mpv|4x4)/i', $html, $matches)) {
            $details['body_style'] = ucfirst(strtolower($matches[1]));
        }

        // First registration date patterns
        if (preg_match('/(?:first\s*reg(?:istration)?|reg\.?\s*date)[:\s]*(\d{1,2}\/\d{1,2}\/\d{2,4})/i', $html, $matches)) {
            $details['first_reg_date'] = $matches[1];
        } elseif (preg_match('/(\d{1,2}\/\d{1,2}\/\d{4})/', $html, $matches)) {
            $details['first_reg_date'] = $matches[1];
        }

        return $details;
    }

    /**
     * Enrich vehicles with full descriptions from detail pages
     */
    private function enrichWithDetailPages(array $vehicles): array
    {
        $delay = $this->config['scraper']['request_delay'];
        $total = count($vehicles);
        
        foreach ($vehicles as $index => &$vehicle) {
            $this->log("  Processing " . ($index + 1) . "/$total: {$vehicle['external_id']}");
            
            $html = $this->fetchUrl($vehicle['vehicle_url']);
            
            if ($html) {
                $fullDesc = $this->extractFullDescription($html);
                if ($fullDesc) {
                    $vehicle['description_full'] = $fullDesc;
                }
                
                // Also try to extract any missing details from the detail page
                if (empty($vehicle['mileage']) || empty($vehicle['colour'])) {
                    $details = $this->extractVehicleDetails($html);
                    foreach ($details as $key => $value) {
                        if (empty($vehicle[$key]) && !empty($value)) {
                            $vehicle[$key] = $value;
                        }
                    }
                }
            }
            
            // Be polite - wait between requests
            if ($index < $total - 1) {
                usleep((int)($delay * 1000000));
            }
        }
        
        return $vehicles;
    }

    /**
     * Extract full description from meta tag, removing finance text
     */
    private function extractFullDescription(string $html): ?string
    {
        // Try to get description from meta tag
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)/i', $html, $matches)) {
            $description = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/content=["\']([^"\']+)["\']\s+name=["\']description/i', $html, $matches)) {
            $description = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            return null;
        }

        // Remove finance-related text
        $description = $this->removeFinanceText($description);
        
        return $this->cleanText($description);
    }

    /**
     * Remove finance-related text from description
     */
    private function removeFinanceText(string $text): string
    {
        $patterns = $this->config['description_cutoff_patterns'];
        
        foreach ($patterns as $pattern) {
            $pos = stripos($text, $pattern);
            if ($pos !== false) {
                $text = substr($text, 0, $pos);
            }
        }
        
        return $text;
    }

    /**
     * Save vehicles to database
     */
    private function saveVehicles(array $vehicles): array
    {
        $source = $this->config['scraper']['source'];
        $activeIds = [];
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO vehicles (
                    source, external_id, title, price, price_numeric, location,
                    mileage, mileage_numeric, colour, transmission, fuel_type, 
                    body_style, first_reg_date, description_short, description_full,
                    image_url, vehicle_url, is_active, last_seen_at, created_at, updated_at
                ) VALUES (
                    :source, :external_id, :title, :price, :price_numeric, :location,
                    :mileage, :mileage_numeric, :colour, :transmission, :fuel_type,
                    :body_style, :first_reg_date, :description_short, :description_full,
                    :image_url, :vehicle_url, 1, :last_seen_at, :created_at, :updated_at
                ) ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    price = VALUES(price),
                    price_numeric = VALUES(price_numeric),
                    location = VALUES(location),
                    mileage = VALUES(mileage),
                    mileage_numeric = VALUES(mileage_numeric),
                    colour = VALUES(colour),
                    transmission = VALUES(transmission),
                    fuel_type = VALUES(fuel_type),
                    body_style = VALUES(body_style),
                    first_reg_date = VALUES(first_reg_date),
                    description_short = VALUES(description_short),
                    description_full = COALESCE(VALUES(description_full), description_full),
                    image_url = VALUES(image_url),
                    vehicle_url = VALUES(vehicle_url),
                    is_active = 1,
                    last_seen_at = VALUES(last_seen_at),
                    updated_at = VALUES(updated_at)";

        $stmt = $this->db->prepare($sql);

        foreach ($vehicles as $vehicle) {
            try {
                $stmt->execute([
                    ':source' => $source,
                    ':external_id' => $vehicle['external_id'],
                    ':title' => $vehicle['title'],
                    ':price' => $vehicle['price'],
                    ':price_numeric' => $vehicle['price_numeric'],
                    ':location' => $vehicle['location'],
                    ':mileage' => $vehicle['mileage'],
                    ':mileage_numeric' => $vehicle['mileage_numeric'],
                    ':colour' => $vehicle['colour'],
                    ':transmission' => $vehicle['transmission'],
                    ':fuel_type' => $vehicle['fuel_type'],
                    ':body_style' => $vehicle['body_style'],
                    ':first_reg_date' => $vehicle['first_reg_date'],
                    ':description_short' => $vehicle['description_short'],
                    ':description_full' => $vehicle['description_full'],
                    ':image_url' => $vehicle['image_url'],
                    ':vehicle_url' => $vehicle['vehicle_url'],
                    ':last_seen_at' => $now,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $rowCount = $stmt->rowCount();
                if ($rowCount === 1) {
                    $this->stats['inserted']++;
                } elseif ($rowCount === 2) {
                    // MySQL returns 2 for ON DUPLICATE KEY UPDATE
                    $this->stats['updated']++;
                }

                $activeIds[] = $vehicle['external_id'];

            } catch (PDOException $e) {
                $this->log("  Error saving {$vehicle['external_id']}: " . $e->getMessage());
            }
        }

        return $activeIds;
    }

    /**
     * Mark vehicles not in the current scrape as inactive
     */
    private function deactivateMissingVehicles(array $activeIds): int
    {
        if (empty($activeIds)) {
            return 0;
        }

        $source = $this->config['scraper']['source'];
        $placeholders = str_repeat('?,', count($activeIds) - 1) . '?';
        
        $sql = "UPDATE vehicles 
                SET is_active = 0, updated_at = NOW() 
                WHERE source = ? 
                AND is_active = 1 
                AND external_id NOT IN ($placeholders)";

        $params = array_merge([$source], $activeIds);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Save JSON snapshot of all active vehicles
     */
    private function saveJsonSnapshot(): void
    {
        $source = $this->config['scraper']['source'];

        $sql = "SELECT * FROM vehicles WHERE source = ? AND is_active = 1 ORDER BY price_numeric DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$source]);
        $vehicles = $stmt->fetchAll();

        // Sanitize and convert fields for JSON output
        foreach ($vehicles as &$vehicle) {
            // Ensure numeric fields are actual numbers
            if ($vehicle['price_numeric']) {
                $vehicle['price_numeric'] = (float)$vehicle['price_numeric'];
            }
            if ($vehicle['mileage_numeric']) {
                $vehicle['mileage_numeric'] = (int)$vehicle['mileage_numeric'];
            }
            $vehicle['id'] = (int)$vehicle['id'];
            $vehicle['is_active'] = (int)$vehicle['is_active'];

            // Clean text fields from any remaining garbage characters
            $textFields = ['title', 'location', 'colour', 'description_short', 'description_full'];
            foreach ($textFields as $field) {
                if (!empty($vehicle[$field])) {
                    // Remove broken UTF-8 sequences
                    $vehicle[$field] = preg_replace('/â[¦€‚ƒ„…†‡ˆ‰Š]/u', '...', $vehicle[$field]);
                    // Remove any remaining invalid UTF-8
                    $vehicle[$field] = mb_convert_encoding($vehicle[$field], 'UTF-8', 'UTF-8');
                }
            }
        }

        $json = json_encode([
            'generated_at' => date('c'),
            'source' => $source,
            'count' => count($vehicles),
            'vehicles' => $vehicles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = $this->config['output']['json_path'];
        file_put_contents($path, $json);

        $this->log("  Saved JSON to: $path");
    }

    /**
     * Normalize a URL (make absolute, clean up)
     */
    private function normalizeUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $url = trim($url);
        
        // Remove fragment
        $url = preg_replace('/#.*$/', '', $url);
        
        // Make absolute
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $url = $this->config['scraper']['base_url'] . $url;
        } elseif (!preg_match('/^https?:\/\//i', $url)) {
            $url = $this->config['scraper']['base_url'] . '/' . $url;
        }

        return $url;
    }

    /**
     * Extract external ID from vehicle URL
     */
    private function extractExternalId(string $url): ?string
    {
        // Pattern: /vehicle/name/slug-here/
        if (preg_match('/\/vehicle\/name\/([^\/\?#]+)/i', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract numeric price from price string
     */
    private function extractNumericPrice(?string $price): ?float
    {
        if (!$price) {
            return null;
        }
        $numeric = preg_replace('/[^0-9.]/', '', $price);
        return $numeric ? (float)$numeric : null;
    }

    /**
     * Extract numeric mileage from mileage string
     */
    private function extractNumericMileage(?string $mileage): ?float
    {
        if (!$mileage) {
            return null;
        }
        // Remove all non-numeric characters except decimal point
        $numeric = preg_replace('/[^0-9.]/', '', $mileage);
        return ($numeric && $numeric !== '') ? (float)$numeric : null;
    }

    /**
     * Clean text (remove extra whitespace, garbage characters, etc.)
     */
    private function cleanText(string $text): string
    {
        // Remove null bytes and control characters (except newlines/tabs)
        $text = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Replace specific broken/double-encoded UTF-8 sequences only (avoid over-matching)
        $text = str_replace([
            'â¦',    // broken ellipsis (U+2026 double-encoded)
            'â€™',   // broken apostrophe
            'â€œ',   // broken quote
            'â€"',   // broken en-dash
            'â€"',   // broken em-dash
            'â€',    // other broken unicode
        ], [
            '...',
            "'",
            '"',
            '-',
            '-',
            '',
        ], $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Log a message
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] $message\n";
        
        echo $line;
        
        $logFile = $this->config['output']['log_path'] . 'scraper_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $line, FILE_APPEND);
    }

    /**
     * Start a scrape log entry
     */
    private function startScrapeLog(): void
    {
        $sql = "INSERT INTO scrape_logs (source, started_at, status) VALUES (?, NOW(), 'running')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->config['scraper']['source']]);
        $this->logId = (int)$this->db->lastInsertId();
    }

    /**
     * Finish a scrape log entry
     */
    private function finishScrapeLog(string $status, ?string $error = null): void
    {
        if (!$this->logId) {
            return;
        }

        $sql = "UPDATE scrape_logs SET 
                finished_at = NOW(),
                vehicles_found = ?,
                vehicles_inserted = ?,
                vehicles_updated = ?,
                vehicles_deactivated = ?,
                status = ?,
                error_message = ?
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $this->stats['found'],
            $this->stats['inserted'],
            $this->stats['updated'],
            $this->stats['deactivated'],
            $status,
            $error,
            $this->logId,
        ]);
    }

    /**
     * Get all active vehicles (for display)
     */
    public function getActiveVehicles(): array
    {
        $source = $this->config['scraper']['source'];
        $sql = "SELECT * FROM vehicles WHERE source = ? AND is_active = 1 ORDER BY price_numeric DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$source]);
        return $stmt->fetchAll();
    }

    /**
     * Get scrape statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
