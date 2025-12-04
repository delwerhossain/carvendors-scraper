<?php
/**
 * StatisticsManager - Handles statistics persistence, reporting, and analysis
 * 
 * Manages persistent storage of scraper performance metrics, generates
 * reports in multiple formats, detects anomalies, and provides historical
 * analysis capabilities.
 * 
 * @package CarVendors\Scrapers
 * @author AI Agent
 * @version 1.0.0
 */

namespace CarVendors\Scrapers;

use PDO;
use DateTime;
use Exception;

class StatisticsManager
{
    private PDO $db;
    private array $config;
    private array $currentStats = [];
    private DateTime $startTime;
    
    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param array $config Configuration array
     */
    public function __construct(PDO $db, array $config = [])
    {
        $this->db = $db;
        $this->config = $config;
        $this->startTime = new DateTime();
    }

    /**
     * Initialize statistics tracking for a new scrape run
     *
     * @param int $vendorId The vendor ID
     * @param string $sourceName Optional source name
     * @return void
     */
    public function initializeStatistics(int $vendorId = 432, string $sourceName = null): void
    {
        $this->currentStats = [
            'vendor_id' => $vendorId,
            'source_name' => $sourceName ?? 'Systonautos Ltd',
            'scrape_date' => date('Y-m-d'),
            'scrape_time' => date('H:i:s'),
            'scrape_datetime' => date('Y-m-d H:i:s'),
            'vehicles_found' => 0,
            'vehicles_inserted' => 0,
            'vehicles_updated' => 0,
            'vehicles_skipped' => 0,
            'vehicles_deactivated' => 0,
            'total_images' => 0,
            'images_stored' => 0,
            'error_count' => 0,
            'warning_count' => 0,
            'status' => 'in_progress'
        ];
        $this->startTime = new DateTime();
    }

    /**
     * Record vehicle processing statistics
     *
     * @param string $action The action performed (inserted, updated, skipped)
     * @param int $count Number of vehicles affected
     * @return void
     */
    public function recordVehicleAction(string $action, int $count = 1): void
    {
        $validActions = ['inserted', 'updated', 'skipped', 'deactivated'];
        
        if (!in_array($action, $validActions)) {
            throw new Exception("Invalid action: {$action}");
        }
        
        $key = "vehicles_{$action}";
        if (isset($this->currentStats[$key])) {
            $this->currentStats[$key] += $count;
        }
        
        // Also increment found count if not already counted
        if ($action !== 'skipped') {
            $this->currentStats['vehicles_found'] += $count;
        }
    }

    /**
     * Record image statistics
     *
     * @param int $totalImages Total images processed
     * @param int $imagesStored Number of images actually stored
     * @return void
     */
    public function recordImageStatistics(int $totalImages, int $imagesStored): void
    {
        $this->currentStats['total_images'] = $totalImages;
        $this->currentStats['images_stored'] = $imagesStored;
    }

