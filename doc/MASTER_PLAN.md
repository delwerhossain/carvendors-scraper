# 🔧 CarVendors Scraper - Master Plan for Complete System Fix

## 📊 Executive Summary

**Current Status**: The scraper is partially working but has fundamental issues with data extraction, database schema, and image handling. While VRM extraction works correctly when detail pages are fetched, there are critical issues with database structure, missing tables, and incomplete data extraction.

**Key Findings**:
- ✅ VRM extraction working (WP66UEX, ML62YDR, etc.)
- ✅ Basic vehicle data being saved to database
- ❌ Missing `gyc_vehicle_image` table (images not being saved)
- ❌ Missing `gyc_vehicle_attribute` table (attributes not being saved)
- ❌ Descriptions being cut off (no "View More" found, but incomplete)
- ❌ CarCheck API not integrated
- ❌ Wrong field mapping in some database operations

---

## 🚨 Critical Issues Identified

### 1. Database Schema Issues
- **Missing Tables**: `gyc_vehicle_image` and `gyc_vehicle_attribute` don't exist
- **Field Mapping**: Scraper trying to use non-existent fields like `vehicle_title`
- **Data Loss**: Images and attributes not being saved due to missing tables

### 2. Data Extraction Issues
- **Incomplete Descriptions**: Descriptions truncated, missing full vehicle details
- **Missing Image Storage**: Images extracted but not saved to database
- **No Attribute Storage**: Vehicle specifications not properly stored

### 3. API Integration Issues
- **CarCheck API**: Available but not integrated into scraper workflow
- **Enhanced Data**: Missing opportunity to enrich vehicle data

### 4. Quality Control Issues
- **Error Handling**: Incomplete error handling for missing database components
- **Data Validation**: Missing validation for required fields
- **Rollback Capability**: No proper rollback on failed operations

---

## 🎯 Comprehensive Solution Plan

### Phase 1: Database Schema Fix (Priority: Critical)

#### Step 1.1: Create Missing Database Tables
```sql
-- Create vehicle images table
CREATE TABLE IF NOT EXISTS `gyc_vehicle_image` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `vehicle_reg_no` varchar(255) NOT NULL,
    `image_url` text NOT NULL,
    `image_order` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vehicle_reg_no` (`vehicle_reg_no`),
    KEY `idx_image_order` (`image_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create vehicle attributes table
CREATE TABLE IF NOT EXISTS `gyc_vehicle_attribute` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `vehicle_reg_no` varchar(255) NOT NULL,
    `attribute_name` varchar(100) NOT NULL,
    `attribute_value` varchar(255) NOT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vehicle_reg_no` (`vehicle_reg_no`),
    KEY `idx_attribute_name` (`attribute_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Step 1.2: Verify and Fix Field Mapping
- **Field Names**: Ensure all database operations use correct field names
- **Data Types**: Verify data types match actual data being inserted
- **Constraints**: Add proper constraints and indexes

### Phase 2: Scraper Data Extraction Fix (Priority: High)

#### Step 2.1: Fix Image Storage
```php
// In CarSafariScraper.php - add proper image storage
private function saveVehicleImages($regNo, $images) {
    try {
        // Clear existing images for this vehicle
        $this->pdo->prepare("DELETE FROM gyc_vehicle_image WHERE vehicle_reg_no = ?")->execute([$regNo]);

        foreach ($images as $index => $imageUrl) {
            $stmt = $this->pdo->prepare("
                INSERT INTO gyc_vehicle_image (vehicle_reg_no, image_url, image_order)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$regNo, $imageUrl, $index + 1]);
        }

        return count($images);
    } catch (Exception $e) {
        $this->log("Error saving images for {$regNo}: " . $e->getMessage());
        return 0;
    }
}
```

#### Step 2.2: Fix Attribute Storage
```php
// In CarSafariScraper.php - add proper attribute storage
private function saveVehicleAttributes($regNo, $vehicleData) {
    try {
        // Clear existing attributes
        $this->pdo->prepare("DELETE FROM gyc_vehicle_attribute WHERE vehicle_reg_no = ?")->execute([$regNo]);

        $attributes = [
            'make' => $vehicleData['make'] ?? '',
            'model' => $vehicleData['model'] ?? '',
            'year' => $vehicleData['year'] ?? '',
            'engine_size' => $vehicleData['engine_size'] ?? '',
            'fuel_type' => $vehicleData['fuel_type'] ?? '',
            'transmission' => $vehicleData['transmission'] ?? '',
            'body_style' => $vehicleData['body_style'] ?? '',
            'color' => $vehicleData['color'] ?? '',
            'doors' => $vehicleData['doors'] ?? '',
            'drive_system' => $vehicleData['drive_system'] ?? ''
        ];

        foreach ($attributes as $name => $value) {
            if (!empty($value)) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO gyc_vehicle_attribute (vehicle_reg_no, attribute_name, attribute_value)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$regNo, $name, $value]);
            }
        }

        return count(array_filter($attributes));
    } catch (Exception $e) {
        $this->log("Error saving attributes for {$regNo}: " . $e->getMessage());
        return 0;
    }
}
```

#### Step 2.3: Fix Description Extraction
- **Complete Descriptions**: Ensure full descriptions are extracted from detail pages
- **Finance Text**: Preserve all finance information as requested by user
- **Formatting**: Maintain proper formatting and special characters

### Phase 3: CarCheck API Integration (Priority: Medium)

#### Step 3.1: Implement CarCheck Service
```php
// Create src/CarCheckService.php
class CarCheckService {
    private $baseUrl = 'https://www.carcheck.co.uk';
    private $cache;
    private $requestDelay;

