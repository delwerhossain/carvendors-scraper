#!/usr/bin/env php
<?php
/**
 * CarSafari Scraper Runner
 *
 * Scrapes vehicle listings and publishes directly to CarSafari database
 *
 * Usage:
 *   php scrape-carsafari.php
 *   php scrape-carsafari.php --no-details   (skip detail page fetching)
 *   php scrape-carsafari.php --no-json      (skip JSON snapshot)
 *   php scrape-carsafari.php --vendor=2     (use vendor ID 2)
 *
 * Cron examples:
 *   0 6 * * * /usr/bin/php /home/username/public_html/carvendors-scraper/scrape-carsafari.php
 *   0 6,18 * * * /usr/bin/php /home/username/public_html/carvendors-scraper/scrape-carsafari.php
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Europe/London');

// Increase memory limit for large scrapes
ini_set('memory_limit', '512M');

// Set execution time limit (30 minutes)
set_time_limit(1800);

// Define base path
define('BASE_PATH', __DIR__);

// Load the scraper classes
require_once BASE_PATH . '/CarScraper.php';
require_once BASE_PATH . '/CarSafariScraper.php';

// Load configuration
$configFile = BASE_PATH . '/config.php';
if (!file_exists($configFile)) {
    die("ERROR: Configuration file not found. Please copy config.example.php to config.php and update with your settings.\n");
}

$config = require $configFile;

// Parse command line arguments
$options = getopt('', ['no-details', 'no-json', 'vendor:', 'help']);

if (isset($options['help'])) {
    echo "CarSafari Scraper\n";
    echo "==================\n\n";
    echo "Usage: php scrape-carsafari.php [options]\n\n";
    echo "Options:\n";
    echo "  --no-details  Skip fetching individual vehicle detail pages\n";
    echo "  --no-json     Skip generating JSON snapshot file\n";
    echo "  --vendor=ID   Set vendor ID (default: 1)\n";
    echo "  --help        Show this help message\n\n";
    exit(0);
}

// Apply command line overrides
if (isset($options['no-details'])) {
    $config['scraper']['fetch_detail_pages'] = false;
    echo "Note: Skipping detail page fetching (--no-details)\n";
}

if (isset($options['no-json'])) {
    $config['output']['save_json'] = false;
    echo "Note: Skipping JSON snapshot (--no-json)\n";
}

$vendorId = isset($options['vendor']) ? (int)$options['vendor'] : 1;
echo "Using vendor ID: $vendorId\n";

// Start the scraper
echo "==============================================\n";
echo "CarSafari Scraper - " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

try {
    $scraper = new CarSafariScraper($config, 'carsafari');

    // Set vendor ID if custom
    if ($vendorId !== 1) {
        $scraper->setVendorId($vendorId);
    }

    $result = $scraper->runWithCarSafari();

    echo "\n==============================================\n";
    if ($result['success']) {
        echo "COMPLETED SUCCESSFULLY\n";
        echo "Found: " . $result['stats']['found'] . "\n";
        echo "Inserted: " . $result['stats']['inserted'] . "\n";
        echo "Updated: " . $result['stats']['updated'] . "\n";
        echo "Published: " . ($result['stats']['published'] ?? 0) . "\n";
        exit(0);
    } else {
        echo "FAILED: {$result['error']}\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