    /**
     * Record an error
     *
     * @param string $errorType The type of error (network, parse, database, etc.)
     * @param string $message The error message
     * @param string $errorCode Optional error code
     * @param int $vehicleId Optional affected vehicle ID
     * @return void
     */
    public function recordError(
        string $errorType,
        string $message,
        string $errorCode = null,
        int $vehicleId = null
    ): void {
        $this->currentStats['error_count']++;
        
        // Store error for later retrieval
        if (!isset($this->currentStats['errors'])) {
            $this->currentStats['errors'] = [];
        }
        
        $this->currentStats['errors'][] = [
            'type' => $errorType,
            'message' => $message,
            'code' => $errorCode,
            'vehicle_id' => $vehicleId,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Record a warning
     *
     * @param string $message The warning message
     * @return void
     */
    public function recordWarning(string $message): void
    {
        $this->currentStats['warning_count']++;
        
        if (!isset($this->currentStats['warnings'])) {
            $this->currentStats['warnings'] = [];
        }
        
        $this->currentStats['warnings'][] = [
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Calculate derived statistics and finalize run
     *
     * @param string $status The final status (completed, partial, failed, timeout)
     * @param string $errorMessage Optional error message for failed runs
     * @return array The completed statistics array
     */
    public function finalizeStatistics(string $status = 'completed', string $errorMessage = null): array
    {
        // Calculate processing metrics
        $endTime = new DateTime();
        $interval = $this->startTime->diff($endTime);
        
        // Convert interval to seconds
        $processingSeconds = ($interval->days * 86400) +
                            ($interval->h * 3600) +
                            ($interval->i * 60) +
                            $interval->s;
        
        $this->currentStats['processing_time_seconds'] = $processingSeconds;
        $this->currentStats['processing_time_formatted'] = $this->formatDuration($processingSeconds);
        $this->currentStats['status'] = $status;
        $this->currentStats['error_message'] = $errorMessage;
        
        // Calculate skip percentage
        $totalVehicles = $this->currentStats['vehicles_found'] + $this->currentStats['vehicles_skipped'];
        $this->currentStats['skip_percentage'] = $totalVehicles > 0 
            ? round(($this->currentStats['vehicles_skipped'] / $totalVehicles) * 100, 2)
            : 0;
        
        // Calculate error percentage
        $this->currentStats['error_percentage'] = $totalVehicles > 0
            ? round(($this->currentStats['error_count'] / $totalVehicles) * 100, 2)
            : 0;
        
        // Calculate average processing per vehicle
        $processedCount = $this->currentStats['vehicles_found'];
        $this->currentStats['avg_processing_per_vehicle'] = $processedCount > 0
            ? round($processingSeconds / $processedCount, 4)
            : 0;
        
        // Calculate processing rate (vehicles per second)
        $this->currentStats['avg_processing_rate'] = $processingSeconds > 0
            ? round($processedCount / $processingSeconds, 4)
            : 0;
        
        return $this->currentStats;
    }

    /**
     * Save statistics to database
     *
     * @return int The ID of the saved statistics record
     */
    public function saveStatistics(): int
    {
        if (empty($this->currentStats)) {
            throw new Exception("No statistics to save. Call finalizeStatistics first.");
        }
        
        // Prepare fields for insertion
        $fields = [
            'scrape_date',
            'scrape_time',
            'scrape_datetime',
            'vendor_id',
            'source_name',
            'vehicles_found',
            'vehicles_inserted',
            'vehicles_updated',
            'vehicles_skipped',
            'vehicles_deactivated',
            'skip_percentage',
            'processing_time_seconds',
            'processing_time_formatted',
            'total_images',
            'images_stored',
            'error_count',
            'error_percentage',
            'warning_count',
            'avg_processing_per_vehicle',
            'avg_processing_rate',
            'status',
            'error_message'
        ];
        
        // Build the INSERT query
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $fieldNames = implode(',', $fields);
        $query = "INSERT INTO scraper_statistics ({$fieldNames}) VALUES ({$placeholders})";
        
        // Build values array
        $values = [];
        foreach ($fields as $field) {
            $values[] = $this->currentStats[$field] ?? null;
        }
        
        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($values);
            $statsId = (int)$this->db->lastInsertId();
            
            // Save errors if any
            if (!empty($this->currentStats['errors'])) {
                $this->saveErrors($statsId);
            }
            
            // Detect and save anomalies
            $anomalies = $this->detectAnomalies($statsId);
            if (!empty($anomalies)) {
                $this->updateStatisticsWithAnomalies($statsId, $anomalies);
            }
            
            // Update daily summary
            $this->updateDailySummary();
            
            return $statsId;
        } catch (Exception $e) {
            throw new Exception("Failed to save statistics: " . $e->getMessage());
        }
    }

    /**
     * Save error details to error_log table
     *
     * @param int $statisticsId The statistics record ID
     * @return void
     */
    private function saveErrors(int $statisticsId): void
    {
        $query = "INSERT INTO scraper_error_log 
                  (statistics_id, vendor_id, error_type, error_message, error_code, vehicle_id, severity)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($query);
        
        foreach ($this->currentStats['errors'] as $error) {
            $severity = 'medium';
            if (strpos($error['type'], 'timeout') !== false) {
                $severity = 'high';
            } elseif (strpos($error['type'], 'database') !== false) {
                $severity = 'critical';
            }
            
            $stmt->execute([
                $statisticsId,
                $this->currentStats['vendor_id'],
                $error['type'],
                substr($error['message'], 0, 500),
                $error['code'] ?? null,
                $error['vehicle_id'] ?? null,
                $severity
            ]);
        }
    }

    /**
     * Detect anomalies in current statistics
     *
     * @param int $statisticsId The statistics record ID
     * @return array Array of detected anomalies
     */
    private function detectAnomalies(int $statisticsId): array
    {
        $anomalies = [];
        
        // Get previous statistics for comparison
        $previousStats = $this->getPreviousStatistics();
        
        if (empty($previousStats)) {
            return $anomalies; // No previous data for comparison
        }
        
        // Check skip rate change
        $skipRateChange = abs($this->currentStats['skip_percentage'] - $previousStats['skip_percentage']);
        if ($skipRateChange > 20) {
            $anomalies[] = 'skip_rate_change';
            $this->currentStats['skip_rate_change'] = $skipRateChange;
        }
        
        // Check processing time spike
        $timeDifference = abs($this->currentStats['processing_time_seconds'] - $previousStats['processing_time_seconds']);
        $percentChange = $previousStats['processing_time_seconds'] > 0
            ? ($timeDifference / $previousStats['processing_time_seconds']) * 100
            : 0;
        
        if ($percentChange > 50) {
            $anomalies[] = 'duration_spike';
            $this->currentStats['duration_change'] = $percentChange;
        }
        
        // Check error rate spike
        $errorDifference = $this->currentStats['error_count'] - $previousStats['error_count'];
        if ($errorDifference > 5) {
            $anomalies[] = 'error_spike';
            $this->currentStats['error_change'] = $errorDifference;
        }
        
        // Check for no vehicles processed
        if ($this->currentStats['vehicles_found'] == 0 && $previousStats['vehicles_found'] > 0) {
            $anomalies[] = 'no_data_processed';
        }
        
        // Check for very low processing rate
        if ($this->currentStats['avg_processing_rate'] < 0.1 && $this->currentStats['vehicles_found'] > 0) {
            $anomalies[] = 'slow_processing';
        }
        
        return $anomalies;
    }

    /**
     * Get previous statistics for comparison
     *
     * @return array|null The previous statistics record or null
     */
    private function getPreviousStatistics(): ?array
    {
        $query = "SELECT * FROM scraper_statistics 
                  WHERE vendor_id = ? AND DATE(scrape_date) = DATE(DATE_SUB(NOW(), INTERVAL 1 DAY))
                  ORDER BY scrape_datetime DESC LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$this->currentStats['vendor_id']]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Update statistics record with anomalies
     *
     * @param int $statisticsId The statistics record ID
     * @param array $anomalies Array of anomaly types
     * @return void
     */
    private function updateStatisticsWithAnomalies(int $statisticsId, array $anomalies): void
    {
        $anomalyTypes = implode(',', $anomalies);
        
        $query = "UPDATE scraper_statistics 
                  SET has_anomalies = TRUE, anomaly_types = ?
                  WHERE id = ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$anomalyTypes, $statisticsId]);
    }

    /**
     * Update daily summary table
     *
     * @return void
     */
    private function updateDailySummary(): void
    {
        $date = $this->currentStats['scrape_date'];
        $vendorId = $this->currentStats['vendor_id'];
        
        // Check if daily record exists
        $checkQuery = "SELECT id FROM scraper_statistics_daily WHERE scrape_date = ? AND vendor_id = ?";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([$date, $vendorId]);
        
        if ($checkStmt->rowCount() > 0) {
            // Update existing record
            $updateQuery = "UPDATE scraper_statistics_daily SET
                            total_runs = total_runs + 1,
                            successful_runs = successful_runs + ?,
                            failed_runs = failed_runs + ?,
                            total_vehicles_found = total_vehicles_found + ?,
                            total_vehicles_inserted = total_vehicles_inserted + ?,
                            total_vehicles_updated = total_vehicles_updated + ?,
                            total_vehicles_skipped = total_vehicles_skipped + ?,
                            total_images_stored = total_images_stored + ?
                            WHERE scrape_date = ? AND vendor_id = ?";
            
            $stmt = $this->db->prepare($updateQuery);
            $stmt->execute([
                ($this->currentStats['status'] === 'completed' ? 1 : 0),
                ($this->currentStats['status'] !== 'completed' ? 1 : 0),
                $this->currentStats['vehicles_found'],
                $this->currentStats['vehicles_inserted'],
                $this->currentStats['vehicles_updated'],
                $this->currentStats['vehicles_skipped'],
                $this->currentStats['images_stored'],
                $date,
                $vendorId
            ]);
        } else {
            // Insert new record
            $insertQuery = "INSERT INTO scraper_statistics_daily
                            (scrape_date, vendor_id, total_runs, successful_runs, failed_runs,
                             total_vehicles_found, total_vehicles_inserted, total_vehicles_updated,
                             total_vehicles_skipped, total_images_stored)
                            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($insertQuery);
            $stmt->execute([
                $date,
                $vendorId,
                ($this->currentStats['status'] === 'completed' ? 1 : 0),
                ($this->currentStats['status'] !== 'completed' ? 1 : 0),
                $this->currentStats['vehicles_found'],
                $this->currentStats['vehicles_inserted'],
                $this->currentStats['vehicles_updated'],
                $this->currentStats['vehicles_skipped'],
                $this->currentStats['images_stored']
            ]);
        }
    }

    /**
     * Get statistics for a date range
     *
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * @param int $vendorId Vendor ID
     * @return array Array of statistics records
     */
    public function getStatisticsForDateRange(string $startDate, string $endDate, int $vendorId = 432): array
    {
        $query = "SELECT * FROM scraper_statistics
                  WHERE vendor_id = ? AND DATE(scrape_date) BETWEEN ? AND ?
                  ORDER BY scrape_datetime DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$vendorId, $startDate, $endDate]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get daily statistics
     *
     * @param int $days Number of days to retrieve
     * @param int $vendorId Vendor ID
     * @return array Array of daily statistics
     */
    public function getDailyStatistics(int $days = 30, int $vendorId = 432): array
    {
        $query = "SELECT * FROM scraper_statistics_daily
                  WHERE vendor_id = ? AND scrape_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  ORDER BY scrape_date DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$vendorId, $days]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate a weekly report
     *
     * @param string $weekStartDate Week start date (YYYY-MM-DD, Monday)
     * @param int $vendorId Vendor ID
     * @return array Weekly summary statistics
     */
    public function generateWeeklyReport(string $weekStartDate, int $vendorId = 432): array
    {
        $endDate = date('Y-m-d', strtotime($weekStartDate . ' +6 days'));
        
        $query = "SELECT
                    ? as week_start,
                    ? as week_end,
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                    SUM(vehicles_found) as total_vehicles,
                    SUM(vehicles_inserted) as total_inserted,
                    SUM(vehicles_updated) as total_updated,
                    SUM(vehicles_skipped) as total_skipped,
                    SUM(total_images) as total_images,
                    SUM(images_stored) as total_images_stored,
                    AVG(skip_percentage) as avg_skip_rate,
                    AVG(processing_time_seconds) as avg_duration,
                    SUM(error_count) as total_errors,
                    MAX(has_anomalies) as has_anomalies
                  FROM scraper_statistics
                  WHERE vendor_id = ? AND DATE(scrape_date) BETWEEN ? AND ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$weekStartDate, $endDate, $vendorId, $weekStartDate, $endDate]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Generate a monthly report
     *
     * @param int $year Year
     * @param int $month Month (1-12)
     * @param int $vendorId Vendor ID
     * @return array Monthly summary statistics
     */
    public function generateMonthlyReport(int $year, int $month, int $vendorId = 432): array
    {
        $startDate = date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
        $endDate = date('Y-m-d', mktime(0, 0, 0, $month + 1, 0, $year));
        
        $query = "SELECT
                    ? as month,
                    ? as year,
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                    SUM(vehicles_found) as total_vehicles,
                    SUM(vehicles_inserted) as total_inserted,
                    SUM(vehicles_updated) as total_updated,
                    SUM(vehicles_skipped) as total_skipped,
                    SUM(total_images) as total_images,
                    SUM(images_stored) as total_images_stored,
                    AVG(skip_percentage) as avg_skip_rate,
                    AVG(processing_time_seconds) as avg_duration,
                    SUM(error_count) as total_errors,
                    COUNT(DISTINCT DATE(scrape_date)) as days_with_runs
                  FROM scraper_statistics
                  WHERE vendor_id = ? AND DATE(scrape_date) BETWEEN ? AND ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$month, $year, $vendorId, $startDate, $endDate]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get error trends
     *
     * @param int $days Number of days to analyze
     * @param int $vendorId Vendor ID
     * @return array Array of error trends by type
     */
    public function getErrorTrends(int $days = 7, int $vendorId = 432): array
    {
        $query = "SELECT
                    error_type,
                    COUNT(*) as occurrence_count,
                    GROUP_CONCAT(DISTINCT error_code) as error_codes,
                    MAX(severity) as max_severity,
                    COUNT(DISTINCT DATE(first_seen)) as days_affected,
                    MAX(last_seen) as most_recent
                  FROM scraper_error_log
                  WHERE vendor_id = ? AND first_seen >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  GROUP BY error_type
                  ORDER BY occurrence_count DESC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$vendorId, $days]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get triggered alerts
     *
     * @param string $status Alert status (triggered, acknowledged, resolved)
     * @param int $limit Maximum number of alerts to return
     * @return array Array of alert records
     */
    public function getAlerts(string $status = 'triggered', int $limit = 50): array
    {
        $query = "SELECT * FROM scraper_alerts
                  WHERE status = ?
                  ORDER BY created_at DESC
                  LIMIT ?";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$status, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate a report in specified format
     *
     * @param string $format Report format (text, json, csv, html)
     * @param array $data Statistics data
     * @return string Formatted report
     */
    public function generateReport(string $format, array $data): string
    {
        return match($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'csv' => $this->generateCsvReport($data),
            'html' => $this->generateHtmlReport($data),
            'text' => $this->generateTextReport($data),
            default => throw new Exception("Unsupported report format: {$format}")
        };
    }

    /**
     * Generate text-formatted report
     *
     * @param array $data Statistics data
     * @return string Text report
     */
    private function generateTextReport(array $data): string
    {
        $report = "=== SCRAPER STATISTICS REPORT ===\n\n";
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $report .= "{$key}:\n";
                foreach ($value as $subKey => $subValue) {
                    $report .= "  {$subKey}: {$subValue}\n";
                }
            } else {
                $report .= "{$key}: {$value}\n";
            }
        }
        
        return $report;
    }