    public function getVehicleData($regNo) {
        try {
            $url = $this->baseUrl . '/vehicles/' . urlencode($regNo);
            // Extract make/model from URL: volkswagen, audi, etc.
            // Enhanced vehicle data extraction
            return $enhancedData;
        } catch (Exception $e) {
            return null;
        }
    }
}
```

#### Step 3.2: Integration with Scraper
```php
// In main scraping workflow
if ($config['carcheck']['enabled'] && !empty($regNo)) {
    $carCheckData = $carCheckService->getVehicleData($regNo);
    if ($carCheckData) {
        // Enhance vehicle data with CarCheck information
        $vehicleData = array_merge($vehicleData, $carCheckData);
    }
}
```

### Phase 4: Quality Control & Error Handling (Priority: High)

#### Step 4.1: Add Comprehensive Error Handling
```php
// Add proper error handling for all database operations
try {
    $this->pdo->beginTransaction();

    // Save vehicle info
    $this->saveVehicleInfo($vehicleData);

    // Save images
    $this->saveVehicleImages($regNo, $images);

    // Save attributes
    $this->saveVehicleAttributes($regNo, $vehicleData);

    // Save CarCheck data if available
    if ($carCheckData) {
        $this->saveCarCheckData($regNo, $carCheckData);
    }

    $this->pdo->commit();

} catch (Exception $e) {
    $this->pdo->rollback();
    $this->log("Failed to save vehicle {$regNo}: " . $e->getMessage());
    throw $e;
}
```

#### Step 4.2: Add Data Validation
```php
private function validateVehicleData($data) {
    $required = ['reg_no', 'attention_grabber', 'selling_price'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }

    // Validate VRM format
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z]{3}$/', $data['reg_no'])) {
        $this->log("Warning: Invalid VRM format: {$data['reg_no']}");
    }

    return true;
}
```

### Phase 5: Testing & Validation (Priority: High)

#### Step 5.1: Create Test Script
```php
// Create test_scraper_fixes.php
require_once 'config.php';

// Test 1: Database schema
echo "Testing database schema...\n";
$tests = new ScraperTests();
$tests->testDatabaseSchema();

