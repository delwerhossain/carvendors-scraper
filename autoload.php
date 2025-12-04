<?php
/**
 * CarVendors Scraper - Autoloader
 * 
 * Automatically loads class files from the src/ directory
 * Supports both PSR-4 and simple class-to-file mapping
 */

class Autoloader
{
    private static $classMap = [
        'CarScraper' => __DIR__ . '/src/CarScraper.php',
        'CarSafariScraper' => __DIR__ . '/src/CarSafariScraper.php',
        'CarCheckIntegration' => __DIR__ . '/src/CarCheckIntegration.php',
        'CarCheckEnhanced' => __DIR__ . '/src/CarCheckEnhanced.php',
        'StatisticsManager' => __DIR__ . '/src/StatisticsManager.php',
    ];

    /**
     * Register the autoloader
     */
    public static function register()
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    /**
     * Load a class file
     */
    public static function autoload($class)
    {
        // Check class map first
        if (isset(self::$classMap[$class])) {
            $file = self::$classMap[$class];
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }

        // PSR-4 style fallback
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($path)) {
            require_once $path;
            return true;
        }

        return false;
    }
}

// Register on include
Autoloader::register();