    /**
     * Generate CSV-formatted report
     *
     * @param array $data Statistics data
     * @return string CSV report
     */
    private function generateCsvReport(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $output = '';
        
        // If array of records, use first record for headers
        $isMultiple = isset($data[0]) && is_array($data[0]);
        
        if ($isMultiple) {
            $firstRecord = reset($data);
            $output .= implode(',', array_keys($firstRecord)) . "\n";
            
            foreach ($data as $row) {
                $output .= implode(',', array_map(function ($val) {
                    return '"' . str_replace('"', '""', $val) . '"';
                }, $row)) . "\n";
            }
        } else {
            // Single record
            foreach ($data as $key => $value) {
                $output .= "{$key},{$value}\n";
            }
        }
        
        return $output;
    }

    /**
     * Generate HTML-formatted report
     *
     * @param array $data Statistics data
     * @return string HTML report
     */
    private function generateHtmlReport(array $data): string
    {
        $html = "<html><head><title>Scraper Statistics Report</title>";
        $html .= "<style>table { border-collapse: collapse; width: 100%; }";
        $html .= "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }</style>";
        $html .= "</head><body><h1>Scraper Statistics Report</h1>";
        
        if (isset($data[0]) && is_array($data[0])) {
            // Multiple records
            $html .= "<table><tr>";
            foreach (array_keys(reset($data)) as $key) {
                $html .= "<th>" . htmlspecialchars($key) . "</th>";
            }
            $html .= "</tr>";
            
            foreach ($data as $row) {
                $html .= "<tr>";
                foreach ($row as $value) {
                    $html .= "<td>" . htmlspecialchars($value) . "</td>";
                }
                $html .= "</tr>";
            }
            $html .= "</table>";
        } else {
            // Single record
            $html .= "<table>";
            foreach ($data as $key => $value) {
                $html .= "<tr><th>" . htmlspecialchars($key) . "</th>";
                $html .= "<td>" . htmlspecialchars($value) . "</td></tr>";
            }
            $html .= "</table>";
        }
        
        $html .= "</body></html>";
        return $html;
    }

    /**
     * Format duration in seconds to readable format
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration (e.g., "1h 5m 30s")
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        
        $parts = [];
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($secs > 0 || empty($parts)) {
            $parts[] = "{$secs}s";
        }
        
        return implode(' ', $parts);
    }

    /**
     * Get current statistics
     *
     * @return array Current statistics array
     */
    public function getCurrentStatistics(): array
    {
        return $this->currentStats;
    }
}
