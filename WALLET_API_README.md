# Wallet API Implementation - Phase 1

This document describes the implementation of the **Wallet System API** according to the provided specification.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Setup](#database-setup)
4. [API Endpoints](#api-endpoints)
5. [Standard Headers](#standard-headers)
6. [Error Handling](#error-handling)
7. [Idempotency](#idempotency)
8. [Events](#events)
9. [Testing](#testing)
10. [Deployment](#deployment)

---

## Overview

The Wallet API provides a ledger-based token management system with:

- **Append-only ledger** for all transactions
- **Dual token types**: regular and promo
- **Balance caching** for performance
- **Idempotency** for safe retries
- **Event publishing** for notifications
- **RBAC** with role-based permissions

### Key Features

- ✅ Cursor-based pagination
- ✅ Uniform error format
- ✅ Standard request headers (X-Request-Id, X-Idempotency-Key, X-Source)
- ✅ JWT authentication with scopes
- ✅ ULID-based ledger IDs
- ✅ Database triggers for automatic balance updates

---

## Architecture

### Tech Stack

- **Language**: PHP 7.4+ (strict types)
- **Database**: MySQL 5.7+ with PDO
- **Authentication**: JWT (HS256)
- **ID Generation**: ULID (26-char, sortable)
- **Caching**: Balance cache table with triggers

### Directory Structure

```
api_prompt/
├── migrations/
│   └── 001_wallet_system.sql          # Database schema
├── lib/
│   ├── errors.php                     # Uniform error responses
│   ├── headers.php                    # Standard headers validation
│   ├── wallet.php                     # Wallet service (core logic)
│   ├── idempotency.php                # Idempotency handling
│   ├── events.php                     # Event publishing
│   └── ulid.php                       # ULID generator
├── v1/
│   ├── wallet/
│   │   ├── me.php                     # GET /api/v1/wallet/me
│   │   └── transactions.php           # GET /api/v1/wallet/me/transactions
│   └── admin/
│       └── users/
│           ├── wallet.php             # GET /api/v1/admin/users/wallet?userId=X
│           ├── transactions.php       # GET /api/v1/admin/users/transactions?userId=X
│           └── seed.php               # POST /api/v1/admin/users/seed?userId=X
└── .htaccess                          # URL rewriting rules
```

---

## Database Setup

### Step 1: Run Migration

Execute the migration script to create the required tables:

```bash
mysql -u [username] -p [database_name] < migrations/001_wallet_system.sql
```

Or via phpMyAdmin, import the SQL file.

### Step 2: Verify Tables

The migration creates three tables:

1. **`wallet_ledger`** - Append-only transaction log
2. **`wallet_balance_cache`** - Cached balances (updated via trigger)
3. **`idempotency_keys`** - Prevents duplicate operations

### Step 3: Verify Trigger

The trigger `trg_wallet_ledger_after_insert` automatically updates the balance cache when new ledger entries are inserted.

### Schema Details

#### wallet_ledger

| Column                  | Type       | Description                          |
|-------------------------|------------|--------------------------------------|
| id                      | VARCHAR(26)| ULID primary key                     |
| user_id                 | INT        | Foreign key to users.id              |
| token_type              | ENUM       | 'regular' or 'promo'                 |
| direction               | ENUM       | 'credit' or 'debit'                  |
| reason                  | ENUM       | Transaction reason (see below)       |
| amount                  | INT        | Token amount (always positive)       |
| balance_after_regular   | INT        | Regular balance after transaction    |
| balance_after_promo     | INT        | Promo balance after transaction      |
| occurred_at             | TIMESTAMP  | Transaction timestamp                |
| reference_id            | VARCHAR(64)| Optional reference (chapter_id, etc.)|
| metadata                | JSON       | Additional metadata                  |
| idempotency_key         | VARCHAR(128)| Unique key for deduplication        |

**Reasons Enum:**
- `registration_bonus`
- `chapter_generation`
- `refund_generation_failure`
- `token_purchase`
- `referral_bonus`
- `promo_expiry`
- `admin_adjustment`
- `migration_correction`

#### wallet_balance_cache

| Column          | Type      | Description                  |
|-----------------|-----------|------------------------------|
| user_id         | INT       | Primary key, FK to users.id  |
| regular_balance | INT       | Cached regular token balance |
| promo_balance   | INT       | Cached promo token balance   |
| updated_at      | TIMESTAMP | Last update time             |

#### idempotency_keys

| Column          | Type         | Description                     |
|-----------------|--------------|---------------------------------|
| id              | INT          | Auto-increment primary key      |
| user_id         | INT (NULL)   | User ID (nullable for webhooks) |
| operation       | VARCHAR(64)  | Operation identifier            |
| resource_key    | VARCHAR(128) | Resource identifier             |
| idempotency_key | VARCHAR(128) | Idempotency key from request    |
| response_hash   | CHAR(64)     | SHA256 of response              |
| status_code     | SMALLINT     | HTTP status code                |
| created_at      | TIMESTAMP    | Record creation time            |

---

## API Endpoints

### Base URL

All endpoints are prefixed with `/api/v1`

### Standard Headers

All requests require:

| Header            | Required | Description                              |
|-------------------|----------|------------------------------------------|
| Authorization     | Yes      | `Bearer <JWT>`                           |
| X-Request-Id      | Yes      | UUID v4 (for tracing)                    |
| X-Idempotency-Key | Mutating | 1-128 chars (required for POST/PUT/DELETE)|
| X-Source          | No       | `web`, `admin`, `service`, or `mobile`   |

### User Endpoints

#### GET /api/v1/wallet/me

Get current user's wallet balance.

**Scopes Required:** `wallet:read`

**Response:**
```json
{
  "balances": {
    "regular": 250,
    "promo": 0,
    "total": 250
  },
  "updated_at": "2025-11-08T12:30:00+00:00",
  "split": {
    "regular": 250,
    "promo": 0
  }
}
```

**Caching:**
- ETag support for efficient caching
- Max-age: 3 seconds

---

#### GET /api/v1/wallet/me/transactions

Get current user's transaction history with cursor pagination.

**Scopes Required:** `wallet:read`

**Query Parameters:**
- `limit` (optional, default 25, max 100)
- `cursor` (optional, opaque cursor from previous response)

**Response:**
```json
{
  "items": [
    {
      "id": "01JDKX3G8M2QWERTY9ABCD1234",
      "occurred_at": "2025-11-08T12:30:00+00:00",
      "type": "credit",
      "token_type": "regular",
      "reason": "registration_bonus",
      "amount": 250,
      "balance_after": {
        "regular": 250,
        "promo": 0,
        "total": 250
      },
      "metadata": {}
    }
  ],
  "next_cursor": "eyJ0IjoiMjAyNS0xMS0wOCAxMjozMDowMCIsImlkIjoiMDFKREtYM0c4TTJRIn0="
}
```

---

### Admin Endpoints

#### GET /api/v1/admin/users/wallet

Get wallet balance for a specific user (admin only).

**Scopes Required:** `wallet:admin`

**Query Parameters:**
- `userId` (required) - User ID

**Response:** Same as `/wallet/me`

---

#### GET /api/v1/admin/users/transactions

Get transaction history for a specific user (admin only).

**Scopes Required:** `wallet:admin`

**Query Parameters:**
- `userId` (required)
- `limit` (optional)
- `cursor` (optional)

**Response:** Same as `/wallet/me/transactions`

---

#### POST /api/v1/admin/users/seed

Manually seed a user's wallet with registration bonus (admin only).

**Scopes Required:** `wallet:admin`

**Query Parameters:**
- `userId` (required)

**Request Body:**
```json
{
  "amount": 250
}
```

**Validations:**
- Amount must equal 250 (configured seed amount)
- User must not already have a `registration_bonus`
- Requires `X-Idempotency-Key` header

**Success Response (201):**
```json
{
  "message": "Wallet seeded successfully",
  "data": {
    "user_id": 123,
    "amount": 250,
    "balances": {
      "regular": 250,
      "promo": 0,
      "total": 250
    },
    "entry_id": "01JDKX3G8M2QWERTY9ABCD1234"
  }
}
```

**Error Response (409 Conflict):**
```json
{
  "error": {
    "code": "409_CONFLICT",
    "message": "User already has registration bonus",
    "details": {
      "user_id": 123,
      "reason": "registration_bonus already exists"
    }
  }
}
```

---

## Standard Headers

### X-Request-Id

**Format:** UUID v4

**Example:** `550e8400-e29b-41d4-a716-446655440000`

**Purpose:** Request tracing and debugging

**Validation:** Must be a valid UUID v4 format

---

### X-Idempotency-Key

**Format:** String (1-128 characters)

**Example:** `checkout-123-retry-1`

**Purpose:** Prevents duplicate operations

**Required for:** All mutating operations (POST, PUT, DELETE)

**Behavior:**
- On first request: Operation executes normally
- On duplicate: Returns original response with 409 Conflict

---

### X-Source

**Format:** Enum (`web`, `admin`, `service`, `mobile`)

**Example:** `web`

**Purpose:** Track request origin

**Optional:** Defaults to `web` if not provided

---

## Error Handling

All errors follow a uniform format:

```json
{
  "error": {
    "code": "422_BUSINESS_RULE",
    "message": "Insufficient tokens",
    "details": {
      "required": 100,
      "available": 50
    }
  }
}
```

### Error Codes

| Code                      | HTTP Status | Description                    |
|---------------------------|-------------|--------------------------------|
| 400_INVALID_INPUT         | 400         | Validation failed              |
| 401_UNAUTHENTICATED       | 401         | Missing/invalid JWT            |
| 403_FORBIDDEN             | 403         | Insufficient permissions       |
| 404_NOT_FOUND             | 404         | Resource not found             |
| 409_CONFLICT              | 409         | Duplicate operation            |
| 422_BUSINESS_RULE         | 422         | Business logic violation       |
| 429_RATE_LIMIT            | 429         | Rate limit exceeded            |
| 500_SERVER_ERROR          | 500         | Internal server error          |
| 503_UPSTREAM_UNAVAILABLE  | 503         | External service unavailable   |

---

## Idempotency

### How It Works

1. Client sends `X-Idempotency-Key` header with request
2. System checks `idempotency_keys` table for existing record
3. If found: Returns 409 Conflict with original response metadata
4. If not found: Executes operation and stores key with response

### Operation Identifiers

| Operation       | Description                |
|-----------------|----------------------------|
| WALLET_SEED     | Registration bonus seeding |
| TOKENS_DEDUCT   | Token deduction            |
| TOKENS_CREDIT   | Token credit               |
| PURCHASE_CREATE | Purchase creation          |

### Resource Key Format

- **User wallet**: `user:{userId}`
- **Chapter**: `sha256(user_id + prompt + subject + grade + page_goal)`
- **Purchase**: `purchase:{purchaseId}`

---

## Events

Events are published for real-time notifications and integrations.

### Event Types

#### wallet.updated

Published when wallet balance changes.

**Payload:**
```json
{
  "user_id": 123,
  "balances": {
    "regular": 250,
    "promo": 0,
    "total": 250
  },
  "reason": "registration_bonus",
  "delta": 250,
  "occurred_at": "2025-11-08T12:30:00+00:00"
}
```

#### purchase.succeeded

Published when token purchase completes.

**Payload:**
```json
{
  "user_id": 123,
  "purchase_id": "px_abc123",
  "tokens": 500,
  "inr": 99.00,
  "provider_ref": "razorpay_abc123"
}
```

#### promo.expiry_upcoming

Published when promo tokens are about to expire.

**Payload:**
```json
{
  "user_id": 123,
  "expiring_tokens": 100,
  "expiry_date": "2025-12-31T23:59:59+00:00"
}
```

#### promo.expired

Published when promo tokens expire.

**Payload:**
```json
{
  "user_id": 123,
  "expired_tokens": 100,
  "run_id": "expiry_job_20251108"
}
```

### Event Storage

Events are stored in the `events` table (auto-created on first event).

To consume events, query:
```php
$events = get_unprocessed_events($pdo, 'wallet.updated', 100);
foreach ($events as $event) {
    // Process event
    mark_event_processed($pdo, $event['id']);
}
```

---

## Testing

### Manual Testing with cURL

#### 1. Get Wallet Balance

```bash
curl -X GET "http://your-domain.com/api/v1/wallet/me" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Source: web"
```

#### 2. Get Transactions

```bash
curl -X GET "http://your-domain.com/api/v1/wallet/me/transactions?limit=10" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Source: web"
```

#### 3. Admin Seed Wallet

```bash
curl -X POST "http://your-domain.com/api/v1/admin/users/seed?userId=123" \
  -H "Authorization: Bearer ADMIN_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: seed-user-123-$(date +%s)" \
  -H "X-Source: admin" \
  -H "Content-Type: application/json" \
  -d '{"amount": 250}'
```

### Acceptance Tests (Phase 1)

✅ **Test 1: Registration Bonus**
- Register new user → Exactly one ledger entry with `registration_bonus:250`
- Verify `wallet_balance_cache` has 250 regular tokens

✅ **Test 2: Fetch Wallet**
- GET `/wallet/me` returns `{ regular:250, promo:0, total:250 }`

✅ **Test 3: Transactions**
- GET `/wallet/me/transactions` shows correct sign mapping (credit = positive)

✅ **Test 4: RBAC**
- User cannot call admin routes (403 Forbidden)

✅ **Test 5: Idempotency**
- POST seed twice with same key → Second returns 409 Conflict

---

## Deployment

### Prerequisites

1. PHP 7.4+ with extensions:
   - PDO
   - GMP (for ULID generation)
   - JSON

2. MySQL 5.7+ with:
   - TRIGGER support
   - JSON column support
   - ENUM support

### Deployment Steps

1. **Upload files** to server via FTP/cPanel

2. **Run database migration**:
   ```bash
   mysql -u username -p database < migrations/001_wallet_system.sql
   ```

3. **Update config.php** (if needed):
   ```php
   define('WALLET_SEED_AMOUNT', 250);
   ```

4. **Verify .htaccess** rules are active:
   ```bash
   # Test URL rewriting
   curl http://your-domain.com/api/v1/wallet/me
   ```

5. **Test authentication**:
   - Register a new user
   - Verify 250 tokens in wallet
   - Test `/wallet/me` endpoint

6. **Monitor logs**:
   - Check PHP error logs
   - Monitor event table for published events

### Rollback

To rollback the migration:

```sql
DROP TRIGGER IF EXISTS `trg_wallet_ledger_after_insert`;
DROP TABLE IF EXISTS `idempotency_keys`;
DROP TABLE IF EXISTS `wallet_balance_cache`;
DROP TABLE IF EXISTS `wallet_ledger`;
```

---

## Troubleshooting

### Issue: "X-Request-Id header is required"

**Solution:** Ensure all requests include the `X-Request-Id` header with a valid UUID v4.

### Issue: "Idempotency key required"

**Solution:** Add `X-Idempotency-Key` header to all POST/PUT/DELETE requests.

### Issue: Balance cache not updating

**Solution:** Verify trigger exists:
```sql
SHOW TRIGGERS LIKE 'wallet_ledger';
```

### Issue: ULID generation fails

**Solution:** Install GMP extension:
```bash
# Ubuntu/Debian
sudo apt-get install php-gmp

# CentOS/RHEL
sudo yum install php-gmp
```

### Issue: 403 Forbidden on admin routes

**Solution:** Ensure JWT has `"role": "admin"` claim.

---

## Next Steps (Phase 2+)

Future enhancements not included in Phase 1:

- [ ] Token purchases via payment gateway
- [ ] Promo code system
- [ ] Token expiry automation
- [ ] Referral bonus system
- [ ] Analytics dashboard
- [ ] Webhook integrations
- [ ] Rate limiting enforcement
- [ ] WebSocket notifications for events

---

## Support

For issues or questions, refer to:
- Database migration: `migrations/001_wallet_system.sql`
- API specification: Original requirements document
- Code comments: Inline documentation in all PHP files

---

**Implementation Date:** 2025-11-08
**API Version:** 1.0
**Phase:** 1 (Foundation)
