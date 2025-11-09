# Wallet API - Phase 2: Token Deduction for Chapter Generation

This document describes Phase 2 of the Wallet API implementation, which adds **token authorization and deduction** features for chapter generation and other token-consuming operations.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Changes](#database-changes)
4. [Pricebook](#pricebook)
5. [Authorization Flow](#authorization-flow)
6. [API Endpoints](#api-endpoints)
7. [Background Jobs](#background-jobs)
8. [Testing](#testing)
9. [Deployment](#deployment)

---

## Overview

Phase 2 implements a **hold-then-capture pattern** for token deductions, similar to credit card pre-authorization:

1. **HOLD**: Reserve tokens before performing the operation
2. **CAPTURE**: Debit tokens when operation succeeds
3. **VOID**: Cancel hold or refund tokens if operation fails

### Key Features

✅ **Hold-then-capture pattern** for safe token deductions
✅ **Automatic hold expiry** (10 minutes)
✅ **Idempotent operations** with duplicate prevention
✅ **Pricebook service** for feature pricing
✅ **Single-shot deduction** for simple use cases
✅ **Race condition handling** (capture-then-void)

---

## Architecture

### Hold-Then-Capture Pattern

```
┌─────────────┐     ┌──────────────┐     ┌──────────────┐
│   CLIENT    │     │   BACKEND    │     │   WALLET     │
└─────────────┘     └──────────────┘     └──────────────┘
       │                    │                    │
       │  1. HOLD (POST)    │                    │
       ├───────────────────>│  Check balance     │
       │                    ├───────────────────>│
       │                    │  (no deduction)    │
       │  { auth_id }       │                    │
       │<───────────────────┤                    │
       │                    │                    │
       │  [Generate chapter with AI...]          │
       │                    │                    │
       │  2a. SUCCESS       │                    │
       │  CAPTURE (POST)    │                    │
       ├───────────────────>│  Debit tokens      │
       │                    ├───────────────────>│
       │  { debited: 10 }   │                    │
       │<───────────────────┤                    │
       │                    │                    │
       │  2b. FAILURE       │                    │
       │  VOID (POST)       │                    │
       ├───────────────────>│  Cancel/refund     │
       │                    ├───────────────────>│
       │  { refunded: 10 }  │                    │
       │<───────────────────┤                    │
```

### Tech Stack Additions

- **Pricebook**: Centralized pricing configuration
- **Authorization Service**: Hold/capture/void operations
- **Cron Job**: Background hold expiry runner

---

## Database Changes

### New Table: token_authorizations

Run migration: `migrations/002_token_authorizations.sql`

**Schema:**

| Column                  | Type       | Description                          |
|-------------------------|------------|--------------------------------------|
| id                      | VARCHAR(26)| ULID primary key                     |
| user_id                 | INT        | Foreign key to users.id              |
| feature                 | VARCHAR(64)| Feature name (e.g., 'chapter_generation') |
| resource_key            | VARCHAR(128)| Stable hash of resource params      |
| amount                  | INT        | Total tokens reserved                |
| status                  | ENUM       | created, held, captured, voided, expired |
| hold_expires_at         | TIMESTAMP  | When hold expires (10 min from creation) |
| created_at              | TIMESTAMP  | Record creation time                 |
| updated_at              | TIMESTAMP  | Last update time                     |
| metadata                | JSON       | Additional metadata                  |
| idempotency_key         | VARCHAR(128)| Unique key for deduplication        |
| captured_transaction_id | VARCHAR(26)| Ledger entry ID when captured       |
| voided_transaction_id   | VARCHAR(26)| Ledger entry ID when voided (refund)|

**Indexes:**
- `(user_id, feature)`
- `(status)`
- `(hold_expires_at)`
- `(user_id, feature, resource_key, status)` - for enforcing single active authorization

---

## Pricebook

The pricebook defines token costs for features.

### Configuration

Located in `lib/pricebook.php`:

```php
const PRICEBOOK = [
    'chapter_generation' => [
        'unit_cost' => 10,
        'token_type' => 'regular',
        'currency_hint' => '₹',
        'description' => 'Generate one chapter with AI'
    ],
    'test_generation' => [
        'unit_cost' => 5,
        'token_type' => 'regular',
        'currency_hint' => '₹',
        'description' => 'Generate a mock test'
    ],
    'outline_generation' => [
        'unit_cost' => 5,
        'token_type' => 'regular',
        'currency_hint' => '₹',
        'description' => 'Generate book outline'
    ],
];
```

### Pricing Rules

- Server-side pricing is **authoritative** (client hints ignored)
- Costs are in token units (not currency)
- Currency hint is for UI display only

---

## Authorization Flow

### Flow Diagram

```
START
  │
  ├─> CREATE AUTHORIZATION (POST /tokens/authorizations)
  │   ├─> Check balance >= required
  │   ├─> Check no existing active auth for same resource
  │   ├─> Create auth with status='held'
  │   └─> Set hold_expires_at = now + 10 minutes
  │
  ├─> [Perform operation (e.g., AI generation)]
  │
  ├─> SUCCESS?
  │   ├─> YES: CAPTURE (POST /tokens/authorizations/{id}/capture)
  │   │   ├─> Check status != voided/expired
  │   │   ├─> Debit tokens from wallet
  │   │   ├─> Update auth status='captured'
  │   │   └─> Return transaction_id
  │   │
  │   └─> NO: VOID (POST /tokens/authorizations/{id}/void)
  │       ├─> If captured: credit tokens (refund)
  │       ├─> If held: just mark voided (no refund)
  │       └─> Update auth status='voided'
  │
END
```

### Status Transitions

```
created
  │
  ├─> held (when authorization created)
  │     │
  │     ├─> captured (on successful capture)
  │     │
  │     ├─> voided (on void before capture)
  │     │
  │     └─> expired (10 min timeout via cron)
  │
  └─> (race) captured → voided (refund issued)
```

---

## API Endpoints

### 1. GET /api/v1/wallet/me/pricebook

Get pricing information for features.

**Query Parameters:**
- `feature` (optional) - Specific feature name

**Examples:**

```bash
# Get all pricebook
GET /api/v1/wallet/me/pricebook

# Get specific feature
GET /api/v1/wallet/me/pricebook?feature=chapter_generation
```

**Response (single feature):**
```json
{
  "unit_cost": 10,
  "token_type": "regular",
  "currency_hint": "₹"
}
```

**Response (all features):**
```json
{
  "chapter_generation": {
    "unit_cost": 10,
    "token_type": "regular",
    "currency_hint": "₹"
  },
  "test_generation": {
    "unit_cost": 5,
    "token_type": "regular",
    "currency_hint": "₹"
  }
}
```

---

### 2. POST /api/v1/tokens/authorizations

Create a token authorization (HOLD).

**Required Headers:**
- `X-Idempotency-Key` (required)
- `X-Request-Id` (required)

**Request Body:**
```json
{
  "feature": "chapter_generation",
  "units": 1,
  "cost_per_unit": 10,
  "resource_key": "sha256_hash_of_params",
  "metadata": {
    "subject": "Maths",
    "grade": "VIII",
    "chapter_index": 1
  }
}
```

**Validations:**
- `units` >= 1
- `feature` must exist in pricebook
- Balance must be >= required amount
- Only one active authorization per (user_id, feature, resource_key)

**Response (201 Created):**
```json
{
  "authorization_id": "01JDKX3G8M2QWERTY9ABCD1234",
  "status": "held",
  "held_amount": 10,
  "hold_expires_at": "2025-11-08T12:45:00+00:00",
  "balance_preview": {
    "regular": 240,
    "promo": 0,
    "total": 240
  }
}
```

**Error Responses:**

**422 - Insufficient Balance:**
```json
{
  "error": {
    "code": "422_BUSINESS_RULE",
    "message": "Insufficient tokens",
    "details": {
      "required": 10,
      "available": 5,
      "error_code": "LOW_BALANCE"
    }
  }
}
```

---

### 3. POST /api/v1/tokens/authorizations/{authorization_id}/capture

Capture an authorization (debit tokens).

**Required Headers:**
- `X-Idempotency-Key` (required)
- `X-Request-Id` (required)

**Request Body:**
```json
{
  "result_id": "chapter_123",
  "status_from_upstream": "success"
}
```

**Response (200 OK):**
```json
{
  "status": "captured",
  "debited": 10,
  "balances": {
    "regular": 230,
    "promo": 0,
    "total": 230
  },
  "transaction_id": "01JDKX4H9N3ZASDFGH5678"
}
```

**Error Responses:**

**409 - Authorization Expired:**
```json
{
  "error": {
    "code": "409_CONFLICT",
    "message": "Authorization expired",
    "details": {
      "expired_at": "2025-11-08T12:45:00Z"
    }
  }
}
```

**409 - Already Voided:**
```json
{
  "error": {
    "code": "409_CONFLICT",
    "message": "Authorization cannot be captured",
    "details": {
      "status": "voided",
      "message": "Authorization is voided"
    }
  }
}
```

**Idempotency:**
- Calling capture twice with same authorization_id returns the original result
- No double-deduction occurs

---

### 4. POST /api/v1/tokens/authorizations/{authorization_id}/void

Void an authorization (cancel hold or refund captured tokens).

**Required Headers:**
- `X-Idempotency-Key` (required)
- `X-Request-Id` (required)

**Request Body:**
```json
{
  "status_from_upstream": "failed",
  "failure_code": "MODEL_TIMEOUT",
  "failure_msg": "LLM request timed out after 60 seconds"
}
```

**Response (200 OK - Not Captured):**
```json
{
  "status": "voided",
  "refunded": 0,
  "balances": {
    "regular": 240,
    "promo": 0,
    "total": 240
  }
}
```

**Response (200 OK - Was Captured):**
```json
{
  "status": "voided",
  "refunded": 10,
  "balances": {
    "regular": 240,
    "promo": 0,
    "total": 240
  }
}
```

**Race Condition Handling:**

If authorization was captured, then voided:
1. Debit entry exists in ledger (reason: `chapter_generation`)
2. Credit entry is created (reason: `refund_generation_failure`)
3. Net effect: balance restored

---

### 5. POST /api/v1/tokens/deduct

Single-shot token deduction (no hold, direct debit).

**Use Case:** Simple, synchronous operations where hold is unnecessary.

**Required Headers:**
- `X-Idempotency-Key` (required)
- `X-Request-Id` (required)

**Request Body:**
```json
{
  "reason": "chapter_generation",
  "amount": 10,
  "resource_key": "sha256_hash_of_params",
  "metadata": {
    "chapter_id": "ch_123"
  }
}
```

**Response (200 OK):**
```json
{
  "debited": 10,
  "balances": {
    "regular": 230,
    "promo": 0,
    "total": 230
  },
  "transaction_id": "01JDKX5K2P4ZBCDEFG7890"
}
```

**Idempotency:**
- Idempotent by `(user_id, reason, resource_key)`
- Duplicate requests return original transaction

**Error Responses:**

**422 - Insufficient Balance:**
```json
{
  "error": {
    "code": "422_BUSINESS_RULE",
    "message": "Insufficient tokens",
    "details": {
      "required": 10,
      "available": 5
    }
  }
}
```

---

## Background Jobs

### Hold Expiry Runner

**Script:** `cron/expire_holds.php`

**Purpose:** Automatically expires authorizations that exceed 10-minute hold time.

**Frequency:** Every minute (via cron)

**Cron Setup:**

```bash
# Edit crontab
crontab -e

# Add this line (runs every minute)
* * * * * php /path/to/api_prompt/cron/expire_holds.php >> /path/to/logs/expire_holds.log 2>&1
```

**How It Works:**

1. Queries all authorizations with `status='held'` and `hold_expires_at < NOW()`
2. Updates status to `'expired'`
3. Logs count of expired authorizations

**Monitoring:**

```bash
# View logs
tail -f /path/to/logs/expire_holds.log

# Sample output
[2025-11-08 12:46:01] === Hold Expiry Runner Started ===
[2025-11-08 12:46:01] Expired 3 authorization(s)
[2025-11-08 12:46:01] === Hold Expiry Runner Completed ===
```

---

## Testing

### Manual Testing with cURL

#### 1. Get Pricebook

```bash
curl -X GET "http://your-domain.com/api/v1/wallet/me/pricebook?feature=chapter_generation" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)"
```

#### 2. Create Authorization (HOLD)

```bash
curl -X POST "http://your-domain.com/api/v1/tokens/authorizations" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: hold-ch1-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{
    "feature": "chapter_generation",
    "units": 1,
    "cost_per_unit": 10,
    "resource_key": "test_resource_123",
    "metadata": {
      "subject": "Maths",
      "grade": "VIII"
    }
  }'
```

Save the `authorization_id` from the response.

#### 3. Capture Authorization

```bash
AUTH_ID="01JDKX3G8M2QWERTY9ABCD1234"

curl -X POST "http://your-domain.com/api/v1/tokens/authorizations/${AUTH_ID}/capture" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: capture-${AUTH_ID}" \
  -H "Content-Type: application/json" \
  -d '{
    "result_id": "chapter_456",
    "status_from_upstream": "success"
  }'
```

#### 4. Void Authorization

```bash
AUTH_ID="01JDKX3G8M2QWERTY9ABCD1234"

curl -X POST "http://your-domain.com/api/v1/tokens/authorizations/${AUTH_ID}/void" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: void-${AUTH_ID}" \
  -H "Content-Type: application/json" \
  -d '{
    "status_from_upstream": "failed",
    "failure_code": "MODEL_TIMEOUT",
    "failure_msg": "LLM timed out"
  }'
```

#### 5. Single-Shot Deduction

```bash
curl -X POST "http://your-domain.com/api/v1/tokens/deduct" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: deduct-test-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{
    "reason": "chapter_generation",
    "amount": 10,
    "resource_key": "direct_deduct_123",
    "metadata": {}
  }'
```

---

### Acceptance Tests (Phase 2)

#### Test 1: Insufficient Balance

**Setup:** User has 5 tokens
**Action:** Create authorization for 10 tokens
**Expected:** 422 error with `LOW_BALANCE`

```bash
# Should return 422 with error_code: LOW_BALANCE
```

#### Test 2: Double-Click Protection

**Setup:** User has 250 tokens
**Action:** Create authorization twice with same `resource_key`
**Expected:** Both requests succeed, only ONE debit occurs

```bash
# First request: Creates auth, returns auth_id
# Second request: Returns same auth_id (idempotent)
# Verify wallet_ledger has only ONE debit
```

#### Test 3: Capture After Void (Race Condition)

**Setup:** User creates authorization
**Action 1:** Capture authorization (debits 10 tokens)
**Action 2:** Void authorization (refunds 10 tokens)
**Expected:** Net balance unchanged

```sql
SELECT * FROM wallet_ledger WHERE user_id = 123 ORDER BY occurred_at DESC;
-- Should show:
-- 1. debit 10 (chapter_generation)
-- 2. credit 10 (refund_generation_failure)
```

#### Test 4: Hold Expiry

**Setup:** Create authorization
**Action:** Wait 10 minutes (or manually set `hold_expires_at` to past)
**Expected:** Capture returns 409 CONFLICT (expired)

```bash
# Run cron job manually
php cron/expire_holds.php

# Verify authorization status updated to 'expired'
# Attempt capture should return 409
```

#### Test 5: Idempotency

**Setup:** Create authorization, capture it
**Action:** Capture again with same idempotency key
**Expected:** Returns original result, no double-debit

```bash
# Both captures return same transaction_id
# Wallet ledger has only ONE debit entry
```

---

## Deployment

### Step 1: Run Migration

```bash
mysql -u username -p database < migrations/002_token_authorizations.sql
```

### Step 2: Verify Tables

```sql
SHOW TABLES LIKE 'token_authorizations';
DESC token_authorizations;
```

### Step 3: Upload Files

Upload these new/modified files:

**New Libraries:**
- `lib/authorizations.php`
- `lib/pricebook.php`

**New Endpoints:**
- `v1/wallet/pricebook.php`
- `v1/tokens/deduct.php`
- `v1/tokens/authorizations/create.php`
- `v1/tokens/authorizations/capture.php`
- `v1/tokens/authorizations/void.php`

**Cron Job:**
- `cron/expire_holds.php`

**Modified:**
- `.htaccess` (URL rewriting rules)

### Step 4: Setup Cron Job

```bash
# SSH into server
ssh user@your-domain.com

# Edit crontab
crontab -e

# Add hold expiry job (runs every minute)
* * * * * /usr/bin/php /path/to/api_prompt/cron/expire_holds.php >> /path/to/logs/expire_holds.log 2>&1

# Create log directory if needed
mkdir -p /path/to/logs
```

### Step 5: Test Endpoints

Test each endpoint according to the manual testing section above.

### Step 6: Monitor Logs

```bash
# Watch cron job logs
tail -f /path/to/logs/expire_holds.log

# Check PHP error logs
tail -f /path/to/php_errors.log
```

---

## Troubleshooting

### Issue: "Feature not found in pricebook"

**Cause:** Feature name doesn't exist in `PRICEBOOK` constant

**Solution:** Add feature to `lib/pricebook.php`:

```php
const PRICEBOOK = [
    'your_feature' => [
        'unit_cost' => 10,
        'token_type' => 'regular',
        'currency_hint' => '₹',
        'description' => 'Your feature description'
    ],
    // ...
];
```

### Issue: Cron job not running

**Solution:** Check cron logs:

```bash
# View cron execution logs
grep CRON /var/log/syslog

# Check PHP path
which php
# Use full path in crontab

# Test manually
php /path/to/cron/expire_holds.php
```

### Issue: "Authorization cannot be captured (expired)"

**Cause:** More than 10 minutes passed since hold creation

**Solution:** Increase hold expiry time in `lib/authorizations.php`:

```php
define('HOLD_EXPIRY_MINUTES', 15); // Change from 10 to 15
```

### Issue: Double deduction on retry

**Cause:** Missing or different `X-Idempotency-Key` header

**Solution:** Always include same idempotency key for retries:

```bash
# Good (same key for retries)
X-Idempotency-Key: capture-auth-123

# Bad (different key each time)
X-Idempotency-Key: $(uuidgen)  # Don't do this!
```

---

## Next Steps (Phase 3+)

Future enhancements:

- [ ] Promo token deduction (currently only regular tokens)
- [ ] Authorization analytics dashboard
- [ ] Webhook notifications for authorization events
- [ ] Partial captures (capture less than held amount)
- [ ] Batch authorization operations
- [ ] Authorization history endpoint

---

**Implementation Date:** 2025-11-08
**API Version:** 1.0
**Phase:** 2 (Token Deduction)