// Test 2: Image storage
echo "Testing image storage...\n";
$tests->testImageStorage();

// Test 3: Attribute storage
echo "Testing attribute storage...\n";
$tests->testAttributeStorage();

// Test 4: CarCheck integration
echo "Testing CarCheck integration...\n";
$tests->testCarCheckIntegration();
```

#### Step 5.2: Performance Optimization
- **Batch Operations**: Use prepared statements and batch inserts
- **Memory Management**: Optimize memory usage for large datasets
- **Error Recovery**: Implement retry logic for transient failures

---

## 📋 Implementation Checklist

### Database Schema
- [ ] Create `gyc_vehicle_image` table
- [ ] Create `gyc_vehicle_attribute` table
- [ ] Verify all field mappings are correct
- [ ] Add proper indexes and constraints
- [ ] Test database operations

### Scraper Fixes
- [ ] Fix image storage in `CarSafariScraper.php`
- [ ] Fix attribute storage functionality
- [ ] Ensure complete description extraction
- [ ] Add comprehensive error handling
- [ ] Implement data validation

### API Integration
- [ ] Create `CarCheckService.php`
- [ ] Integrate CarCheck API in main scraper
- [ ] Add caching for API responses
- [ ] Test API integration with real VRMs

### Quality Control
- [ ] Add transaction support for data integrity
- [ ] Implement comprehensive logging
- [ ] Create test suite
- [ ] Add performance monitoring
- [ ] Implement rollback functionality

### Testing & Deployment
- [ ] Test with small dataset first
- [ ] Validate data integrity
- [ ] Performance testing
- [ ] Full production run
- [ ] Monitor results

---

## 🚀 Expected Outcomes

After implementing this master plan:

### Data Quality Improvements
- ✅ Complete vehicle descriptions with finance information preserved
- ✅ All vehicle images properly stored (30-50+ per vehicle)
- ✅ Detailed vehicle attributes (make, model, engine, etc.)
- ✅ Enhanced data from CarCheck API integration

### System Reliability
- ✅ Zero data loss through proper transactions
- ✅ Comprehensive error handling and recovery
- ✅ Data validation ensuring data integrity
- ✅ Performance monitoring and optimization

### Enhanced Functionality
- ✅ VRM-based CarCheck integration
- ✅ Complete vehicle specifications
- ✅ Professional image management
- ✅ Production-ready error handling

---

## ⚠️ Risk Mitigation

### Database Risks
- **Backup**: Always backup database before schema changes
- **Testing**: Test schema changes on development environment first
- **Rollback**: Keep rollback scripts ready

### Scraper Risks
- **Rate Limiting**: Implement proper delays between requests
- **Error Recovery**: Handle network failures gracefully
- **Data Validation**: Validate all data before database insertion

### Performance Risks
- **Memory**: Monitor memory usage during scraping
- **Timeout**: Handle timeouts appropriately
- **Batching**: Use batch operations for efficiency

---

## 📈 Success Metrics

### Data Completeness
- Vehicle descriptions: 100% complete with finance info
- Images per vehicle: Average 30-50+ images
- Attributes per vehicle: Complete specification set
- CarCheck integration: 90%+ success rate

### System Performance
- Scraping speed: Maintain 1-2 seconds per vehicle
- Database operations: <100ms per vehicle
- Error rate: <1% for all operations
- Memory usage: <512MB for full scrape

### Quality Assurance
- Data validation: 100% pass rate
- VRM format: 100% valid UK registration numbers
- Image URLs: 100% valid and accessible
- Zero duplicate entries

---

**🎯 Key Goal**: Transform the current partially-working scraper into a production-ready system that extracts complete vehicle data, stores all images and attributes, and provides enhanced data through API integration.

**Timeline Estimate**: 2-3 days for full implementation and testing
**Priority**: High - Critical database issues need immediate attention

**Last Updated**: December 13, 2025
**Status**: Ready for implementation