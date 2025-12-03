#!/usr/bin/env php
<?php
/**
 * Car Listings Scraper - Cron Runner
 * 
 * This script is designed to be run via cron job.
 * It scrapes the dealer website and updates the database.
 * 
 * Usage:
 *   php scrape.php
 *   php scrape.php --no-details   (skip fetching individual detail pages)
 *   php scrape.php --no-json      (skip JSON snapshot generation)
 * 
 * Cron example (daily at 6 AM):
 *   0 6 * * * /usr/bin/php /home/username/public_html/car-scraper/scrape.php >> /home/username/logs/scraper.log 2>&1
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
ini_set('memory_limit', '256M');

// Set execution time limit (30 minutes should be plenty)
set_time_limit(1800);

// Define base path
define('BASE_PATH', __DIR__);

// Load the scraper class
require_once BASE_PATH . '/CarScraper.php';

// Load configuration
$configFile = BASE_PATH . '/config.php';
if (!file_exists($configFile)) {
    die("ERROR: Configuration file not found. Please copy config.example.php to config.php and update with your settings.\n");
}

$config = require $configFile;

// Parse command line arguments
$options = getopt('', ['no-details', 'no-json', 'help']);

if (isset($options['help'])) {
    echo "Car Listings Scraper\n";
    echo "====================\n\n";
    echo "Usage: php scrape.php [options]\n\n";
    echo "Options:\n";
    echo "  --no-details  Skip fetching individual vehicle detail pages\n";
    echo "  --no-json     Skip generating JSON snapshot file\n";
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

// Start the scraper
echo "==============================================\n";
echo "Car Listings Scraper - " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

try {
    $scraper = new CarScraper($config);
    $result = $scraper->run();
    
    echo "\n==============================================\n";
    if ($result['success']) {
        echo "COMPLETED SUCCESSFULLY\n";
        echo "Found: {$result['stats']['found']}\n";
        echo "Inserted: {$result['stats']['inserted']}\n";
        echo "Updated: {$result['stats']['updated']}\n";
        echo "Deactivated: {$result['stats']['deactivated']}\n";
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
