#!/usr/bin/env php
<?php
/**
 * CRON Job Setup Script
 *
 * Generates the appropriate CRON commands for different hosting environments
 * and creates a .cron file for easy setup.
 */

echo "CarVendors Scraper - CRON Job Setup\n";
echo "====================================\n\n";

// Default settings
$defaultVendor = 432;
$phpPath = '/usr/bin/php';  // Default for Linux hosting
$scriptPath = __DIR__;

echo "Default Settings:\n";
echo "  Vendor ID: $defaultVendor\n";
echo "  PHP Path: $phpPath\n";
echo "  Script Path: $scriptPath\n\n";

// CRON schedule options
$schedules = [
    'daily' => [
        'name' => 'Daily Refresh (Recommended)',
        'cron' => '0 2 * * *',  // 2:00 AM daily
        'description' => 'Runs every day at 2:00 AM'
    ],
    'twice_daily' => [
        'name' => 'Twice Daily',
        'cron' => '0 2,14 * * *',  // 2:00 AM and 2:00 PM
        'description' => 'Runs twice daily at 2:00 AM and 2:00 PM'
    ],
    'hourly' => [
        'name' => 'Hourly',
        'cron' => '0 * * * *',  // Every hour
        'description' => 'Runs every hour'
    ],
    'weekly' => [
        'name' => 'Weekly',
        'cron' => '0 2 * * 0',  // Sunday 2:00 AM
        'description' => 'Runs weekly on Sunday at 2:00 AM'
    ]
];

echo "Recommended Schedules:\n";
foreach ($schedules as $key => $schedule) {
    echo "  $key: {$schedule['name']} ({$schedule['cron']})\n";
    echo "       {$schedule['description']}\n\n";
}

// Generate CRON commands
echo "Generated CRON Commands:\n";
echo "========================\n\n";

$dailyRefresh = "$phpPath $scriptPath/daily_refresh.php --vendor=$defaultVendor";
$cleanupWeekly = "$phpPath $scriptPath/cleanup_orphaned_attributes.php";

foreach ($schedules as $key => $schedule) {
    echo "{$schedule['name']}:\n";
    echo "{$schedule['cron']} $dailyRefresh\n";
    echo "\n";
}

echo "Weekly Cleanup (Recommended):\n";
echo "0 3 * * 0 $cleanupWeekly --confirm\n";
echo "           (Sunday 3:00 AM - after daily refresh)\n\n";

// Environment-specific setups
echo "Environment Setup Instructions:\n";
echo "=================================\n\n";

echo "1. cPanel Hosting:\n";
echo "   - Go to cPanel > Cron Jobs\n";
echo "   - Add New Cron Job\n";
echo "   - Use one of the commands above\n";
echo "   - Set email notifications\n\n";

echo "2. Plesk Hosting:\n";
echo "   - Go to Websites & Domains > Scheduled Tasks\n";
echo "   - Add Task\n";
echo "   - Select 'Run a command'\n";
echo "   - Use one of the commands above\n\n";

echo "3. Direct SSH/Server:\n";
echo "   - Run: crontab -e\n";
echo "   - Add one of the lines below:\n\n";

echo "4. DirectAdmin:\n";
echo "   - Go to Advanced Features > Cron Jobs\n";
echo "   - Create New Cron Job\n";
echo "   - Use one of the commands above\n\n";

// Create .cron file
$cronFile = __DIR__ . '/.cron.example';
$cronContent = "# CarVendors Scraper - CRON Jobs\n";
$cronContent .= "# Generated on " . date('Y-m-d H:i:s') . "\n\n";
$cronContent .= "# Daily refresh at 2:00 AM (recommended)\n";
$cronContent .= "0 2 * * * $phpPath $scriptPath/daily_refresh.php --vendor=$defaultVendor\n\n";
$cronContent .= "# Weekly cleanup on Sunday at 3:00 AM\n";
$cronContent .= "0 3 * * 0 $phpPath $scriptPath/cleanup_orphaned_attributes.php --confirm\n\n";
$cronContent .= "# Example: Twice daily (2 AM and 2 PM)\n";
$cronContent .= "# 0 2,14 * * * $phpPath $scriptPath/daily_refresh.php --vendor=$defaultVendor\n\n";
$cronContent .= "# Example: Hourly (use with caution)\n";
$cronContent .= "# 0 * * * * $phpPath $scriptPath/daily_refresh.php --vendor=$defaultVendor\n";

file_put_contents($cronFile, $cronContent);
echo "✅ Created example CRON file: $cronFile\n\n";

echo "Monitoring & Logging:\n";
echo "=====================\n";
echo "- Logs are stored in: logs/scraper_YYYY-MM-DD.log\n";
echo "- Statistics are tracked in: scraper_statistics table\n";
echo "- Check logs daily for any errors\n";
echo "- Monitor database size and performance\n\n";

echo "Performance Tips:\n";
echo "=================\n";
echo "- Daily refresh uses smart change detection (minimal processing)\n";
echo "- Weekly cleanup prevents database bloat\n";
echo "- Avoid running during peak traffic hours\n";
echo "- Monitor execution time in logs\n\n";

echo "🚀 Setup Complete! Choose your schedule and add the appropriate CRON command.\n";