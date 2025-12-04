<?php
/**
 * CarCheck Integration
 * Fetches vehicle color and other details from carcheck.co.uk
 * to enrich scraped vehicle data
 */

class CarCheckIntegration
{
    private $timeout = 30;
    private $validColors = [
        'black', 'white', 'silver', 'grey', 'gray', 'red', 'blue', 'green',
        'brown', 'beige', 'cream', 'ivory', 'orange', 'yellow', 'pink',
        'purple', 'metallic', 'pearl', 'gunmetal', 'charcoal', 'bronze',
        'champagne', 'tan', 'khaki', 'taupe', 'sage', 'navy', 'midnight',
        'forest', 'emerald', 'cobalt', 'azure', 'teal', 'olive', 'copper',
        'rust', 'sand', 'ash', 'smoke', 'slate', 'pewter', 'graphite', 'lime', 'mint'
    ];

    /**
     * Fetch vehicle details from carcheck.co.uk
     * @param string $regNo - Vehicle registration number
     * @return array - Extracted vehicle details
     */
    public function fetchVehicleData($regNo)
    {
        $data = [];
        
        try {
            // Try to extract make from reg_no (e.g., "volvo-v40..." → "volvo")
            $make = $this->extractMakeFromRegNo($regNo);
            
            if (!$make) {
                return $data; // Can't construct URL without make
            }

            $url = "https://www.carcheck.co.uk/{$make}/{$regNo}";
            
            $html = $this->fetchUrl($url);
            if (!$html) {
                return $data;
            }

            // Parse the HTML
            $dom = new DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);

            // Extract color
            $color = $this->extractColor($xpath);
            if ($color) {
                $data['color'] = $color;
            }

            // Extract other useful details
            $details = $this->extractVehicleDetails($xpath);
            $data = array_merge($data, $details);

            return $data;

        } catch (Exception $e) {
            error_log("CarCheck integration error for {$regNo}: " . $e->getMessage());
            return $data;
        }
    }

    /**
     * Extract make from registration number
     * Examples: "volvo-v40-..." → "volvo", "nissan-micra-..." → "nissan"
     * @param string $regNo
     * @return string|null
     */
    private function extractMakeFromRegNo($regNo)
    {
        if (empty($regNo)) {
            return null;
        }

        // Split by hyphen and take the first part
        $parts = explode('-', $regNo);
        if (count($parts) > 0) {
            return strtolower($parts[0]);
        }

        return null;
    }

    /**
     * Fetch URL via cURL
     * @param string $url
     * @return string|false - HTML content or false on failure
     */
    private function fetchUrl($url)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $output = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception("cURL error: {$error}");
            }

            return $output;

        } catch (Exception $e) {
            error_log("URL fetch error for {$url}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract color from carcheck.co.uk HTML
     * Looks for color in various patterns on the page
     * @param DOMXPath $xpath
     * @return string|null - Color name if found
     */
    private function extractColor($xpath)
    {
        // Try to find color in specification tables
        // Pattern: Look for "Colour" or "Color" label with value
        
        // Search all table rows
        $rows = $xpath->query("//tr");
        
        foreach ($rows as $row) {
            $cells = $xpath->query(".//td|.//th", $row);
            
            if ($cells->length >= 2) {
                $label = trim($cells->item(0)->textContent);
                $value = trim($cells->item(1)->textContent);
                
                // Check if label contains "colour" or "color"
                if (stripos($label, 'colour') !== false || stripos($label, 'color') !== false) {
                    // Extract color name and validate
                    $color = $this->cleanColorValue($value);
                    if ($color && in_array(strtolower($color), $this->validColors)) {
                        return $color;
                    }
                }
            }
        }

        // Alternative: Try to find in divs with specific patterns
        $divs = $xpath->query("//div[contains(@class, 'specification') or contains(@class, 'detail')]");
        
        foreach ($divs as $div) {
            $text = $div->textContent;
            
            if (stripos($text, 'colour:') !== false || stripos($text, 'color:') !== false) {
                // Try to extract color value
                if (preg_match('/colo?ur:\s*([a-z]+)/i', $text, $matches)) {
                    $color = $this->cleanColorValue($matches[1]);
                    if ($color && in_array(strtolower($color), $this->validColors)) {
                        return $color;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Clean color value (remove extra text, normalize)
     * @param string $value
     * @return string|null
     */
    private function cleanColorValue($value)
    {
        // Remove common suffixes and special characters
        $value = trim($value);
        $value = preg_replace('/\s*\(.*?\)/', '', $value); // Remove parenthetical info
        $value = preg_replace('/\s*\|.*$/', '', $value); // Remove pipe-separated info
        $value = preg_replace('/\s+/', ' ', $value); // Normalize spaces

        // Take only first word if multiple
        $parts = explode(' ', $value);
        $value = $parts[0];

        if (empty($value) || strlen($value) < 2) {
            return null;
        }

        return $value;
    }

    /**
     * Extract additional vehicle details from carcheck HTML
     * @param DOMXPath $xpath
     * @return array
     */
    private function extractVehicleDetails($xpath)
    {
        $details = [];

        // Try to extract registration date, MOT expiry, etc.
        $rows = $xpath->query("//tr");
        
        foreach ($rows as $row) {
            $cells = $xpath->query(".//td|.//th", $row);
            
            if ($cells->length >= 2) {
                $label = strtolower(trim($cells->item(0)->textContent));
                $value = trim($cells->item(1)->textContent);

                // Extract specific fields
                if (stripos($label, 'registered') !== false) {
                    $details['registration_date'] = $value;
                }
                if (stripos($label, 'mot') !== false) {
                    $details['mot_expiry'] = $value;
                }
                if (stripos($label, 'fuel') !== false) {
                    $details['fuel_type_carcheck'] = $value;
                }
                if (stripos($label, 'transmission') !== false || stripos($label, 'gearbox') !== false) {
                    $details['transmission_carcheck'] = $value;
                }
            }
        }

        return $details;
    }
}

?>
