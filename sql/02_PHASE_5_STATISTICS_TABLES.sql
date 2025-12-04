-- ============================================================================
-- Phase 5: Enhanced Statistics Database Tables
-- ============================================================================
-- Creates tables for persistent statistics tracking, historical analysis,
-- and automated reporting capabilities.
-- ============================================================================

-- Create Statistics Table
-- Stores detailed statistics from each scrape run for historical analysis
CREATE TABLE IF NOT EXISTS scraper_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Timestamp Information
    scrape_date DATE NOT NULL,
    scrape_time TIME NOT NULL,
    scrape_datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Vendor/Source Information
    vendor_id INT NOT NULL DEFAULT 432,
    source_name VARCHAR(100),
    
    -- Processing Statistics
    vehicles_found INT NOT NULL DEFAULT 0,
    vehicles_inserted INT NOT NULL DEFAULT 0,
    vehicles_updated INT NOT NULL DEFAULT 0,
    vehicles_skipped INT NOT NULL DEFAULT 0,
    vehicles_deactivated INT NOT NULL DEFAULT 0,
    
    -- Processing Metrics
    skip_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    processing_time_seconds INT NOT NULL DEFAULT 0,
    processing_time_formatted VARCHAR(20),
    
    -- Image Statistics
    total_images INT NOT NULL DEFAULT 0,
    images_stored INT NOT NULL DEFAULT 0,
    images_skipped INT NOT NULL DEFAULT 0,
    
    -- Data Quality Metrics
    error_count INT NOT NULL DEFAULT 0,
    error_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    warning_count INT NOT NULL DEFAULT 0,
    
    -- Performance Metrics
    avg_processing_per_vehicle DECIMAL(8,4) NOT NULL DEFAULT 0,
    avg_processing_rate DECIMAL(8,4) NOT NULL DEFAULT 0, -- vehicles/sec
    peak_memory_usage VARCHAR(20),
    
    -- Status and Results
    status VARCHAR(20) NOT NULL, -- completed, partial, failed, timeout
    error_message TEXT NULL,
    warning_message TEXT NULL,
    
    -- Comparison to Previous Run
    skip_rate_change DECIMAL(5,2) NULL,
    duration_change DECIMAL(5,2) NULL,
    error_change INT NULL,
    
    -- Anomaly Detection
    has_anomalies BOOLEAN DEFAULT FALSE,
    anomaly_types VARCHAR(255) NULL, -- comma-separated: skip_rate_change, duration_spike, error_spike
    
    -- Data Integrity
    data_hash_recorded BOOLEAN DEFAULT FALSE,
    hash_consistency DECIMAL(5,2) NULL,
    
    -- Indexes for Performance
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_vendor_date (vendor_id, scrape_date),
    KEY idx_status (status),
    KEY idx_scrape_date (scrape_date),
    KEY idx_scrape_datetime (scrape_datetime),
    KEY idx_has_anomalies (has_anomalies),
    UNIQUE KEY uq_vendor_datetime (vendor_id, scrape_datetime)
);

-- Create Statistics Summary Table
-- Stores daily summaries for quick access and reporting
CREATE TABLE IF NOT EXISTS scraper_statistics_daily (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Date Information
    scrape_date DATE NOT NULL UNIQUE,
    vendor_id INT NOT NULL DEFAULT 432,
    
    -- Daily Aggregates
    total_runs INT NOT NULL DEFAULT 0,
    successful_runs INT NOT NULL DEFAULT 0,
    failed_runs INT NOT NULL DEFAULT 0,
    
    -- Combined Statistics
    total_vehicles_found INT NOT NULL DEFAULT 0,
    total_vehicles_processed INT NOT NULL DEFAULT 0,
    total_vehicles_inserted INT NOT NULL DEFAULT 0,
    total_vehicles_updated INT NOT NULL DEFAULT 0,
    total_vehicles_skipped INT NOT NULL DEFAULT 0,
    total_vehicles_deactivated INT NOT NULL DEFAULT 0,
    
    -- Daily Averages
    avg_skip_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    avg_processing_time INT NOT NULL DEFAULT 0,
    avg_error_count INT NOT NULL DEFAULT 0,
    
    -- Daily Performance
    best_duration INT,
    worst_duration INT,
    best_skip_rate DECIMAL(5,2),
    worst_skip_rate DECIMAL(5,2),
    
    -- Daily Images
    total_images_stored INT NOT NULL DEFAULT 0,
    
    -- Anomalies
    daily_anomaly_count INT NOT NULL DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_scrape_date (scrape_date),
    KEY idx_vendor_date (vendor_id, scrape_date)
);

-- Create Statistics Trends Table
-- Stores rolling trends for pattern detection
CREATE TABLE IF NOT EXISTS scraper_statistics_trends (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Time Period
    period_date DATE NOT NULL,
    period_type ENUM('daily', 'weekly', 'monthly') NOT NULL,
    vendor_id INT NOT NULL DEFAULT 432,
    
    -- Trend Metrics (averages over period)
    avg_skip_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    avg_processing_time INT NOT NULL DEFAULT 0,
    avg_error_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    avg_vehicles_per_run INT NOT NULL DEFAULT 0,
    
    -- Trend Direction
    skip_rate_trend VARCHAR(10), -- increasing, decreasing, stable
    processing_time_trend VARCHAR(10),
    error_rate_trend VARCHAR(10),
    
    -- Period Statistics
    runs_in_period INT NOT NULL DEFAULT 0,
    total_vehicles_processed INT NOT NULL DEFAULT 0,
    
    -- Anomalies in Period
    anomalies_detected INT NOT NULL DEFAULT 0,
    worst_anomaly_type VARCHAR(50),
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_period_date (period_date),
    KEY idx_period_type (period_type),
    KEY idx_vendor_period (vendor_id, period_date, period_type),
    UNIQUE KEY uq_period (vendor_id, period_date, period_type)
);

