-- Create scrape_logs table for tracking scraper runs
CREATE TABLE IF NOT EXISTS `scrape_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `source` varchar(255) NOT NULL DEFAULT 'systonautosltd',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime NULL,
  `vehicles_found` int(11) DEFAULT 0,
  `vehicles_inserted` int(11) DEFAULT 0,
  `vehicles_updated` int(11) DEFAULT 0,
  `vehicles_deactivated` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'running',
  `error_message` text NULL,
  KEY `idx_started` (`started_at`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
