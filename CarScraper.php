<?php
/**
 * CarScraper - PHP class for scraping used car listings
 * 
 * Designed for scraping Syston Autos Ltd listings and saving to MySQL.
 * Can be adapted for other dealers by modifying the parsing methods.
 */

class CarScraper
{
    protected PDO $db;
    protected array $config;
    protected int $logId = 0;
    protected array $stats = [
        'found' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,         // No changes detected
        'deactivated' => 0,
        'images_stored' => 0,   // Track image storage
        'errors' => 0,
    ];
    protected int $startTime = 0;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initDatabase();
        $this->ensureDirectories();
    }

    /**
     * Initialize database connection
     */
    protected function initDatabase(): void
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
    protected function ensureDirectories(): void
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
    protected function fetchUrl(string $url): ?string
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
    protected function parseListingPage(string $html): array
    {
        $vehicles = [];
        $processedIds = [];  // STEP 1: Add deduplication tracking
        
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
                    // STEP 1: Check deduplication before adding to vehicles array
                    if ($vehicle && !isset($processedIds[$vehicle['external_id']])) {
                        $vehicles[] = $vehicle;
                        $processedIds[$vehicle['external_id']] = true;  // Mark as processed
                    }
                }
            }
        } else {
            foreach ($cards as $card) {
                $vehicle = $this->parseVehicleCard($card, $xpath);
                // STEP 1: Check deduplication before adding to vehicles array
                if ($vehicle && !isset($processedIds[$vehicle['external_id']])) {
                    $vehicles[] = $vehicle;
                    $processedIds[$vehicle['external_id']] = true;  // Mark as processed
                }
            }
        }

        return $vehicles;
    }

    /**
     * Find the parent card container for a vehicle link
     */
    protected function findParentCard(DOMNode $node, DOMXPath $xpath): ?DOMNode
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
    protected function parseVehicleCard(DOMNode $card, DOMXPath $xpath, ?string $vehicleUrl = null): ?array
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

        // Extract image URLs (primary + all additional images)
        $imageUrl = null;
        $imageUrls = [];  // All images found

        // Find all image src attributes
        if (preg_match_all('/src=["\']([^"\']+\.(jpg|jpeg|png|webp)[^"\']*)/i', $cardHtml, $matches)) {
            foreach ($matches[1] as $url) {
                $normalized = $this->normalizeUrl($url);
                if ($normalized && !in_array($normalized, $imageUrls)) {
                    $imageUrls[] = $normalized;
                }
            }
        }
        // Also check for data-src (lazy loading)
        if (preg_match_all('/data-src=["\']([^"\']+\.(jpg|jpeg|png|webp)[^"\']*)/i', $cardHtml, $matches)) {
            foreach ($matches[1] as $url) {
                $normalized = $this->normalizeUrl($url);
                if ($normalized && !in_array($normalized, $imageUrls)) {
                    $imageUrls[] = $normalized;
                }
            }
        }
        // Check for background-image style
        if (preg_match_all('/background-image:\s*url\(["\']?([^"\')\s]+)/i', $cardHtml, $matches)) {
            foreach ($matches[1] as $url) {
                $normalized = $this->normalizeUrl($url);
                if ($normalized && !in_array($normalized, $imageUrls)) {
                    $imageUrls[] = $normalized;
                }
            }
        }

        // Use first image as primary, store all
        $imageUrl = !empty($imageUrls) ? $imageUrls[0] : null;

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

        // STEP 3: Extract additional fields from title
        $doors = null;
        $registration_plate = null;
        $plate_year = null;
        $drive_system = null;
        $trim = null;
        $year = null;
        
        // Extract number of doors: "5dr", "3dr", "4dr"
        if (preg_match('/\b(\d)dr\b/i', $title, $matches)) {
            $doors = (int)$matches[1];
        }
        
        // Extract registration plate year: "(64 plate)", "(23 plate)"
        if (preg_match('/\((\d{2})\s*(?:plate|reg|registration)\)/i', $title, $matches)) {
            $plateCode = (int)$matches[1];
            if ($plateCode <= 99) {
                $registration_plate = $matches[1];
                // Convert plate code to year: 00-49 = 2000-2049, 50-99 = 1950-1999
                $plate_year = ($plateCode <= 49) ? 2000 + $plateCode : 1900 + $plateCode;
            }
        }
        
        // Extract drive system: "4WD", "AWD", "2WD", "FWD", "RWD", "xDrive", "sDrive", "ALL4"
        if (preg_match('/\b(4WD|AWD|2WD|FWD|RWD|xDrive|sDrive|qDrive|ALL4)\b/i', $title, $matches)) {
            $drive_system = strtoupper($matches[1]);
        }
        
        // Extract trim level: "SE", "Sport", "S line", "FR", "GT", etc.
        if (preg_match('/\b(SE|Sport|S\s*line|FR|GT|Elegance|Executive|Limited|Prestige|Premium|Base|Standard|R-Design|AMG|RS|M\s*Sport|GLE|GLC|GLA)\b/i', $title, $matches)) {
            $trim = trim($matches[1]);
        }
        
        // Extract year from title (already done but needed for return array)
        if (preg_match('/\b(19|20)\d{2}\b/', $title, $matches)) {
            $year = (int)$matches[0];
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
            'image_url' => $imageUrl,  // Primary image
            'image_urls' => $imageUrls,  // All images
            'vehicle_url' => $vehicleUrl,
            // STEP 3: New fields from title parsing
            'doors' => $doors,
            'registration_plate' => $registration_plate,
            'plate_year' => $plate_year,
            'drive_system' => $drive_system,
            'trim' => $trim,
            'year' => $year,
            // STEP 4: Engine size (will be populated from detail page)
            'engine_size' => null,
        ];
    }

    /**
     * Extract vehicle details from HTML text
     */
    protected function extractVehicleDetails(string $html): array
    {
        $details = [
            'mileage' => null,
            'colour' => null,
            'transmission' => null,
            'fuel_type' => null,
            'body_style' => null,
            'first_reg_date' => null,
            'engine_size' => null,
            'drive_system' => null,
            'vrm' => null,           // UK Vehicle Registration Mark (actual reg number)
            'all_images' => [],      // All vehicle images from detail page
        ];

        // Valid car colors list (used for validation)
        $validColors = [
            'black', 'white', 'silver', 'grey', 'gray', 'red', 'blue', 'green', 'brown', 'beige',
            'gold', 'orange', 'yellow', 'purple', 'pink', 'maroon', 'navy', 'turquoise', 'bronze',
            'cream', 'ivory', 'pearl', 'metallic', 'gunmetal', 'charcoal', 'graphite', 'midnight',
            'bordeaux', 'wine', 'burgundy', 'crimson', 'scarlet', 'cobalt', 'azure', 'teal',
            'olive', 'forest', 'emerald', 'lime', 'mint', 'sage', 'khaki', 'tan', 'copper',
            'rust', 'champagne', 'sand', 'taupe', 'ash', 'smoke', 'slate', 'pewter'
        ];

        // ======== COLOUR EXTRACTION (PRIORITY ORDER) ========
        
        // Pattern 1: Systonautos structured HTML - <span class="vd-detail-name">Colour</span><span class="vd-detail-value">Silver</span>
        if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*Colou?r\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([^<]+)/i', $html, $matches)) {
            $colour = trim($matches[1]);
            if ($this->isValidColour($colour, $validColors)) {
                $details['colour'] = ucfirst(strtolower($colour));
            }
        }
        
        // Pattern 2: Table row format - <th>Colour</th><td>Silver</td>
        if (empty($details['colour']) && preg_match('/<th[^>]*>\s*Colou?r\s*<\/th>\s*<td[^>]*>\s*([^<]+)/i', $html, $matches)) {
            $colour = trim($matches[1]);
            if ($this->isValidColour($colour, $validColors)) {
                $details['colour'] = ucfirst(strtolower($colour));
            }
        }
        
        // Pattern 3: List item format - <li>Colour: Silver</li> or <li>Colour Silver</li>
        if (empty($details['colour']) && preg_match('/<li[^>]*>[^<]*Colou?r[:\s]*([A-Za-z\s\-]+?)(?:<|$)/i', $html, $matches)) {
            $colour = trim($matches[1]);
            if ($this->isValidColour($colour, $validColors)) {
                $details['colour'] = ucfirst(strtolower($colour));
            }
        }
        
        // Pattern 4: Generic text pattern - "Colour: Silver" or "ColourSilver" (no space)
        if (empty($details['colour']) && preg_match('/Colou?r[:\s]*([A-Za-z\s\-]+?)(?:[\s<;|,\n]|Transmission|Fuel|Body|Mileage|Engine|$)/i', $html, $matches)) {
            $colour = trim($matches[1]);
            if ($this->isValidColour($colour, $validColors)) {
                $details['colour'] = ucfirst(strtolower($colour));
            }
        }

        // ======== MILEAGE EXTRACTION ========
        
        // Pattern 1: Structured HTML - <span class="vd-detail-value">75,000</span> after Mileage
        if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*Mileage\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([0-9,]+)/i', $html, $matches)) {
            $details['mileage'] = str_replace(',', '', $matches[1]) . ' miles';
        }
        // Pattern 2: Table row - <th>Mileage</th><td>75,000</td>
        elseif (preg_match('/<th[^>]*>\s*Mileage\s*<\/th>\s*<td[^>]*>\s*([0-9,]+)/i', $html, $matches)) {
            $details['mileage'] = str_replace(',', '', $matches[1]) . ' miles';
        }
        // Pattern 3: Generic text "Mileage75,000" or "Mileage: 75,000"
        elseif (preg_match('/Mileage[:\s]*([0-9,]+)/i', $html, $matches)) {
            $details['mileage'] = str_replace(',', '', $matches[1]) . ' miles';
        }
        // Pattern 4: "75,000 miles" anywhere
        elseif (preg_match('/([0-9,]+)\s*miles/i', $html, $matches)) {
            $details['mileage'] = str_replace(',', '', $matches[1]) . ' miles';
        }

        // ======== ENGINE SIZE EXTRACTION ========
        
        // Pattern 1: Structured HTML - Engine Size span
        if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*Engine\s*Size\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([0-9,]+)/i', $html, $matches)) {
            $details['engine_size'] = (int)str_replace(',', '', $matches[1]);
        }
        // Pattern 2: Table row - <th>Engine Size</th><td>1,969</td>
        elseif (preg_match('/<th[^>]*>\s*Engine\s*(?:Size|Capacity)\s*<\/th>\s*<td[^>]*>\s*([0-9,]+)/i', $html, $matches)) {
            $details['engine_size'] = (int)str_replace(',', '', $matches[1]);
        }
        // Pattern 3: Generic text "Engine Size1,969" or "Engine Size: 1969 cc"
        elseif (preg_match('/Engine\s*(?:Size|Capacity)?[:\s]*([0-9,]+)\s*(?:cc)?/i', $html, $matches)) {
            $engineSize = (int)str_replace(',', '', $matches[1]);
            if ($engineSize >= 600 && $engineSize <= 8000) {
                $details['engine_size'] = $engineSize;
            }
        }

        // ======== TRANSMISSION EXTRACTION ========
        
        // Pattern 1: Structured HTML
        if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*Transmission\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['transmission'] = ucfirst(strtolower(trim($matches[1])));
        }
        // Pattern 2: Table row
        elseif (preg_match('/<th[^>]*>\s*(?:Transmission|Gearbox)\s*<\/th>\s*<td[^>]*>\s*([^<]+)/i', $html, $matches)) {
            $trans = trim($matches[1]);
            // Extract just "Manual" or "Automatic" from "6 speed Manual"
            if (preg_match('/(automatic|manual|semi-auto|cvt)/i', $trans, $transMatch)) {
                $details['transmission'] = ucfirst(strtolower($transMatch[1]));
            } else {
                $details['transmission'] = ucfirst(strtolower($trans));
            }
        }
        // Pattern 3: Generic text
        elseif (preg_match('/(automatic|manual|semi-auto|cvt)/i', $html, $matches)) {
            $details['transmission'] = ucfirst(strtolower($matches[1]));
        }

        // ======== FUEL TYPE EXTRACTION ========
        
        // Pattern 1: Structured HTML
        if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*Fuel\s*Type\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['fuel_type'] = ucfirst(strtolower(trim($matches[1])));
        }
        // Pattern 2: Table row
        elseif (preg_match('/<th[^>]*>\s*Fuel\s*(?:Type)?\s*<\/th>\s*<td[^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['fuel_type'] = ucfirst(strtolower(trim($matches[1])));
        }
        // Pattern 3: Generic text
        elseif (preg_match('/(petrol|diesel|electric|hybrid|plug-in hybrid|phev)/i', $html, $matches)) {
            $details['fuel_type'] = ucfirst(strtolower($matches[1]));
        }

        // ======== BODY STYLE EXTRACTION ========
        
        // Pattern 1: Structured HTML
        if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*Body\s*Style\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['body_style'] = ucfirst(strtolower(trim($matches[1])));
        }
        // Pattern 2: Table row
        elseif (preg_match('/<th[^>]*>\s*Body\s*(?:Style|Type)?\s*<\/th>\s*<td[^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['body_style'] = ucfirst(strtolower(trim($matches[1])));
        }
        // Pattern 3: Generic text
        elseif (preg_match('/(hatchback|saloon|estate|suv|coupe|convertible|mpv|4x4)/i', $html, $matches)) {
            $details['body_style'] = ucfirst(strtolower($matches[1]));
        }

        // ======== FIRST REGISTRATION DATE EXTRACTION ========
        
        // Pattern 1: Structured HTML
        if (preg_match('/<span[^>]*class=["\']vd-detail-name["\'][^>]*>\s*First\s*Registration\s*Date\s*<\/span>\s*<span[^>]*class=["\']vd-detail-value["\'][^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['first_reg_date'] = trim($matches[1]);
        }
        // Pattern 2: Table row
        elseif (preg_match('/<th[^>]*>\s*First\s*Reg(?:istration)?\s*(?:Date)?\s*<\/th>\s*<td[^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['first_reg_date'] = trim($matches[1]);
        }
        // Pattern 3: Generic text
        elseif (preg_match('/(?:first\s*reg(?:istration)?|reg\.?\s*date)[:\s]*(\d{1,2}\/\d{1,2}\/\d{2,4})/i', $html, $matches)) {
            $details['first_reg_date'] = $matches[1];
        }

        // ======== DRIVE SYSTEM EXTRACTION ========
        
        // Pattern 1: Table row - <th>Drive</th><td>4WD</td>
        if (preg_match('/<th[^>]*>\s*Drive(?:\s*System)?\s*<\/th>\s*<td[^>]*>\s*([^<]+)/i', $html, $matches)) {
            $details['drive_system'] = strtoupper(trim($matches[1]));
        }
        // Pattern 2: Generic patterns
        elseif (preg_match('/\b(4WD|AWD|2WD|FWD|RWD|4x4|All[- ]?Wheel[- ]?Drive|Front[- ]?Wheel[- ]?Drive|Rear[- ]?Wheel[- ]?Drive)\b/i', $html, $matches)) {
            $drive = strtoupper(trim($matches[1]));
            // Normalize
            if (stripos($drive, 'ALL') !== false || $drive === '4WD' || $drive === 'AWD' || $drive === '4X4') {
                $details['drive_system'] = 'AWD';
            } elseif (stripos($drive, 'FRONT') !== false || $drive === 'FWD') {
                $details['drive_system'] = 'FWD';
            } elseif (stripos($drive, 'REAR') !== false || $drive === 'RWD') {
                $details['drive_system'] = 'RWD';
            } else {
                $details['drive_system'] = $drive;
            }
        }

        // ======== VRM (UK REGISTRATION NUMBER) EXTRACTION ========
        
        // Pattern 1: Hidden input field - <input type="hidden" name="vrm" value="WP66UEX"/>
        if (preg_match('/<input[^>]*name=["\']vrm["\'][^>]*value=["\']([A-Z0-9]+)["\'][^>]*>/i', $html, $matches)) {
            $details['vrm'] = strtoupper(trim($matches[1]));
        }
        // Pattern 2: Alternative order - <input value="..." name="vrm">
        elseif (preg_match('/<input[^>]*value=["\']([A-Z0-9]+)["\'][^>]*name=["\']vrm["\'][^>]*>/i', $html, $matches)) {
            $details['vrm'] = strtoupper(trim($matches[1]));
        }
        // Pattern 3: JavaScript VC_SETTINGS - vrn: 'WP66UEX'
        elseif (preg_match('/vrn["\']?\s*[:=]\s*["\']([A-Z0-9]+)["\']/i', $html, $matches)) {
            $details['vrm'] = strtoupper(trim($matches[1]));
        }
        // Pattern 4: UK reg format in quoted strings (AA00 AAA or AA00AAA pattern)
        elseif (preg_match('/["\']([A-Z]{2}[0-9]{2}\s?[A-Z]{3})["\']/i', $html, $matches)) {
            $details['vrm'] = strtoupper(str_replace(' ', '', trim($matches[1])));
        }

        // ======== ALL IMAGES EXTRACTION (for detail page) ========
        
        // Pattern 1: aacarsdna.com images (common vehicle image CDN)
        if (preg_match_all('/src=["\']([^"\']*aacarsdna\.com\/images\/vehicles[^"\']+\.(?:jpg|jpeg|png|webp))/i', $html, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                if (!in_array($imgUrl, $details['all_images'])) {
                    $details['all_images'][] = $imgUrl;
                }
            }
        }
        
        // Pattern 2: Stock images with /stock/ in path
        if (preg_match_all('/src=["\']([^"\']*\/stock\/[^"\']+\.(?:jpg|jpeg|png|webp))/i', $html, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                if (!in_array($imgUrl, $details['all_images'])) {
                    $details['all_images'][] = $imgUrl;
                }
            }
        }
        
        // Pattern 3: Data-src lazy loaded images (vehicle galleries often use this)
        if (preg_match_all('/data-src=["\']([^"\']+\.(?:jpg|jpeg|png|webp))/i', $html, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                // Only include vehicle-related images, skip icons/logos
                if (stripos($imgUrl, 'vehicle') !== false || 
                    stripos($imgUrl, 'stock') !== false ||
                    stripos($imgUrl, 'aacarsdna') !== false) {
                    if (!in_array($imgUrl, $details['all_images'])) {
                        $details['all_images'][] = $imgUrl;
                    }
                }
            }
        }
        
        // Pattern 4: Background images in style attributes
        if (preg_match_all('/background-image:\s*url\(["\']?([^"\')\s]+\.(?:jpg|jpeg|png|webp))/i', $html, $matches)) {
            foreach ($matches[1] as $imgUrl) {
                if (stripos($imgUrl, 'vehicle') !== false || 
                    stripos($imgUrl, 'stock') !== false ||
                    stripos($imgUrl, 'aacarsdna') !== false) {
                    if (!in_array($imgUrl, $details['all_images'])) {
                        $details['all_images'][] = $imgUrl;
                    }
                }
            }
        }

        return $details;
    }

    /**
     * Validate if a colour string is a valid car colour
     */
    protected function isValidColour(string $colour, array $validColors): bool
    {
        $colour = trim($colour);
        $colourLower = strtolower($colour);
        
        // Must be 2-30 characters and only letters/spaces/hyphens
        if (strlen($colour) < 2 || strlen($colour) > 30 || !preg_match('/^[a-zA-Z\s\-]+$/', $colour)) {
            return false;
        }
        
        // Check against valid colors list
        foreach ($validColors as $validColor) {
            if ($colourLower === $validColor || strpos($colourLower, $validColor) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Enrich vehicles with full descriptions from detail pages
     */
    protected function enrichWithDetailPages(array $vehicles): array
    {
        $delay = $this->config['scraper']['request_delay'];
        $total = count($vehicles);
        
        foreach ($vehicles as $index => &$vehicle) {
            $this->log("  Processing " . ($index + 1) . "/$total: {$vehicle['external_id']}");
            
            $html = $this->fetchUrl($vehicle['vehicle_url']);
            
            if ($html) {
                // Extract full description
                $fullDesc = $this->extractFullDescription($html);
                if ($fullDesc) {
                    $vehicle['description_full'] = $fullDesc;
                }
                
                // ALWAYS extract details from detail page - this is where accurate data is
                $details = $this->extractVehicleDetails($html);
                foreach ($details as $key => $value) {
                    // Override with detail page data if available (more accurate)
                    if (!empty($value)) {
                        $vehicle[$key] = $value;
                    }
                }
                
                // CRITICAL: Use VRM as the real registration number (reg_no)
                // This replaces the URL slug with actual UK reg like "WP66UEX"
                if (!empty($details['vrm'])) {
                    $vehicle['reg_no'] = $details['vrm'];
                    $this->log("    Found VRM: {$details['vrm']}");
                }
                
                // CRITICAL: Merge all images from detail page
                if (!empty($details['all_images'])) {
                    // Combine with existing images, remove duplicates
                    $existingImages = $vehicle['image_urls'] ?? [];
                    $allImages = array_unique(array_merge($existingImages, $details['all_images']));
                    $vehicle['image_urls'] = array_values($allImages);
                    $this->log("    Found " . count($details['all_images']) . " images (total: " . count($vehicle['image_urls']) . ")");
                }
                
                // Log what we found for debugging
                if (!empty($details['colour'])) {
                    $this->log("    Found colour: {$details['colour']}");
                }
                if (!empty($details['engine_size'])) {
                    $this->log("    Found engine_size: {$details['engine_size']}");
                }
                if (!empty($details['transmission'])) {
                    $this->log("    Found transmission: {$details['transmission']}");
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
    protected function extractFullDescription(string $html): ?string
    {
        // Try to get description from meta tag
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)/i', $html, $matches)) {
            $description = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/content=["\']([^"\']+)["\']\s+name=["\']description/i', $html, $matches)) {
            $description = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            return null;
        }

        // Remove finance-related text AND keep specs
        $description = $this->removeFinanceText($description);
        
        // Use specialized description cleaner that preserves pipe format specs
        return $this->cleanDescriptionText($description);
    }

    /**
     * Remove finance-related text from description
     */
    protected function removeFinanceText(string $text): string
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
     * STEP 4: Extract engine size from detail page HTML
     */
    protected function extractEngineSize(string $html): ?int
    {
        $engineSize = null;
        
        // Try multiple patterns to find engine size
        // Pattern 1: "Engine Size: 1,598 cc" or "Engine Size: 1598 cc"
        if (preg_match('/Engine\s*(?:Size|Capacity)?[:\s]*([0-9,]+)\s*(?:cc|cubic)/i', $html, $matches)) {
            $engineSize = (int)str_replace(',', '', $matches[1]);
        }
        // Pattern 2: "1,598cc" or "1598 cc"
        elseif (preg_match('/\b([0-9,]+)\s*(?:cc|cubic\s*centimetres?)\b/i', $html, $matches)) {
            $engineSize = (int)str_replace(',', '', $matches[1]);
        }
        // Pattern 3: Engine displacement in specification table
        elseif (preg_match('/(?:displacement|engine\s*capacity)[:\s]*([0-9,]+)\s*(?:cc|ml)/i', $html, $matches)) {
            $engineSize = (int)str_replace(',', '', $matches[1]);
        }
        
        // Validate: engine sizes should be between 600cc and 8000cc for cars
        if ($engineSize && $engineSize >= 600 && $engineSize <= 8000) {
            return $engineSize;
        }
        
        return null;
    }

    /**
     * Save vehicles to database
     */
    protected function saveVehicles(array $vehicles): array
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
    protected function deactivateMissingVehicles(array $activeIds): int
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
    protected function saveJsonSnapshot(): void
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
    protected function normalizeUrl(?string $url): ?string
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
    protected function extractExternalId(string $url): ?string
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
    protected function extractNumericPrice(?string $price): ?float
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
    protected function extractNumericMileage(?string $mileage): ?float
    {
        if (!$mileage) {
            return null;
        }
        // Remove all non-numeric characters except decimal point
        $numeric = preg_replace('/[^0-9.]/', '', $mileage);
        return ($numeric && $numeric !== '') ? (float)$numeric : null;
    }

    /**
     * Clean text (remove extra whitespace, garbage characters, etc.) - AGGRESSIVE
     */
    /**
     * GENTLE text cleaner for short descriptions (preserves normal UTF-8)
     */
    protected function cleanText(string $text): string
    {
        // 1. Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Remove null bytes and control characters
        $text = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $text);

        // 3. Fix broken UTF-8 sequences only (don't replace all non-ASCII)
        $text = preg_replace('/[\xC0-\xC3][\x80-\xBF]+/', '', $text);
        $text = preg_replace('/[\xE0-\xEF][\x80-\xBF]{2}/', '', $text);

        // 4. Replace specific HTML encoding artifacts
        $text = str_replace([
            'â¦', 'â€™', 'â€œ', 'â€"', 'â€"', 'â„¢',
        ], [
            '...', "'", '"', '-', '-', 'TM'
        ], $text);

        // 5. Clean up excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\.{4,}/', '...', $text);

        // 6. Trim
        $text = trim($text);

        return $text;
    }

    /**
     * SPECIALIZED cleaner for FULL descriptions (preserves specs with pipes)
     * Keeps pipe-separated specifications intact while removing extra whitespace
     */
    protected function cleanDescriptionText(string $text): string
    {
        // 1. Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Remove null bytes and control characters
        $text = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $text);

        // 3. PRESERVE pipe-separated specs - normalize spacing around pipes
        // Clean whitespace around pipes but keep the pipes
        $text = preg_replace('/\s*\|\s*/', '|', $text);

        // 4. Replace broken UTF-8 sequences
        $text = preg_replace('/[\xC0-\xC3][\x80-\xBF]+/', '', $text);
        $text = preg_replace('/[\xE0-\xEF][\x80-\xBF]{2}/', '', $text);

        // 5. Replace specific HTML encoding artifacts
        $text = str_replace([
            'â¦', 'â€™', 'â€œ', 'â€"', 'â€"', 'â„¢',
        ], [
            '...', "'", '"', '-', '-', 'TM'
        ], $text);

        // 6. Clean up excessive whitespace BUT preserve pipe structure
        $text = preg_replace('/(?<!\|)\s{2,}(?!\|)/', ' ', $text);
        $text = preg_replace('/\.{4,}/', '...', $text);

        // 7. Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Log a message
     */
    protected function log(string $message): void
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
    protected function startScrapeLog(): void
    {
        $sql = "INSERT INTO scrape_logs (source, started_at, status) VALUES (?, NOW(), 'running')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->config['scraper']['source']]);
        $this->logId = (int)$this->db->lastInsertId();
    }

    /**
     * Finish a scrape log entry
     */
    protected function finishScrapeLog(string $status, ?string $error = null): void
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

    /**
     * Calculate hash of key vehicle data for change detection
     * Used to detect if vehicle data has changed since last scrape
     *
     * @param array $vehicle Vehicle data array
     * @return string MD5 hash of combined key fields
     */
    protected function calculateDataHash(array $vehicle): string
    {
        $keyFields = [
            $vehicle['title'] ?? '',
            $vehicle['selling_price'] ?? 0,
            $vehicle['mileage'] ?? 0,
            $vehicle['description'] ?? '',
            $vehicle['model'] ?? '',
            $vehicle['year'] ?? 0,
            $vehicle['fuel_type'] ?? '',
            $vehicle['transmission'] ?? '',
        ];

        $hashInput = implode('|', array_map(function($field) {
            if (is_string($field)) {
                // Normalize whitespace in text fields
                return trim(preg_replace('/\s+/', ' ', $field));
            }
            return (string)$field;
        }, $keyFields));

        return md5($hashInput);
    }

    /**
     * Check if a vehicle's data has changed based on stored hash
     *
     * @param array $vehicle Current vehicle data
     * @param string|null $storedHash Previously calculated hash (from database)
     * @return bool True if data has changed or no stored hash exists
     */
    protected function hasDataChanged(array $vehicle, ?string $storedHash): bool
    {
        if ($storedHash === null) {
            return true; // New vehicle, always "changed"
        }

        $currentHash = $this->calculateDataHash($vehicle);
        return $currentHash !== $storedHash;
    }

    /**
     * Clean and rotate JSON output files (keep only last 2)
     * Adds timestamp to filename for tracking
     *
     * @param string $outputFile Path to JSON file
     * @return array Array of rotated files (kept files)
     */
    protected function rotateJsonFiles(string $outputFile): array
    {
        $dir = dirname($outputFile);
        $basename = basename($outputFile, '.json');
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            return [];
        }

        // Find all timestamped JSON files matching pattern
        $files = glob($dir . '/' . $basename . '_*.json');
        
        if (empty($files)) {
            return [];
        }

        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        // Keep only last 2 files, delete rest
        $keptFiles = [];
        foreach ($files as $idx => $file) {
            if ($idx < 2) {
                $keptFiles[] = $file;
            } else {
                @unlink($file);
                $this->log('Deleted old JSON file: ' . basename($file));
            }
        }

        return $keptFiles;
    }

    /**
     * Create timestamped JSON filename and clean old versions
     *
     * @param string $outputFile Base output file path
     * @return string New timestamped filename
     */
    protected function getTimestampedJsonFile(string $outputFile): string
    {
        $dir = dirname($outputFile);
        $basename = basename($outputFile, '.json');
        $timestamp = date('YYYYMMDDHHmmss');
        
        // Rotate old files first
        $this->rotateJsonFiles($outputFile);
        
        // Return new timestamped filename
        return $dir . '/' . $basename . '_' . $timestamp . '.json';
    }

    /**
     * Clean up old log files (keep only last 7 days)
     * Log files are named scraper_YYYY-MM-DD.log
     *
     * @return int Number of logs deleted
     */
    protected function cleanupOldLogs(): int
    {
        $logsDir = $this->config['paths']['logs'];
        
        if (!is_dir($logsDir)) {
            return 0;
        }

        $logFiles = glob($logsDir . '/scraper_*.log');
        $deleted = 0;
        $retentionDays = 7;
        $cutoffTime = time() - ($retentionDays * 86400);

        foreach ($logFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                @unlink($file);
                $deleted++;
                $this->log('Deleted old log file: ' . basename($file));
            }
        }

        return $deleted;
    }

    /**
     * Get database hash for a vehicle (if exists)
     * Override in subclasses for database-specific implementations
     *
     * @param string $registrationNumber Vehicle registration number
     * @return string|null Stored data hash or null if not found
     */
    protected function getStoredDataHash(string $registrationNumber): ?string
    {
        // Base implementation returns null
        // CarSafariScraper will override to query gyc_vehicle_info.data_hash
        return null;
    }
}
