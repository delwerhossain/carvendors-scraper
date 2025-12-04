/**
 * Phase 6: CarCheck Integration Enhancement - Database Schema
 * 
 * Creates 3 new tables for:
 * - carcheck_cache: Intelligent caching with TTL and hit tracking
 * - carcheck_statistics: Daily metrics for monitoring
 * - carcheck_errors: Error logging and pattern analysis
 * 
 * Run: mysql -u user -p carsafari < 03_CARCHECK_CACHE_TABLES.sql
 */

-- =====================================================
-- 1. CarCheck Cache Table
-- =====================================================
-- Purpose: Store cached API responses with intelligent expiration
-- Columns:
--   - id: Primary key
--   - registration: Vehicle registration (UNIQUE index for lookups)
--   - data: Full JSON response (allows extension)
--   - cached_at: When was this cached?
--   - expires_at: When does this expire? (Index for cleanup queries)
--   - hit_count: How many times was this cache hit? (Performance metric)
--   - last_hit: When was this last used? (Cache aging info)

CREATE TABLE IF NOT EXISTS `carcheck_cache` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `registration` VARCHAR(20) NOT NULL UNIQUE,
  `data` LONGTEXT NOT NULL COMMENT 'Cached JSON response from API',
  `cached_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL COMMENT 'Cache expiration time',
  `hit_count` INT DEFAULT 0 COMMENT 'Number of cache hits',
  `last_hit` TIMESTAMP NULL COMMENT 'Last time this cache was accessed',
  
  -- Indexes for performance
  KEY `idx_expires_at` (`expires_at`) COMMENT 'For cleanup queries',
  KEY `idx_registration` (`registration`) COMMENT 'For lookups by registration',
  KEY `idx_cached_at` (`cached_at`) COMMENT 'For time-based queries',
  
  -- Configuration
  ENGINE=InnoDB,
  DEFAULT CHARSET=utf8mb4,
  COLLATE=utf8mb4_unicode_ci,
  COMMENT='Intelligent cache for CarCheck API responses'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. CarCheck Statistics Table
-- =====================================================
-- Purpose: Track daily metrics for monitoring and optimization
-- Columns:
--   - id: Primary key
--   - stat_date: Date of statistics (UNIQUE for aggregation)
--   - total_requests: Total API requests made
--   - successful: Successfully completed requests
--   - failed: Failed requests (for error rate calculation)
--   - cached_hits: Requests served from cache
--   - avg_response_time: Average response time in seconds
--   - cache_hit_rate: Percentage of cached requests

CREATE TABLE IF NOT EXISTS `carcheck_statistics` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `stat_date` DATE NOT NULL UNIQUE COMMENT 'Date of statistics',
  `total_requests` INT DEFAULT 0 COMMENT 'Total API requests',
  `successful` INT DEFAULT 0 COMMENT 'Successfully completed',
  `failed` INT DEFAULT 0 COMMENT 'Failed requests',
  `cached_hits` INT DEFAULT 0 COMMENT 'Cache hits',
  `avg_response_time` DECIMAL(8,4) DEFAULT 0 COMMENT 'Average response time (seconds)',
  `cache_hit_rate` DECIMAL(5,2) DEFAULT 0 COMMENT 'Cache hit percentage (0-100)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Indexes
  KEY `idx_stat_date` (`stat_date`) COMMENT 'For range queries',
  
  -- Configuration
  ENGINE=InnoDB,
  DEFAULT CHARSET=utf8mb4,
  COLLATE=utf8mb4_unicode_ci,
  COMMENT='Daily statistics for CarCheck integration'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. CarCheck Error Log Table
-- =====================================================
-- Purpose: Track errors for pattern analysis and debugging
-- Columns:
--   - id: Primary key
--   - registration: Vehicle registration (if applicable)
--   - error_type: Category of error (fetch_failed, parse_error, etc.)
--   - message: Error message (truncated to 500 chars)
--   - retry_count: How many retries were attempted
--   - resolved: Has this error been resolved?
--   - created_at: When did this error occur?

CREATE TABLE IF NOT EXISTS `carcheck_errors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `registration` VARCHAR(20) COMMENT 'Vehicle registration (if applicable)',
  `error_type` VARCHAR(50) NOT NULL COMMENT 'Type of error (fetch_failed, parse_error, timeout, etc.)',
  `message` VARCHAR(500) COMMENT 'Error message (first 500 chars)',
  `retry_count` INT DEFAULT 0 COMMENT 'Number of retry attempts',
  `resolved` BOOLEAN DEFAULT FALSE COMMENT 'Has this error been resolved?',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` TIMESTAMP NULL COMMENT 'When was this error resolved?',
  
  -- Indexes for analysis
  KEY `idx_registration` (`registration`) COMMENT 'For vehicle-specific errors',
  KEY `idx_error_type` (`error_type`) COMMENT 'For error pattern analysis',
  KEY `idx_created_at` (`created_at`) COMMENT 'For time-based queries',
  KEY `idx_resolved` (`resolved`) COMMENT 'For filtering unresolved errors',
  
  -- Configuration
  ENGINE=InnoDB,
  DEFAULT CHARSET=utf8mb4,
  COLLATE=utf8mb4_unicode_ci,
  COMMENT='Error log for CarCheck integration debugging'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Maintenance Procedures
-- =====================================================

-- Cleanup expired cache entries (run daily)
-- DELETE FROM carcheck_cache WHERE expires_at < NOW();

-- Archive old errors (keep last 90 days, run monthly)
-- DELETE FROM carcheck_errors WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND resolved = TRUE;

-- View cache statistics
-- SELECT 
--   COUNT(*) as total_cached,
--   SUM(hit_count) as total_hits,
--   AVG(hit_count) as avg_hits_per_entry,
--   ROUND(AVG(LENGTH(data)), 2) as avg_cache_size_bytes
-- FROM carcheck_cache;

-- View error summary by type
-- SELECT 
--   error_type,
--   COUNT(*) as error_count,
--   SUM(CASE WHEN resolved = TRUE THEN 1 ELSE 0 END) as resolved,
--   MAX(created_at) as latest_error
-- FROM carcheck_errors
-- GROUP BY error_type
-- ORDER BY error_count DESC;

-- View cache hit trends
-- SELECT 
--   stat_date,
--   total_requests,
--   cached_hits,
--   cache_hit_rate,
--   ROUND(avg_response_time, 4) as avg_time
-- FROM carcheck_statistics
-- WHERE stat_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
-- ORDER BY stat_date DESC;
