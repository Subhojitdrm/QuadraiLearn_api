# Wallet API - Phase 5: Token Analytics & Exports

## Overview

Phase 5 introduces comprehensive analytics and data export capabilities for the token wallet system. This phase enables admins to:

- View token activity KPIs and trends
- Analyze token usage by feature and composition
- Track top users by spend/earn metrics
- Export transaction data to CSV/JSON formats
- Access materialized daily analytics for fast queries

**Build on:** Phase 4 (Promotions & Referrals)

---

## Table of Contents

1. [Architecture](#architecture)
2. [Database Schema](#database-schema)
3. [Analytics Library](#analytics-library)
4. [Export Service](#export-service)
5. [API Endpoints](#api-endpoints)
6. [Background Jobs](#background-jobs)
7. [Testing Guide](#testing-guide)
8. [Deployment Checklist](#deployment-checklist)

---

## Architecture

### Analytics Strategy

1. **Materialized Table (`analytics_token_daily`)**
   - Pre-aggregated daily statistics
   - Fast query performance
   - Populated by nightly ETL job
   - Includes JSON columns for feature breakdown and composition

2. **Real-time View (`v_token_analytics_realtime`)**
   - Fallback for current-day data
   - Direct aggregation from `wallet_ledger`
   - Used when materialized table is stale

3. **Export System**
   - Async export generation
   - 24-hour download link expiry
   - Support for multiple datasets (transactions, purchases, referrals, etc.)
   - 100k row limit for safety

### Key Design Decisions

- **Date Range Limits:** Max 365 days to prevent performance issues
- **Granularity Options:** Daily, weekly, monthly aggregations
- **Export Formats:** CSV (spreadsheet-friendly) and JSON (programmatic access)
- **File Storage:** Local filesystem with cleanup job for expired exports
- **ETL Schedule:** Runs nightly at 03:00 IST, processes previous day's data

---

## Database Schema

### Migration: `migrations/005_analytics.sql`

#### Table: `analytics_token_daily`

Materialized daily token statistics for fast analytics queries.

```sql
CREATE TABLE `analytics_token_daily` (
  `date` DATE PRIMARY KEY,
  `credited` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total tokens credited',
  `debited` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total tokens debited',
  `net` BIGINT NOT NULL DEFAULT 0 COMMENT 'Net tokens (credited - debited)',
  `revenue_in_inr` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Revenue from purchases in INR',
  `active_users` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unique active users',
  `by_feature` JSON NOT NULL DEFAULT (JSON_OBJECT()) COMMENT 'Breakdown by feature',
  `regular_vs_promo` JSON NOT NULL DEFAULT (JSON_OBJECT()) COMMENT 'Regular vs promo split',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_date_range` (`date`)
) ENGINE=InnoDB;
```

**JSON Column Examples:**

```json
// by_feature
{
  "chapter_generation": {"credited": 0, "debited": 5000},
  "registration_bonus": {"credited": 1000, "debited": 0},
  "purchase": {"credited": 10000, "debited": 0}
}

// regular_vs_promo
{
  "regular": 75.5,  // percentage
  "promo": 24.5
}
```

#### Table: `analytics_exports`

Tracks data export requests and their status.

```sql
CREATE TABLE `analytics_exports` (
  `id` VARCHAR(26) PRIMARY KEY COMMENT 'ULID primary key',
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Admin who requested export',
  `type` ENUM('csv', 'json') NOT NULL DEFAULT 'csv',
  `dataset` VARCHAR(64) NOT NULL COMMENT 'Dataset to export',
  `filters` JSON NOT NULL DEFAULT (JSON_OBJECT()) COMMENT 'Export filters',
  `status` ENUM('preparing', 'ready', 'failed', 'expired') NOT NULL DEFAULT 'preparing',
  `estimated_rows` INT UNSIGNED NULL,
  `actual_rows` INT UNSIGNED NULL,
  `file_path` VARCHAR(255) NULL,
  `download_url` VARCHAR(512) NULL,
  `expires_at` TIMESTAMP NULL COMMENT 'When download link expires',
  `error_message` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB;
```

#### View: `v_token_analytics_realtime`

Real-time analytics view for current-day data.

```sql
CREATE OR REPLACE VIEW `v_token_analytics_realtime` AS
SELECT
    DATE(occurred_at) as date,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) as credited,
    SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) as debited,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as net,
    COUNT(DISTINCT user_id) as active_users
FROM wallet_ledger
GROUP BY DATE(occurred_at);
```

---

## Analytics Library

### File: `lib/analytics.php`

#### Function: `get_token_overview()`

Returns overview KPIs for token activity.

```php
function get_token_overview(
    PDO $pdo,
    string $from,
    string $to,
    ?string $feature = null,
    ?string $tokenType = null
): array
```

**Returns:**
```json
{
  "credited": 50000,
  "debited": 35000,
  "net": 15000,
  "revenue_in_inr": 1500.00,
  "active_users": 120
}
```

#### Function: `get_token_trend()`

Returns token trend data over time.

```php
function get_token_trend(
    PDO $pdo,
    string $granularity,  // daily, weekly, monthly
    string $from,
    string $to
): array
```

**Returns:**
```json
{
  "series": [
    {
      "date": "2025-11-01",
      "credited": 5000,
      "debited": 3000,
      "net": 2000,
      "active_users": 25,
      "revenue_in_inr": 300.00
    },
    ...
  ]
}
```

**Granularity:**
- `daily`: Group by day (DATE(occurred_at))
- `weekly`: Group by week start (Monday)
- `monthly`: Group by month start (1st of month)

#### Function: `get_tokens_by_feature()`

Returns token usage breakdown by feature.

```php
function get_tokens_by_feature(PDO $pdo, string $from, string $to): array
```

**Returns:**
```json
{
  "items": [
    {
      "feature": "chapter_generation",
      "credited": 0,
      "debited": 15000
    },
    {
      "feature": "purchase",
      "credited": 30000,
      "debited": 0
    }
  ]
}
```

#### Function: `get_token_composition()`

Returns token composition (regular vs promo percentages).

```php
function get_token_composition(PDO $pdo, string $from, string $to): array
```

**Returns:**
```json
{
  "regular": 68.5,
  "promo": 31.5
}
```

#### Function: `get_top_users()`

Returns top users leaderboard.

```php
function get_top_users(
    PDO $pdo,
    string $metric,  // spend, earn, net
    string $from,
    string $to,
    int $limit = 20
): array
```

**Returns:**
```json
{
  "items": [
    {
      "user_id": 42,
      "username": "john_doe",
      "email": "john@example.com",
      "spend": 5000
    }
  ]
}
```

**Metrics:**
- `spend`: Total tokens debited
- `earn`: Total tokens credited
- `net`: Net tokens (credited - debited)

#### Function: `get_purchase_analytics()`

Returns purchase analytics.

```php
function get_purchase_analytics(PDO $pdo, string $from, string $to): array
```

**Returns:**
```json
{
  "total_purchases": 150,
  "total_tokens": 75000,
  "total_revenue": 2250.00,
  "avg_tokens_per_purchase": 500.0,
  "avg_revenue_per_purchase": 15.00,
  "unique_buyers": 85
}
```

#### Function: `get_promotion_analytics()`

Returns promotion/referral analytics.

```php
function get_promotion_analytics(
    PDO $pdo,
    string $from,
    string $to,
    ?string $campaignId = null
): array
```

**Returns:**
```json
{
  "total_referrers": 45,
  "total_referrals": 120,
  "successful_referrals": 98,
  "total_bonus_awarded": 9800,
  "conversion_rate": 81.67
}
```

---

## Export Service

### File: `lib/exports.php`

#### Supported Datasets

1. **transactions** - Wallet ledger entries with user info
2. **purchases** - Token purchase records
3. **referrals** - Referral activity with campaign details
4. **authorizations** - Token authorization (hold-capture) records
5. **users** - User list with wallet balances

#### Function: `create_export()`

Creates a new export request.

```php
function create_export(
    PDO $pdo,
    int $userId,
    string $type,      // csv, json
    string $dataset,   // transactions, purchases, etc.
    array $filters = []
): array
```

**Returns:**
```json
{
  "export_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
  "status": "preparing",
  "type": "csv",
  "dataset": "transactions",
  "estimated_rows": 1500,
  "expires_at": "2025-11-09 15:30:00"
}
```

#### Function: `generate_export_file()`

Generates the export file (call from background job).

```php
function generate_export_file(PDO $pdo, string $exportId): bool
```

**Process:**
1. Fetch export record
2. Query data with applied filters
3. Generate CSV or JSON file
4. Update export status to `ready`
5. Set download URL and expiry (24 hours)

#### Dataset Filters

**transactions:**
- `from`, `to`: Date range (YYYY-MM-DD)
- `user_id`: Specific user
- `token_type`: regular/promo
- `direction`: credit/debit
- `reason`: Feature/reason code

**purchases:**
- `from`, `to`: Date range
- `user_id`: Specific user
- `status`: created/pending/paid/failed/refunded
- `provider`: razorpay/stripe

**referrals:**
- `from`, `to`: Date range
- `campaign_id`: Specific campaign
- `status`: pending/credited
- `referrer_user_id`: Specific referrer

**authorizations:**
- `from`, `to`: Date range
- `user_id`: Specific user
- `feature`: chapter_generation, etc.
- `status`: created/held/captured/voided/expired

**users:**
- `role`: user/admin/service
- `from`, `to`: Registration date range

#### Export Limits

- **Max rows per export:** 100,000
- **Download link expiry:** 24 hours
- **Max date range:** 365 days
- **File storage:** `exports/` directory

---

## API Endpoints

### Analytics Endpoints

#### 1. Get Overview KPIs

```
GET /api/v1/admin/analytics/tokens/overview
```

**Query Parameters:**
- `from` (required): Start date (YYYY-MM-DD)
- `to` (required): End date (YYYY-MM-DD)
- `feature` (optional): Filter by feature
- `token_type` (optional): regular/promo

**Response (200 OK):**
```json
{
  "credited": 50000,
  "debited": 35000,
  "net": 15000,
  "revenue_in_inr": 1500.00,
  "active_users": 120
}
```

#### 2. Get Token Trend

```
GET /api/v1/admin/analytics/tokens/trend
```

**Query Parameters:**
- `granularity` (required): daily/weekly/monthly
- `from` (required): Start date
- `to` (required): End date

**Response (200 OK):**
```json
{
  "series": [
    {
      "date": "2025-11-01",
      "credited": 5000,
      "debited": 3000,
      "net": 2000,
      "active_users": 25,
      "revenue_in_inr": 300.00
    }
  ]
}
```

#### 3. Get Feature Breakdown

```
GET /api/v1/admin/analytics/tokens/by-feature
```

**Query Parameters:**
- `from` (required): Start date
- `to` (required): End date

**Response (200 OK):**
```json
{
  "items": [
    {
      "feature": "chapter_generation",
      "credited": 0,
      "debited": 15000
    }
  ]
}
```

#### 4. Get Token Composition

```
GET /api/v1/admin/analytics/tokens/composition
```

**Query Parameters:**
- `from` (required): Start date
- `to` (required): End date

**Response (200 OK):**
```json
{
  "regular": 68.5,
  "promo": 31.5
}
```

#### 5. Get Top Users

```
GET /api/v1/admin/analytics/users/top
```

**Query Parameters:**
- `metric` (required): spend/earn/net
- `from` (required): Start date
- `to` (required): End date
- `limit` (optional): 1-100, default 20

**Response (200 OK):**
```json
{
  "items": [
    {
      "user_id": 42,
      "username": "john_doe",
      "email": "john@example.com",
      "spend": 5000
    }
  ]
}
```

### Export Endpoints

#### 6. Create Export

```
POST /api/v1/admin/analytics/exports
```

**Request Body:**
```json
{
  "type": "csv",
  "dataset": "transactions",
  "filters": {
    "from": "2025-11-01",
    "to": "2025-11-08",
    "token_type": "regular"
  }
}
```

**Response (201 Created):**
```json
{
  "export_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
  "status": "ready",
  "type": "csv",
  "dataset": "transactions",
  "estimated_rows": 1500,
  "actual_rows": 1498,
  "download_url": "/exports/transactions_20251108153045.csv",
  "expires_at": "2025-11-09 15:30:45",
  "created_at": "2025-11-08 15:30:42",
  "completed_at": "2025-11-08 15:30:45"
}
```

#### 7. Get Export Status

```
GET /api/v1/admin/analytics/exports/{export_id}
```

**Response (200 OK):**

**When ready:**
```json
{
  "export_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
  "status": "ready",
  "type": "csv",
  "dataset": "transactions",
  "filters": {"from": "2025-11-01", "to": "2025-11-08"},
  "estimated_rows": 1500,
  "actual_rows": 1498,
  "download_url": "/exports/transactions_20251108153045.csv",
  "expires_at": "2025-11-09 15:30:45",
  "created_at": "2025-11-08 15:30:42",
  "completed_at": "2025-11-08 15:30:45"
}
```

**When failed:**
```json
{
  "export_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
  "status": "failed",
  "type": "csv",
  "dataset": "transactions",
  "filters": {},
  "error_message": "Database connection timeout",
  "created_at": "2025-11-08 15:30:42"
}
```

### Common Error Responses

**Validation Error (400):**
```json
{
  "error": {
    "code": "validation_error",
    "message": "from and to date parameters are required"
  }
}
```

**Business Rule Error (422):**
```json
{
  "error": {
    "code": "business_rule_violation",
    "message": "Date range cannot exceed 365 days"
  }
}
```

**Not Found (404):**
```json
{
  "error": {
    "code": "not_found",
    "message": "Export not found"
  }
}
```

---

## Background Jobs

### 1. Analytics ETL Job

**File:** `cron/analytics_etl.php`

**Purpose:** Populate `analytics_token_daily` materialized table

**Schedule:** Nightly at 03:00 IST
```cron
0 3 * * * php /path/to/cron/analytics_etl.php >> /path/to/logs/analytics_etl.log 2>&1
```

**Process:**
1. Determine date range to process
   - Get last processed date from `analytics_token_daily`
   - Process from last processed + 1 day to yesterday
   - Skip today (incomplete data)
2. For each date:
   - Aggregate credited/debited/net/active_users from `wallet_ledger`
   - Calculate revenue from `purchases`
   - Build feature breakdown JSON
   - Calculate regular vs promo composition
   - Insert/update `analytics_token_daily` row
3. Clean up expired exports
4. Log results

**Output Example:**
```
[2025-11-08 03:00:01] === Analytics ETL Job Started ===
[2025-11-08 03:00:01] Processing date range: 2025-11-07 to 2025-11-07
[2025-11-08 03:00:01] Processing date: 2025-11-07
[2025-11-08 03:00:02]   - Processed 2025-11-07: credited=15000, debited=8500, active_users=45
[2025-11-08 03:00:02] Total dates processed: 1
[2025-11-08 03:00:02] Cleaning up expired exports...
[2025-11-08 03:00:03] Cleaned up 3 expired export(s)
[2025-11-08 03:00:03] === Analytics ETL Job Completed Successfully ===
```

**Initial Backfill:**

To populate historical data when first deploying:

```bash
php cron/analytics_etl.php
```

The job will automatically detect missing dates and backfill from the earliest ledger entry.

---

## Testing Guide

### Test Scenario 1: Overview KPIs

**Setup:**
```sql
-- Create test transactions
INSERT INTO wallet_ledger (id, user_id, token_type, direction, reason, amount, occurred_at)
VALUES
  (ULID(), 1, 'regular', 'credit', 'purchase', 1000, '2025-11-01 10:00:00'),
  (ULID(), 1, 'regular', 'debit', 'chapter_generation', 500, '2025-11-01 11:00:00'),
  (ULID(), 2, 'promo', 'credit', 'referral_bonus', 100, '2025-11-01 12:00:00');
```

**Request:**
```bash
curl -X GET "http://localhost/api/v1/admin/analytics/tokens/overview?from=2025-11-01&to=2025-11-01" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)"
```

**Expected Response:**
```json
{
  "credited": 1100,
  "debited": 500,
  "net": 600,
  "revenue_in_inr": 0.00,
  "active_users": 2
}
```

### Test Scenario 2: Token Trend (Weekly)

**Request:**
```bash
curl -X GET "http://localhost/api/v1/admin/analytics/tokens/trend?granularity=weekly&from=2025-10-28&to=2025-11-10" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)"
```

**Expected Response:**
```json
{
  "series": [
    {
      "date": "2025-10-28",  // Week starting Monday
      "credited": 5000,
      "debited": 3000,
      "net": 2000,
      "active_users": 15,
      "revenue_in_inr": 150.00
    },
    {
      "date": "2025-11-04",
      "credited": 6000,
      "debited": 3500,
      "net": 2500,
      "active_users": 18,
      "revenue_in_inr": 180.00
    }
  ]
}
```

### Test Scenario 3: Export Creation

**Request:**
```bash
curl -X POST "http://localhost/api/v1/admin/analytics/exports" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "csv",
    "dataset": "transactions",
    "filters": {
      "from": "2025-11-01",
      "to": "2025-11-08",
      "direction": "debit"
    }
  }'
```

**Expected Response:**
```json
{
  "export_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
  "status": "ready",
  "type": "csv",
  "dataset": "transactions",
  "estimated_rows": 150,
  "actual_rows": 148,
  "download_url": "/exports/transactions_20251108153045.csv",
  "expires_at": "2025-11-09 15:30:45",
  "created_at": "2025-11-08 15:30:42",
  "completed_at": "2025-11-08 15:30:45"
}
```

**CSV Output Example:**
```csv
id,user_id,username,email,token_type,direction,reason,amount,balance_after_regular,balance_after_promo,reference_id,metadata,occurred_at
01ARZ3NDEK...,1,john_doe,john@example.com,regular,debit,chapter_generation,500,9500,100,chapter_123,"{""chapter_id"":123}",2025-11-01 11:00:00
```

### Test Scenario 4: Top Users by Spend

**Request:**
```bash
curl -X GET "http://localhost/api/v1/admin/analytics/users/top?metric=spend&from=2025-11-01&to=2025-11-08&limit=10" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)"
```

**Expected Response:**
```json
{
  "items": [
    {
      "user_id": 42,
      "username": "power_user",
      "email": "power@example.com",
      "spend": 15000
    },
    {
      "user_id": 18,
      "username": "regular_user",
      "email": "regular@example.com",
      "spend": 8500
    }
  ]
}
```

### Test Scenario 5: ETL Job Execution

**Run manually:**
```bash
php cron/analytics_etl.php
```

**Verify materialized table:**
```sql
SELECT * FROM analytics_token_daily
WHERE date = '2025-11-07';
```

**Expected:**
```
+------------+----------+---------+------+---------------+--------------+--------------------+------------------+
| date       | credited | debited | net  | revenue_in_inr| active_users | by_feature         | regular_vs_promo |
+------------+----------+---------+------+---------------+--------------+--------------------+------------------+
| 2025-11-07 | 15000    | 8500    | 6500 | 450.00        | 45           | {"purchase":...}   | {"regular":...}  |
+------------+----------+---------+------+---------------+--------------+--------------------+------------------+
```

---

## Deployment Checklist

### 1. Database Migration

```bash
mysql -u your_user -p your_database < migrations/005_analytics.sql
```

**Verify tables:**
```sql
SHOW TABLES LIKE 'analytics_%';
DESCRIBE analytics_token_daily;
DESCRIBE analytics_exports;
```

### 2. Create Export Directory

```bash
mkdir -p exports
chmod 755 exports
chown www-data:www-data exports  # Or your PHP user
```

**Verify write permissions:**
```bash
sudo -u www-data touch exports/test.txt
rm exports/test.txt
```

### 3. Update .htaccess

The .htaccess file has been updated with Phase 5 routes. Verify:

```bash
grep -A 10 "Analytics & Exports" .htaccess
```

### 4. Test Apache Rewrite

```bash
# Restart Apache
sudo systemctl restart apache2  # or httpd

# Test route
curl -I "http://localhost/api/v1/admin/analytics/tokens/overview?from=2025-11-01&to=2025-11-08"
```

### 5. Setup Cron Jobs

**Add to crontab:**
```bash
crontab -e
```

**Add line:**
```cron
# Analytics ETL - Runs nightly at 03:00 IST
0 3 * * * php /path/to/api_prompt/cron/analytics_etl.php >> /path/to/logs/analytics_etl.log 2>&1
```

**Create log directory:**
```bash
mkdir -p /path/to/logs
chmod 755 /path/to/logs
```

### 6. Initial Data Backfill

**Run ETL job to populate historical data:**
```bash
php cron/analytics_etl.php
```

**Monitor progress:**
```bash
tail -f /path/to/logs/analytics_etl.log
```

### 7. Test Analytics Endpoints

**Test each endpoint:**
```bash
# Overview
curl "http://localhost/api/v1/admin/analytics/tokens/overview?from=2025-11-01&to=2025-11-08" \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Trend
curl "http://localhost/api/v1/admin/analytics/tokens/trend?granularity=daily&from=2025-11-01&to=2025-11-08" \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Feature breakdown
curl "http://localhost/api/v1/admin/analytics/tokens/by-feature?from=2025-11-01&to=2025-11-08" \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Composition
curl "http://localhost/api/v1/admin/analytics/tokens/composition?from=2025-11-01&to=2025-11-08" \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Top users
curl "http://localhost/api/v1/admin/analytics/users/top?metric=spend&from=2025-11-01&to=2025-11-08&limit=20" \
  -H "Authorization: Bearer ADMIN_TOKEN"
```

### 8. Test Export Flow

**Create export:**
```bash
curl -X POST "http://localhost/api/v1/admin/analytics/exports" \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"csv","dataset":"transactions","filters":{"from":"2025-11-01","to":"2025-11-08"}}'
```

**Check status:**
```bash
curl "http://localhost/api/v1/admin/analytics/exports/01ARZ3NDEKTSV4RRFFQ69G5FAV" \
  -H "Authorization: Bearer ADMIN_TOKEN"
```

**Download file:**
```bash
curl -O "http://localhost/exports/transactions_20251108153045.csv"
```

### 9. Verify File Permissions

```bash
ls -la exports/
# Should show:
# -rw-r--r-- www-data www-data ... transactions_20251108153045.csv
```

### 10. Monitor Logs

**Check PHP error log:**
```bash
tail -f /var/log/apache2/error.log
```

**Check ETL log:**
```bash
tail -f /path/to/logs/analytics_etl.log
```

---

## Performance Considerations

### 1. Materialized Table Benefits

- **Fast queries:** Pre-aggregated data eliminates expensive GROUP BY operations
- **Predictable performance:** Query time independent of ledger size
- **Reduced load:** Analytics queries don't impact transactional workload

### 2. Date Range Limits

- **Max 365 days:** Prevents memory exhaustion and timeout issues
- **Pagination for exports:** 100k row limit protects server resources

### 3. Index Usage

```sql
-- analytics_token_daily
INDEX idx_date_range (date) -- Fast date range scans

-- analytics_exports
INDEX idx_user_status (user_id, status) -- Fast user export lookup
INDEX idx_expires (expires_at) -- Fast cleanup query
```

### 4. Export File Cleanup

- **24-hour expiry:** Limits disk usage
- **Automated cleanup:** ETL job removes expired files
- **Max file size:** 100k rows * ~200 bytes/row = ~20MB max

### 5. JSON Column Performance

MySQL 5.7+ supports efficient JSON operations:
```sql
-- Fast JSON extraction
SELECT by_feature->>'$.chapter_generation.debited'
FROM analytics_token_daily
WHERE date = '2025-11-07';
```

---

## Security Notes

1. **Admin-only access:** All analytics endpoints require admin scope
2. **No PII in exports:** Email/username only for authorized admins
3. **Temporary URLs:** Export links expire after 24 hours
4. **File isolation:** Exports stored outside web root if possible
5. **Input validation:** Date format, range limits, ULID validation

---

## Future Enhancements

1. **Async export generation:** Queue system (Redis + worker) for large exports
2. **Dashboard caching:** Redis cache for frequently accessed date ranges
3. **Real-time analytics:** WebSocket updates for live dashboards
4. **Custom reports:** User-defined metric combinations
5. **Email delivery:** Send export download link via email
6. **S3 storage:** Store exports in S3 with signed URLs
7. **Data retention:** Archive old analytics data

---

## Troubleshooting

### Issue: ETL job fails with "No data to process"

**Cause:** No new ledger entries since last run

**Solution:** Normal behavior, job will process when new data arrives

---

### Issue: Export status stuck on "preparing"

**Cause:** Export generation failed without error handling

**Solution:**
```sql
-- Check for errors
SELECT id, status, error_message
FROM analytics_exports
WHERE status = 'preparing'
  AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE);

