<?php

/**
 * Configuration for Car Listings Scraper
 *
 * Local WAMP Setup
 */

return [
    // Database configuration
    'database' => [
        'host'     => 'localhost',
        'dbname'   => 'tst-car',      // Your database name
        'username' => 'root',         // WAMP default user
        'password' => '',             // WAMP default (empty)
        'charset'  => 'utf8mb4',
    ],

    // Scraper settings
    'scraper' => [
        // Source identifier (used in database)
        'source' => 'systonautosltd',

        // Base URL for the dealer
        'base_url' => 'https://systonautosltd.co.uk',

        // Listing page URL
        'listing_url' => 'https://systonautosltd.co.uk/vehicle/search/min_price/0/order/price/dir/DESC/limit/250/',

        // Delay between requests in seconds (be polite!)
        'request_delay' => 1.5,

        // Whether to fetch detail pages for full descriptions
        'fetch_detail_pages' => true,

        // Request timeout in seconds
        'timeout' => 30,

        // User agent string
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',

        // Disable SSL verification for local development (Windows WAMP)
        'verify_ssl' => false,
    ],

    // Output settings
    'output' => [
        // Whether to save JSON snapshot
        'save_json' => true,

        // Path for JSON output (relative to script directory)
        'json_path' => __DIR__ . '/data/vehicles.json',

        // Path for logs
        'log_path' => __DIR__ . '/logs/',
    ],

    // Path settings
    'paths' => [
        'logs' => __DIR__ . '/logs/',
        'images' => __DIR__ . '/images/',
        'data' => __DIR__ . '/data/',
    ],

    // Patterns to identify where to cut off finance text in descriptions
    // DISABLED - Keep complete descriptions including finance information
    'description_cutoff_patterns' => [
        //'Finance available',
        //'Finance Available',
        //'FINANCE AVAILABLE',
        //'finance available',
        //'Representative Example',
        //'REPRESENTATIVE EXAMPLE',
        //'Monthly Payment',
        //'APR Representative',
        //'Total Amount Payable',
        //'Credit subject to',
        //'Terms and conditions apply',
        //'We are authorised',
        //'We are a credit broker',
        //'12 months free AA breakdown cover',
        //'5 days drive away insurance',
        //'Full dealer facilities available',
        //'If you are coming by train',
        //'We are open 7 days a week',
        //'Every effort has been made',
        //'£99 Admin Fee',
        //'The vehicle specification displayed',
        //'We specialize in the sales',
        //'A trusted dealership',
        // Keep all finance and dealer information as requested
    ],

    // CarCheck Integration Settings (Phase 6)
    'carcheck' => [
        // Enable/disable CarCheck integration
        'enabled' => true,

        // Cache time-to-live in seconds (30 minutes default)
        'cache_ttl' => 1800,

        // Request delay between API calls in seconds (rate limiting)
        'request_delay' => 1.5,

        // Maximum retries for failed API calls
        'max_retries' => 3,

        // Request timeout in seconds
        'timeout' => 30,

        // Batch processing size (how many vehicles per batch)
        'batch_size' => 10,

        // Enable automatic error recovery
        'auto_retry' => true,
    ],
];
