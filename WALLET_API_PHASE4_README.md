# Wallet API - Phase 4: Promotions, Referrals & Expiry

This document describes Phase 4 of the Wallet API implementation, which adds **promotional campaigns, referral system, and promo token expiry management**.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Schema](#database-schema)
4. [Referral System](#referral-system)
5. [Promo Token Expiry](#promo-token-expiry)
6. [API Endpoints](#api-endpoints)
7. [Background Jobs](#background-jobs)
8. [Implementation Guide](#implementation-guide)
9. [Testing](#testing)
10. [Deployment](#deployment)

---

## Overview

Phase 4 implements a complete **promotional marketing system** with:

1. **Promotional Campaigns** - Flexible campaign management (referral, seasonal, bulk)
2. **Referral System** - User referral codes with bonus rewards
3. **Token Expiry** - Automatic expiration of promotional tokens
4. **Notifications** - Expiry warnings and event publishing

### Key Features

✅ **Multi-campaign support** (referral, seasonal, bulk)
✅ **Referral code generation** (unique 12-char codes)
✅ **Automatic promo token expiry** (30-day default)
✅ **Expiry preview and warnings** (3-day notice)
✅ **Campaign statistics** (granted, expired, active users)
✅ **Per-user caps** (prevent abuse)

---

## Architecture

### System Components

```
┌──────────────────────┐
│  Promotion Campaign  │ ← Admin creates campaigns
└──────────────────────┘
          │
          ├─> Referral System
          │   ├─> Generate referral codes
          │   ├─> Track referrals
          │   └─> Award bonuses
          │
          ├─> Promo Expiry
          │   ├─> Schedule expiry (30 days)
          │   ├─> Nightly expiry job
          │   └─> Send warnings (3 days ahead)
          │
          └─> Events
              ├─> wallet.updated
              ├─> promo.expiry_upcoming
              └─> promo.expired
```

### Token Flow

```
Referral Signup
    ↓
100 Promo Tokens Credited
    ↓
Expiry Scheduled (+30 days)
    ↓
Warning Sent (-3 days)
    ↓
Tokens Expired (if unused)
```

---

## Database Schema

### Table: promotion_campaigns

Stores promotional campaign configurations.

| Column       | Type        | Description                              |
|--------------|-------------|------------------------------------------|
| id           | VARCHAR(26) | ULID primary key                         |
| name         | VARCHAR(120)| Campaign name                            |
| type         | ENUM        | referral, seasonal, bulk                 |
| bonus_amount | INT         | Token amount to award                    |
| token_type   | ENUM        | regular or promo                         |
| start_at     | TIMESTAMP   | Campaign start (null = immediate)        |
| end_at       | TIMESTAMP   | Campaign end (null = no end)             |
| per_user_cap | INT         | Max times user can benefit (null = unlimited) |
| terms        | TEXT        | Terms and conditions                     |
| status       | ENUM        | draft, active, paused, archived          |
| created_by   | INT         | Admin user who created                   |
| metadata     | JSON        | Additional settings                      |

**Campaign Types:**
- `referral`: User referral bonuses
- `seasonal`: Limited-time promotions
- `bulk`: Bulk rewards (e.g., welcome bonus)

### Table: referrals

Tracks user referrals and their status.

| Column               | Type        | Description                          |
|----------------------|-------------|--------------------------------------|
| id                   | VARCHAR(26) | ULID primary key                     |
| campaign_id          | VARCHAR(26) | FK to promotion_campaigns            |
| referrer_user_id     | INT         | User who referred                    |
| referral_code        | VARCHAR(12) | Unique referral code                 |
| referee_user_id      | INT         | User who signed up (null until signup) |
| status               | ENUM        | generated, clicked, joined, credited, rejected |
| ledger_transaction_id| VARCHAR(26) | Ledger entry when credited           |
| click_count          | INT         | Number of link clicks                |
| last_clicked_at      | TIMESTAMP   | Last click timestamp                 |

**Unique Constraints:**
- `(referrer_user_id, referral_code)` - One code per referrer
- `(campaign_id, referee_user_id)` - Prevent double credit

**Status Flow:**
```
generated → clicked → joined → credited
                           ↘ rejected
```

### Table: promo_expiry_schedules

Manages expiration of promotional tokens.

| Column            | Type        | Description                          |
|-------------------|-------------|--------------------------------------|
| id                | VARCHAR(26) | ULID primary key                     |
| user_id           | INT         | FK to users                          |
| source_ledger_id  | VARCHAR(26) | FK to wallet_ledger (promo credit)   |
| expiry_at         | TIMESTAMP   | When promo tokens expire             |
| amount_initial    | INT         | Initial promo amount                 |
| amount_remaining  | INT         | Remaining amount to expire           |
| status            | ENUM        | scheduled, partially_expired, expired|

**Expiry Logic:**
- Created when promo tokens awarded
- Default expiry: 30 days from credit
- Partial expiry if user has less promo balance than scheduled

---

## Referral System

### Referral Code Format

- **Length:** 12 characters
- **Format:** `PREFIX` (4 chars) + `SUFFIX` (8 chars)
- **Example:** `SUBH1234XYZW`
- **Generation:** MD5 hash of user ID + random suffix
- **Uniqueness:** Validated against existing codes

### Referral Flow

**1. User Creates Referral Link**

```bash
POST /api/v1/wallet/me/referrals/link
```

**Response:**
```json
{
  "code": "SUBH1234XYZW",
  "url": "https://app.quadralearn.com/r/SUBH1234XYZW"
}
```

**2. Friend Clicks Link**

- Link tracked (click_count incremented)
- Status: `generated` → `clicked`

**3. Friend Signs Up**

- Backend calls apply referral endpoint
- Status: `clicked` → `joined`

**4. Bonus Credited**

```bash
POST /api/v1/promotions/referral/apply (SERVICE)
{
  "referral_code": "SUBH1234XYZW",
  "new_user_id": 123,
  "campaign_id": "campaign_ulid"
}
```

- Referrer gets 100 promo tokens
- Expiry scheduled for +30 days
- Status: `joined` → `credited`

### Referral Bonuses

**Default Campaign:**
- Referrer: 100 promo tokens (30-day expiry)
- Referee: Optional (configurable per campaign)

**Validation:**
- Campaign must be active
- Per-user cap enforced
- One bonus per referee per campaign

---

## Promo Token Expiry

### Expiry Schedule

**When Created:**
- Triggered by promo token credit
- Default: 30 days from credit date
- Stored in `promo_expiry_schedules`

**Expiry Logic:**

```php
$expiryDate = date('Y-m-d', strtotime('+30 days'));

// Example:
// Credit: 2025-11-08
// Expiry: 2025-12-08
```

### Expiry Processing

**Nightly Job** (02:00 IST):

```bash
0 2 * * * php /path/to/cron/expire_promos.php
```

**Process:**
1. Find schedules where `expiry_at <= NOW()`
2. Get user's current promo balance
3. Debit up to `min(amount_remaining, promo_balance)`
4. Update schedule status
5. Publish `promo.expired` event

**Partial Expiry:**

If user has 50 promo tokens but 100 scheduled to expire:
- Debit: 50 tokens
- Remaining schedule: 50 tokens
- Status: `partially_expired`

Next expiry run will handle remaining when user gets more promo tokens.

### Expiry Warnings

**3-Day Warning:**

Sent automatically 3 days before expiry:

```json
{
  "event": "promo.expiry_upcoming",
  "user_id": 123,
  "expiring_tokens": 100,
  "expiry_date": "2025-12-08T00:00:00Z"
}
```

---

## API Endpoints

### User Endpoints

#### 1. POST /api/v1/wallet/me/referrals/link

Create or get referral link.

**Response:**
```json
{
  "code": "SUBH1234XYZW",
  "url": "https://app.quadralearn.com/r/SUBH1234XYZW"
}
```

**Implementation Pattern:**

```php
// v1/wallet/referrals/link.php
$user = require_auth();
$userId = (int)$user['sub'];

// Get active referral campaign
$campaign = get_active_campaign($pdo, CAMPAIGN_TYPE_REFERRAL);

// Create or get existing code
$result = create_or_get_referral_link($pdo, $userId, $campaign['id'], $baseUrl);

send_success($result);
```

---

#### 2. GET /api/v1/wallet/me/referrals

List user's referrals with status.

**Query Parameters:**
- `limit` (default 25)
- `cursor` (pagination)

**Response:**
```json
{
  "items": [
    {
      "code": "SUBH1234XYZW",
      "status": "credited",
      "referee_username": "john_doe",
      "referee_email": "john@example.com",
      "clicks": 5,
      "created_at": "2025-11-01T10:00:00Z"
    }
  ],
  "next_cursor": "opaque"
}
```

**Implementation Pattern:**

```php
// v1/wallet/referrals/list.php
$user = require_auth();
$userId = (int)$user['sub'];
$pagination = get_pagination_params(25, 100);

$result = get_user_referrals($pdo, $userId, $pagination['limit'], $pagination['cursor']);

send_success($result);
```

---

#### 3. GET /api/v1/wallet/me/expiries

View upcoming promo token expiries.

**Query Parameters:**
- `range`: `next_30d` (default), `next_7d`, `next_3d`

**Response:**
```json
{
  "items": [
    {
      "date": "2025-12-08",
      "amount": 100,
      "source": "referral_bonus"
    },
    {
      "date": "2025-12-15",
      "amount": 50,
      "source": "seasonal_promo"
    }
  ]
}
```

**Implementation Pattern:**

```php
// v1/wallet/expiries/list.php
$user = require_auth();
$userId = (int)$user['sub'];
$range = $_GET['range'] ?? 'next_30d';

$result = get_upcoming_expiries($pdo, $userId, $range);

send_success($result);
```

---

### Service Endpoints

#### 4. POST /api/v1/promotions/referral/apply

Apply referral bonus on signup (internal service call).

**Headers:**
- `Authorization: Bearer <SERVICE_TOKEN>` (service-to-service auth)

**Request Body:**
```json
{
  "referral_code": "SUBH1234XYZW",
  "new_user_id": 123,
  "campaign_id": "campaign_ulid"
}
```

**Response:**
```json
{
  "success": true,
  "referrer_user_id": 456,
  "bonus_amount": 100,
  "token_type": "promo",
  "transaction_id": "01JDKX..."
}
```

**Validations:**
- Campaign must be active
- Referral code must exist
- Per-user cap not exceeded
- Prevent double credit (unique constraint)

**Implementation Pattern:**

```php
// v1/promotions/referral_apply.php
// Validate service token
validate_service_token();

$input = json_decode(file_get_contents('php://input'), true);
validate_required_fields($input, ['referral_code', 'new_user_id', 'campaign_id']);

$pdo->beginTransaction();

$result = apply_referral_bonus(
    $pdo,
    $input['referral_code'],
    (int)$input['new_user_id'],
    $input['campaign_id']
);

$pdo->commit();

send_success($result);
```

---

### Admin Endpoints

#### 5. POST /api/v1/admin/promotions/campaigns

Create promotion campaign.

**Request Body:**
```json
{
  "name": "November Referral Bonus",
  "type": "referral",
  "bonus_amount": 100,
  "token_type": "promo",
  "start_at": "2025-11-01T00:00:00Z",
  "end_at": null,
  "per_user_cap": 10,
  "terms": "Refer friends and earn 100 promo tokens per signup (max 10)"
}
```

**Response:**
```json
{
  "campaign_id": "01JDKX...",
  "status": "draft"
}
```

---

#### 6. GET /api/v1/admin/promotions/campaigns

List campaigns with filters.

**Query Parameters:**
- `status`: `active`, `draft`, `paused`, `archived`
- `type`: `referral`, `seasonal`, `bulk`

**Response:**
```json
{
  "items": [
    {
      "id": "01JDKX...",
      "name": "November Referral Bonus",
      "type": "referral",
      "status": "active",
      "bonus_amount": 100,
      "created_at": "2025-11-01T00:00:00Z"
    }
  ]
}
```

---

#### 7. GET /api/v1/admin/promotions/campaigns/{id}/stats

Get campaign statistics.

**Response:**
```json
{
  "granted": 5600,
  "expired": 1200,
  "active_users": 48,
  "joined": 74
}
```

**Metrics:**
- `granted`: Total tokens awarded
- `expired`: Total tokens expired
- `active_users`: Unique users who received bonuses
- `joined`: Total referee signups

---

#### 8. POST /api/v1/admin/promotions/expiries/preview

Preview upcoming expiries for specific date.

**Request Body:**
```json
{
  "date": "2025-12-08"
}
```

**Response:**
```json
[
  {
    "user_id": 123,
    "scheduled_expiry": 150,
    "current_promo_balance": 100,
    "actual_expiry": 100
  },
  {
    "user_id": 456,
    "scheduled_expiry": 200,
    "current_promo_balance": 250,
    "actual_expiry": 200
  }
]
```

---

## Background Jobs

### 1. Promo Expiry Runner

**Script:** `cron/expire_promos.php`

**Schedule:** Nightly at 02:00 IST

```bash
0 2 * * * php /path/to/cron/expire_promos.php >> /path/to/logs/expire_promos.log 2>&1
```

**Process:**

1. **Send Warnings** (3 days ahead)
   ```php
   send_expiry_warnings($pdo, 3);
   ```

2. **Process Expiries**
   ```php
   $results = process_promo_expiries($pdo);
   ```

3. **Log Results**
   ```
   [2025-12-08 02:00:01] === Promo Expiry Runner Started ===
   [2025-12-08 02:00:05] Sent 12 expiry warning(s)
   [2025-12-08 02:00:10] Processed: 45 schedules
   [2025-12-08 02:00:10] Total expired: 2,350 tokens
   [2025-12-08 02:00:10] Users affected: 38
   [2025-12-08 02:00:10] === Promo Expiry Runner Completed ===
   ```

**Idempotency:**
- Safe to run multiple times
- Only processes schedules with `amount_remaining > 0`
- Updates schedule status after processing

---

## Implementation Guide

### Complete Endpoint Implementation Pattern

All endpoints follow this pattern. Here's a template:

```php
<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id, X-Idempotency-Key, X-Source');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/errors.php';
require_once __DIR__ . '/../../lib/headers.php';
require_once __DIR__ . '/../../lib/promotions.php';

try {
    $headers = validate_standard_headers(false);
    $user = require_auth();
    $userId = (int)$user['sub'];

    validate_scopes($user, ['wallet:read']);

    $pdo = get_db();

    // Your endpoint logic here

    send_success($result);

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    server_error('Operation failed');
}
```

### Required Endpoints to Implement

Based on the .htaccess routes, create these files:

```
v1/wallet/referrals/
  ├─ link.php           ✓ Created
  └─ list.php           → Use get_user_referrals()

v1/wallet/expiries/
  └─ list.php           → Use get_upcoming_expiries()

v1/promotions/
  └─ referral_apply.php → Use apply_referral_bonus()

v1/admin/promotions/campaigns/
  ├─ list.php           → Query promotion_campaigns table
  ├─ view.php           → Get single campaign
  └─ stats.php          ✓ Use get_campaign_stats()

v1/admin/promotions/
  └─ expiry_preview.php → Use preview_expiries()
```

---

## Testing

### Test 1: Referral Bonus (Idempotent)

**Setup:** Active referral campaign, User A creates link

**Actions:**
1. User A creates referral link
2. User B signs up with link
3. Apply referral twice (same referee)

**Expected:**
- User A gets exactly 100 promo tokens
- Expiry scheduled for +30 days
- Second apply returns error (unique constraint)

**SQL Verification:**
```sql
SELECT * FROM referrals WHERE referrer_user_id = <A_ID>;
-- Should show 1 row, status=credited

SELECT * FROM wallet_ledger WHERE user_id = <A_ID> AND reason = 'referral_bonus';
-- Should show 1 credit entry

SELECT * FROM promo_expiry_schedules WHERE user_id = <A_ID>;
-- Should show 1 schedule with amount_initial=100
```

---

### Test 2: Expiry Reduces Only Promo

**Setup:** User has 50 regular + 30 promo tokens, 100 promo scheduled to expire

**Action:** Run expiry job

**Expected:**
- Debit only 30 promo tokens (all available promo)
- Regular tokens unchanged: 50
- Promo balance: 0
- Schedule: amount_remaining=70, status=partially_expired

**SQL Verification:**
```sql
SELECT * FROM wallet_balance_cache WHERE user_id = <USER_ID>;
-- regular_balance=50, promo_balance=0

SELECT * FROM promo_expiry_schedules WHERE id = <SCHEDULE_ID>;
-- amount_remaining=70, status='partially_expired'
```

---

### Test 3: Expiry Warning Trigger

**Setup:** Promo tokens expire in 2 days

**Action:** Run expiry job (warnings sent 3 days ahead)

**Expected:**
- Event published: `promo.expiry_upcoming`
- Payload includes: user_id, expiring_tokens, expiry_date
- No actual debit yet

**Event Verification:**
```sql
SELECT * FROM events WHERE event_type = 'promo.expiry_upcoming' ORDER BY created_at DESC LIMIT 10;
```

---

## Deployment

### Step 1: Run Migration

```bash
mysql -u username -p database < migrations/004_promotions_referrals.sql
```

### Step 2: Create Default Referral Campaign

```sql
INSERT INTO promotion_campaigns (
  id, name, type, bonus_amount, token_type,
  per_user_cap, status, created_at
) VALUES (
  '01JDKXDEFAULT0000000000000',
  'Default Referral Program',
  'referral',
  100,
  'promo',
  10,
  'active',
  NOW()
);
```

### Step 3: Setup Cron Job

```bash
# Edit crontab
crontab -e

# Add expiry job (runs daily at 2 AM IST)
0 2 * * * /usr/bin/php /path/to/api_prompt/cron/expire_promos.php >> /path/to/logs/expire_promos.log 2>&1

# Create log directory
mkdir -p /path/to/logs
```

### Step 4: Configure Base URL

In `config.php`:

```php
const REFERRAL_BASE_URL = 'https://app.quadralearn.com';
```

### Step 5: Test Referral Flow

```bash
# 1. Create referral link
curl -X POST "https://your-domain.com/api/v1/wallet/me/referrals/link" \
  -H "Authorization: Bearer USER_A_JWT" \
  -H "X-Request-Id: $(uuidgen)"

# 2. Register new user (User B)
# 3. Apply referral
curl -X POST "https://your-domain.com/api/v1/promotions/referral/apply" \
  -H "Authorization: Bearer SERVICE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "referral_code": "SUBH1234XYZW",
    "new_user_id": 123,
    "campaign_id": "01JDKXDEFAULT0000000000000"
  }'

# 4. Verify User A balance
curl -X GET "https://your-domain.com/api/v1/wallet/me" \
  -H "Authorization: Bearer USER_A_JWT" \
  -H "X-Request-Id: $(uuidgen)"
```

---

## Acceptance Tests Summary

| Test | Description | Expected Result |
|------|-------------|-----------------|
| 1 | Referral bonus idempotency | Exactly 100 promo to referrer, one credit |
| 2 | Expiry reduces only promo | Only promo tokens debited, regular untouched |
| 3 | Partial expiry | If promo < scheduled, debit available only |
| 4 | Expiry warning (3-day) | Event published, no debit yet |
| 5 | Per-user cap | Referrer cannot exceed campaign limit |
| 6 | Double credit prevention | Unique constraint prevents duplicate bonus |

---

## Next Steps (Future Enhancements)

- [ ] Seasonal campaigns with auto-activation
- [ ] Bulk promo distribution
- [ ] Referral leaderboards
- [ ] Dynamic expiry periods per campaign
- [ ] Promo code system (non-referral)
- [ ] A/B testing for campaigns
- [ ] Email notifications for expiry warnings

---

**Implementation Date:** 2025-11-08
**API Version:** 1.0
**Phase:** 4 (Promotions, Referrals & Expiry)