-- Manually fail stuck exports
UPDATE analytics_exports
SET status = 'failed', error_message = 'Timeout'
WHERE status = 'preparing'
  AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE);
```

---

### Issue: Export file not found (404)

**Cause:** File expired or deleted

**Solution:**
```sql
-- Check export status
SELECT status, expires_at, file_path
FROM analytics_exports
WHERE id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

-- Verify file exists
```
```bash
ls -la exports/transactions_20251108153045.csv
```

---

### Issue: Slow analytics queries

**Cause:** Date range too large or materialized table not updated

**Solution:**
1. Check ETL job ran successfully
2. Reduce date range to < 90 days
3. Add indexes if needed:
```sql
SHOW INDEX FROM analytics_token_daily;
```

---

### Issue: JSON parsing errors in by_feature column

**Cause:** Invalid JSON stored

**Solution:**
```sql
-- Find invalid JSON
SELECT date, by_feature
FROM analytics_token_daily
WHERE JSON_VALID(by_feature) = 0;

-- Fix with empty object
UPDATE analytics_token_daily
SET by_feature = JSON_OBJECT()
WHERE JSON_VALID(by_feature) = 0;
```

---

## Summary

Phase 5 completes the Wallet API with comprehensive analytics and export capabilities. Key achievements:

- **Materialized analytics:** Fast dashboard queries via `analytics_token_daily`
- **Flexible exports:** CSV/JSON exports for 5 datasets with custom filters
- **Top users:** Leaderboards by spend/earn/net metrics
- **Automated ETL:** Nightly job keeps analytics current
- **Admin insights:** Overview KPIs, trends, feature breakdown, composition

**Next steps:** Deploy to production, monitor ETL job, gather admin feedback for dashboard UI.

---

**Phase 5 Implementation Complete! 🎉**

All 5 phases of the Wallet API are now implemented:
- ✅ Phase 1: Wallet Foundation
- ✅ Phase 2: Token Deduction
- ✅ Phase 3: Token Purchase
- ✅ Phase 4: Promotions & Referrals
- ✅ Phase 5: Analytics & Exports
