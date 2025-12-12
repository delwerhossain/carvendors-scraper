<?php
/**
 * StatisticsManager - Handles all statistics and reporting for the scraper
 *
 * This class manages scrape statistics, error tracking, and reporting.
 */

namespace CarVendors\Scrapers;

use PDO;
use PDOException;
use DateTime;

class StatisticsManager
{
    private PDO $db;
    private array $config;
    private array $currentStats = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = $this->initializeDatabaseConnection();
        $this->currentStats = [
            'vehicles_found' => 0,
            'vehicles_inserted' => 0,
            'vehicles_updated' => 0,
            'vehicles_skipped' => 0,
            'vehicles_failed' => 0,
            'images_stored' => 0,
            'requests_made' => 0,
            'errors' => [],
            'warnings' => [],
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => null
        ];
    }

    /**
     * Initialize database connection
     */
    private function initializeDatabaseConnection(): PDO
    {
        try {
            $pdo = new PDO(
                "mysql:host=" . $this->config['database']['host'] .
                ";dbname=" . $this->config['database']['dbname'] .
                ";charset=utf8mb4",
                $this->config['database']['username'],
                $this->config['database']['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Record a vehicle action (inserted, updated, skipped, failed)
     */
    public function recordVehicleAction(string $action, array $vehicleData = []): void
    {
        $this->currentStats['vehicles_' . $action]++;

        // Log specific vehicle data if provided
        if (!empty($vehicleData)) {
            $this->logVehicleDetail($action, $vehicleData);
        }
    }

    /**
     * Record an error
     */
    public function recordError(string $type, string $message, string $severity = 'ERROR'): void
    {
        $error = [
            'type' => $type,
            'message' => $message,
            'severity' => $severity,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $this->currentStats['errors'][] = $error;

        // Also log to database
        $this->logErrorToDatabase($error);
    }

    /**
     * Record an image action
     */
    public function recordImageAction(string $action, int $imageCount = 1): void
    {
        if ($action === 'stored') {
            $this->currentStats['images_stored'] += $imageCount;
        }
    }

    /**
     * Finalize statistics for a scrape run
     */
    public function finalizeStatistics(string $status, ?string $errorMessage = null): array
    {
        $this->currentStats['end_time'] = date('Y-m-d H:i:s');
        $this->currentStats['status'] = $status;

        if ($errorMessage) {
            $this->recordError('runtime', $errorMessage, $status);
        }

        // Calculate duration
        if ($this->currentStats['start_time'] && $this->currentStats['end_time']) {
            $start = new DateTime($this->currentStats['start_time']);
            $end = new DateTime($this->currentStats['end_time']);
            $interval = $start->diff($end);
            $this->currentStats['duration'] = $interval->format('%H:%I:%S');
        }

        return $this->currentStats;
    }

    /**
     * Save statistics to database
     */
    public function saveStatistics(): ?int
    {
        try {
            $sql = "INSERT INTO scraper_statistics (
                vendor_id, run_date, status, vehicles_found,
                vehicles_inserted, vehicles_updated, vehicles_skipped, vehicles_failed,
                images_stored, requests_made, duration_minutes, stats_json, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )";

            $stmt = $this->db->prepare($sql);

            $statsJson = json_encode($this->currentStats);

            return $stmt->execute([
                432, // vendor_id
                date('Y-m-d'),
                $this->currentStats['status'] ?? 'running',
                $this->currentStats['vehicles_found'],
                $this->currentStats['vehicles_inserted'],
                $this->currentStats['vehicles_updated'],
                $this->currentStats['vehicles_skipped'],
                $this->currentStats['vehicles_failed'],
                $this->currentStats['images_stored'],
                $this->currentStats['requests_made'],
                $this->calculateDurationMinutes(),
                $statsJson
            ]) ? (int)$this->db->lastInsertId() : null;

        } catch (Exception $e) {
            // Don't throw exception - log it but continue
            $this->recordError('database', 'Failed to save statistics: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get statistics for a date range
     */
    public function getStatisticsForDateRange(string $startDate, string $endDate, int $vendorId = 432): array
    {
        try {
            $sql = "SELECT * FROM scraper_statistics
                    WHERE run_date BETWEEN ? AND ?
                    AND vendor_id = ?
                    ORDER BY run_date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$startDate, $endDate, $vendorId]);

            return $stmt->fetchAll();
        } catch (Exception $e) {
            $this->recordError('database', 'Failed to get date range stats: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get daily statistics
     */
    public function getDailyStatistics(int $days = 7, int $vendorId = 432): array
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        return $this->getStatisticsForDateRange($startDate, $endDate, $vendorId);
    }

    /**
     * Generate weekly report
     */
    public function generateWeeklyReport(string $weekStartDate, int $vendorId = 432): array
    {
        $endDate = date('Y-m-d', strtotime($weekStartDate . ' +6 days'));
        $stats = $this->getStatisticsForDateRange($weekStartDate, $endDate, $vendorId);

        return $this->aggregateWeeklyStats($stats);
    }

    /**
     * Generate monthly report
     */
    public function generateMonthlyReport(int $year, int $month, int $vendorId = 432): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $stats = $this->getStatisticsForDateRange($startDate, $endDate, $vendorId);

        return $this->aggregateMonthlyStats($stats);
    }

    /**
     * Get alerts based on statistics
     */
    public function getAlerts(string $status = 'all'): array
    {
        try {
            $sql = "SELECT * FROM scraper_statistics
                    WHERE status IN (?, ?, ?)
                    ORDER BY run_date DESC
                    LIMIT 50";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['failed', 'warning', $status]);

            return $stmt->fetchAll();
        } catch (Exception $e) {
            $this->recordError('database', 'Failed to get alerts: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get error trends
     */
    public function getErrorTrends(int $days = 30, int $vendorId = 432): array
    {
        try {
            $sql = "SELECT DATE(run_date) as date,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_count
                    FROM scraper_statistics
                    WHERE run_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                    AND vendor_id = ?
                    GROUP BY DATE(run_date)
                    ORDER BY date DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$days, $vendorId]);

            return $stmt->fetchAll();
        } catch (Exception $e) {
            $this->recordError('database', 'Failed to get error trends: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Helper: Calculate duration in minutes
     */
    private function calculateDurationMinutes(): float
    {
        if (!$this->currentStats['start_time'] || !$this->currentStats['end_time']) {
            return 0;
        }

        $start = new DateTime($this->currentStats['start_time']);
        $end = new DateTime($this->currentStats['end_time']);
        $interval = $start->diff($end);

        return ($interval->days * 24 * 60) +
               ($interval->h * 60) +
               ($interval->i) +
               ($interval->s / 60);
    }

    /**
     * Helper: Log vehicle details
     */
    private function logVehicleDetail(string $action, array $vehicleData): void
    {
        try {
            $sql = "INSERT INTO vehicle_logs (
                action, vehicle_id, reg_no, title, price, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $action,
                $vehicleData['id'] ?? null,
                $vehicleData['reg_no'] ?? null,
                $vehicleData['title'] ?? null,
                $vehicleData['price'] ?? null
            ]);
        } catch (Exception $e) {
            // Silent failure - don't break the scraper for logging
        }
    }

    /**
     * Helper: Log error to database
     */
    private function logErrorToDatabase(array $error): void
    {
        try {
            $sql = "INSERT INTO error_logs (
                type, message, severity, timestamp, created_at
            ) VALUES (?, ?, ?, ?, NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $error['type'],
                $error['message'],
                $error['severity'],
                $error['timestamp']
            ]);
        } catch (Exception $e) {
            // Silent failure
        }
    }

    /**
     * Helper: Aggregate weekly statistics
     */
    private function aggregateWeeklyStats(array $stats): array
    {
        $totals = [
            'total_vehicles' => 0,
            'total_images' => 0,
            'successful_runs' => 0,
            'failed_runs' => 0,
            'average_duration' => 0,
            'runs_count' => count($stats)
        ];

        $totalDuration = 0;
        foreach ($stats as $stat) {
            $totals['total_vehicles'] += $stat->vehicles_found + $stat->vehicles_inserted + $stat->vehicles_updated;
            $totals['total_images'] += $stat->images_stored;
            if ($stat->status === 'completed') {
                $totals['successful_runs']++;
            } else {
                $totals['failed_runs']++;
            }
            $totalDuration += $stat->duration_minutes ?? 0;
        }

        $totals['average_duration'] = $totals['runs_count'] > 0 ? $totalDuration / $totals['runs_count'] : 0;

        return $totals;
    }

    /**
     * Helper: Aggregate monthly statistics
     */
    private function aggregateMonthlyStats(array $stats): array
    {
        return $this->aggregateWeeklyStats($stats); // Same logic for now
    }

    /**
     * Initialize statistics for a new scrape run
     */
    public function initializeStatistics(int $vendorId = 432): void
    {
        $this->currentStats = [
            'vehicles_found' => 0,
            'vehicles_inserted' => 0,
            'vehicles_updated' => 0,
            'vehicles_skipped' => 0,
            'vehicles_failed' => 0,
            'images_stored' => 0,
            'requests_made' => 0,
            'errors' => [],
            'warnings' => [],
            'vendor_id' => $vendorId,
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => null
        ];
    }

    /**
     * Record image statistics
     */
    public function recordImageStatistics(int $imagesCount, ?int $vehicleId = null): void
    {
        $this->currentStats['images_stored'] += $imagesCount;

        // Log image details if needed
        if ($vehicleId) {
            $this->currentStats['images_per_vehicle'][] = [
                'vehicle_id' => $vehicleId,
                'count' => $imagesCount
            ];
        }
    }

    /**
     * Generate formatted report
     */
    public function generateReport(string $format = 'html', array $data = []): string
    {
        $defaultData = $this->currentStats;
        $data = array_merge($defaultData, $data);

        switch (strtolower($format)) {
            case 'html':
                return $this->generateHtmlReport($data);
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
            case 'text':
                return $this->generateTextReport($data);
            default:
                return $this->generateHtmlReport($data);
        }
    }

    /**
     * Generate HTML report
     */
    private function generateHtmlReport(array $data): string
    {
        $html = '<h1>Scraper Statistics Report</h1>';
        $html .= '<table border="1" cellpadding="5" cellspacing="0">';
        $html .= '<tr><th>Metric</th><th>Value</th></tr>';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = count($value) . ' items';
            }
            $html .= "<tr><td>{$key}</td><td>{$value}</td></tr>";
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Generate text report
     */
    private function generateTextReport(array $data): string
    {
        $text = "Scraper Statistics Report\n";
        $text .= str_repeat('=', 30) . "\n";

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = count($value) . ' items';
            }
            $text .= sprintf("%-20s: %s\n", $key, $value);
        }

        return $text;
    }

    /**
     * Get current statistics (for testing/debugging)
     */
    public function getCurrentStats(): array
    {
        return $this->currentStats;
    }
}
?>