-- Create Error Log Table
-- Detailed error tracking for debugging
CREATE TABLE IF NOT EXISTS scraper_error_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Reference
    statistics_id INT,
    vendor_id INT NOT NULL DEFAULT 432,
    
    -- Error Details
    error_type VARCHAR(100) NOT NULL, -- network, parse, database, timeout, etc.
    error_message TEXT NOT NULL,
    error_code VARCHAR(50),
    
    -- Context
    vehicle_id INT NULL,
    vehicle_reg_no VARCHAR(50) NULL,
    affected_vehicle_count INT DEFAULT 1,
    
    -- Severity
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    is_recoverable BOOLEAN DEFAULT TRUE,
    
    -- Tracking
    first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    occurrence_count INT DEFAULT 1,
    
    -- Resolution
    resolved BOOLEAN DEFAULT FALSE,
    resolution_notes TEXT NULL,
    resolved_at DATETIME NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_error_type (error_type),
    KEY idx_severity (severity),
    KEY idx_resolved (resolved),
    KEY idx_vendor_date (vendor_id, first_seen),
    KEY idx_stats_id (statistics_id),
    FOREIGN KEY (statistics_id) REFERENCES scraper_statistics(id) ON DELETE SET NULL
);

-- Create Alerts Table
-- Tracks triggered alerts for monitoring and notifications
CREATE TABLE IF NOT EXISTS scraper_alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Reference
    statistics_id INT,
    vendor_id INT NOT NULL DEFAULT 432,
    
    -- Alert Details
    alert_type VARCHAR(100) NOT NULL, -- skip_rate_drop, duration_spike, error_rate_high, etc.
    alert_name VARCHAR(255) NOT NULL,
    alert_description TEXT,
    
    -- Thresholds
    threshold_value DECIMAL(10,4),
    actual_value DECIMAL(10,4),
    threshold_exceeded_by DECIMAL(10,4),
    
    -- Status
    status ENUM('triggered', 'acknowledged', 'resolved') NOT NULL DEFAULT 'triggered',
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    
    -- Notification
    notification_sent BOOLEAN DEFAULT FALSE,
    notification_sent_at DATETIME NULL,
    notification_recipients VARCHAR(255),
    
    -- Resolution
    acknowledged_at DATETIME NULL,
    acknowledged_by VARCHAR(100),
    resolved_at DATETIME NULL,
    resolution_notes TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_alert_type (alert_type),
    KEY idx_status (status),
    KEY idx_severity (severity),
    KEY idx_vendor_date (vendor_id, created_at),
    KEY idx_stats_id (statistics_id),
    FOREIGN KEY (statistics_id) REFERENCES scraper_statistics(id) ON DELETE SET NULL
);

-- Create Configuration Table
-- Stores alert thresholds and notification settings
CREATE TABLE IF NOT EXISTS scraper_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- Configuration Key
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_category VARCHAR(50), -- alerts, thresholds, notifications, retention
    
    -- Values
    config_value VARCHAR(1000),
    config_description TEXT,
    
    -- Type and Defaults
    config_type ENUM('string', 'integer', 'decimal', 'boolean', 'json') DEFAULT 'string',
    default_value VARCHAR(1000),
    
    -- Metadata
    is_active BOOLEAN DEFAULT TRUE,
    can_override BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY idx_category (config_category),
    KEY idx_is_active (is_active)
);

-- Insert default configuration values
INSERT INTO scraper_config (config_key, config_category, config_value, config_description, config_type) VALUES
('alert_skip_rate_min', 'thresholds', '50', 'Minimum skip rate percentage', 'integer'),
('alert_skip_rate_drop', 'thresholds', '20', 'Alert if skip rate drops by this percentage', 'integer'),
('alert_error_rate_max', 'thresholds', '5', 'Maximum error rate percentage before alert', 'integer'),
('alert_processing_time_max', 'thresholds', '600', 'Maximum processing time in seconds before alert', 'integer'),
('alert_consecutive_failures', 'thresholds', '3', 'Number of consecutive failures to trigger alert', 'integer'),
('retention_days_statistics', 'retention', '365', 'Days to retain detailed statistics', 'integer'),
('retention_days_logs', 'retention', '30', 'Days to retain log files', 'integer'),
('retention_days_errors', 'retention', '90', 'Days to retain error logs', 'integer'),
('notification_email_enabled', 'notifications', 'false', 'Enable email notifications', 'boolean'),
('notification_email_recipients', 'notifications', 'admin@example.com', 'Email recipients for alerts (comma-separated)', 'string'),
('notification_on_error', 'notifications', 'true', 'Send notification on errors', 'boolean'),
('notification_on_anomaly', 'notifications', 'true', 'Send notification on anomalies', 'boolean'),
('report_daily_enabled', 'reporting', 'true', 'Generate daily reports', 'boolean'),
('report_weekly_enabled', 'reporting', 'true', 'Generate weekly reports', 'boolean'),
('report_monthly_enabled', 'reporting', 'true', 'Generate monthly reports', 'boolean');

-- ============================================================================
-- Summary of New Tables
-- ============================================================================
-- 1. scraper_statistics - Main statistics table for each run
-- 2. scraper_statistics_daily - Daily summary aggregates
-- 3. scraper_statistics_trends - Weekly/monthly trend analysis
-- 4. scraper_error_log - Detailed error tracking
-- 5. scraper_alerts - Alert triggers and tracking
-- 6. scraper_config - Configurable thresholds and settings
-- ============================================================================
