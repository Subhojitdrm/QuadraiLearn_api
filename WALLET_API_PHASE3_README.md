# Wallet API - Phase 3: Token Purchase (Recharge)

This document describes Phase 3 of the Wallet API implementation, which adds **token purchase functionality** with payment provider integration (Razorpay, Stripe).

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Changes](#database-changes)
4. [Pricing Configuration](#pricing-configuration)
5. [Purchase Flow](#purchase-flow)
6. [API Endpoints](#api-endpoints)
7. [Webhook Integration](#webhook-integration)
8. [Security](#security)
9. [Testing](#testing)
10. [Deployment](#deployment)

---

## Overview

Phase 3 implements a complete **token purchase system** with payment gateway integration:

1. **Create Purchase Intent**: User initiates token purchase
2. **Provider Order**: System creates order with payment provider (Razorpay/Stripe)
3. **User Payment**: User completes payment through provider
4. **Webhook Notification**: Provider notifies our system
5. **Token Credit**: System credits tokens to wallet
6. **Receipt Generation**: Unique receipt number generated

### Key Features

✅ **Multi-provider support** (Razorpay, Stripe)
✅ **Server-side pricing** (prevents client manipulation)
✅ **Webhook signature verification** for security
✅ **Idempotent webhook processing** (duplicate-safe)
✅ **Automatic receipt generation** (QL-YYYY-NNNNNN format)
✅ **Complete audit trail** (all webhooks logged)

---

## Architecture

### Purchase Flow Diagram

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐     ┌──────────────┐
│   CLIENT    │     │   BACKEND    │     │   PROVIDER  │     │   WALLET     │
└─────────────┘     └──────────────┘     └─────────────┘     └──────────────┘
       │                    │                    │                    │
       │  1. Create Purchase│                    │                    │
       ├───────────────────>│                    │                    │
       │  (250 tokens)      │  2. Create Order   │                    │
       │                    ├───────────────────>│                    │
       │                    │  { order_id }      │                    │
       │                    │<───────────────────┤                    │
       │  { provider_data } │                    │                    │
       │<───────────────────┤                    │                    │
       │                    │                    │                    │
       │  3. Initiate Payment                    │                    │
       ├────────────────────────────────────────>│                    │
       │                    │                    │                    │
       │  4. Complete Payment                    │                    │
       │<────────────────────────────────────────┤                    │
       │                    │                    │                    │
       │                    │  5. Webhook (payment.captured)          │
       │                    │<───────────────────┤                    │
       │                    │  { order_id }      │                    │
       │                    │  6. Verify Signature                   │
       │                    │                    │                    │
       │                    │  7. Credit Tokens  │                    │
       │                    ├──────────────────────────────────────────>│
       │                    │  8. Generate Receipt                   │
       │                    │                    │                    │
       │  9. 200 OK         │                    │                    │
       │                    ├───────────────────>│                    │
```

### Tech Stack Additions

- **Payment Providers**: Razorpay, Stripe (extensible)
- **Webhook Security**: HMAC-SHA256 signature verification
- **Receipt System**: Auto-incrementing yearly receipts
- **Event Logging**: All webhooks logged for audit

---

## Database Changes

### New Tables

Run migration: `migrations/003_token_purchases.sql`

#### Table: purchases

| Column                 | Type       | Description                          |
|------------------------|------------|--------------------------------------|
| id                     | VARCHAR(26)| ULID primary key                     |
| user_id                | INT        | Foreign key to users.id              |
| status                 | ENUM       | created, pending, paid, failed, expired, refunded |
| tokens                 | INT        | Number of tokens to credit           |
| inr_amount             | INT        | Amount in paise (₹1 = 100 paise)     |
| provider               | VARCHAR(32)| Payment provider (razorpay, stripe)  |
| provider_order_id      | VARCHAR(128)| Provider order ID (unique)          |
| provider_payment_id    | VARCHAR(128)| Provider payment ID (set on success)|
| receipt_no             | VARCHAR(64)| Receipt number (unique, QL-YYYY-NNNNNN) |
| metadata               | JSON       | Additional metadata                  |
| created_at             | TIMESTAMP  | Record creation time                 |
| updated_at             | TIMESTAMP  | Last update time                     |
| idempotency_key        | VARCHAR(128)| Unique key for deduplication        |
| ledger_transaction_id  | VARCHAR(26)| Wallet ledger entry ID when credited|

**Status Flow:**
```
created → pending → paid (success)
       ↘         ↘ failed (error)
                 ↘ expired (timeout)
                 ↘ refunded (future feature)
```

#### Table: payment_webhook_events

| Column       | Type         | Description                          |
|--------------|--------------|--------------------------------------|
| id           | INT          | Auto-increment primary key           |
| provider     | VARCHAR(32)  | Payment provider name                |
| event_id     | VARCHAR(128) | Unique event ID from provider        |
| payload      | JSON         | Full webhook payload                 |
| processed_at | TIMESTAMP    | When event was processed             |
| status       | ENUM         | received, processed, skipped, error  |
| error_msg    | TEXT         | Error message if processing failed   |
| created_at   | TIMESTAMP    | Record creation time                 |

**Purpose:**
- Deduplication (prevent double-credit)
- Audit trail (compliance)
- Error debugging

---

## Pricing Configuration

### Token Pricing

Configured in `lib/purchases.php`:

```php
define('TOKEN_PRICE_PER_UNIT', 3);      // ₹3 per token
define('MIN_PURCHASE_TOKENS', 50);      // Minimum purchase
define('MAX_PURCHASE_TOKENS', 10000);   // Maximum purchase
```

### Calculation

```php
tokens = 250
price_per_token = ₹3
total_inr = 250 × 3 = ₹750
total_paise = 750 × 100 = 75000 paise
```

**Why paise?**
- Payment providers use paise (smallest currency unit)
- Avoids floating-point precision issues

---

## Purchase Flow

### Step-by-Step Flow

**1. Client: Create Purchase Intent**

```javascript
POST /api/v1/wallet/me/purchases
{
  "tokens": 250,
  "provider": "razorpay"
}
```

**2. Server: Create Provider Order**

```php
// Server creates Razorpay order
$api = new Razorpay\Api\Api(KEY_ID, KEY_SECRET);
$order = $api->order->create([
    'receipt' => $purchaseId,
    'amount' => 75000, // paise
    'currency' => 'INR'
]);
```

**3. Server: Return Order Data**

```json
{
  "purchase_id": "01JDKX...",
  "status": "created",
  "tokens": 250,
  "inr_amount": 75000,
  "provider": "razorpay",
  "provider_payload": {
    "order_id": "order_9A33XWu170gUtm",
    "amount": 75000,
    "currency": "INR"
  }
}
```

**4. Client: Initiate Payment**

```javascript
// Frontend initiates Razorpay checkout
const options = {
  key: 'rzp_live_YOUR_KEY',
  amount: 75000,
  currency: 'INR',
  order_id: 'order_9A33XWu170gUtm',
  handler: function(response) {
    // Payment successful, wait for webhook
    checkPurchaseStatus(purchaseId);
  }
};
const rzp = new Razorpay(options);
rzp.open();
```

**5. Provider: Send Webhook**

```
POST /api/v1/payments/webhook/razorpay
X-Razorpay-Signature: <hmac_signature>

{
  "event": "payment.captured",
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_abc123",
        "order_id": "order_9A33XWu170gUtm",
        "amount": 75000,
        "status": "captured"
      }
    }
  }
}
```

**6. Server: Process Webhook**

```php
// Verify signature
verify_razorpay_signature($payload, $signature, $secret);

// Find purchase by order_id
$purchase = find_by_order_id('order_9A33XWu170gUtm');

// Credit tokens (atomic transaction)
wallet_credit($userId, 250, 'regular', 'token_purchase');

// Generate receipt: QL-2025-000123
$receiptNo = generate_receipt_number($pdo);

// Update purchase status
update_purchase($purchaseId, 'paid', $receiptNo);
```

**7. Client: Poll Purchase Status**

```javascript
GET /api/v1/wallet/me/purchases/{purchase_id}

Response:
{
  "status": "paid",
  "tokens": 250,
  "inr_amount": 75000,
  "receipt_no": "QL-2025-000123"
}
```

---

## API Endpoints

### 1. POST /api/v1/wallet/me/purchases

Create a token purchase intent.

**Required Headers:**
- `X-Idempotency-Key` (required)
- `X-Request-Id` (required)

**Request Body:**
```json
{
  "tokens": 250,
  "provider": "razorpay"
}
```

**Validations:**
- `tokens`: Between 50 and 10,000
- `provider`: Must be `razorpay` or `stripe`

**Response (201 Created):**
```json
{
  "purchase_id": "01JDKX3G8M2QWERTY9ABCD1234",
  "status": "created",
  "tokens": 250,
  "inr_amount": 75000,
  "provider": "razorpay",
  "provider_payload": {
    "order_id": "order_9A33XWu170gUtm",
    "amount": 75000,
    "currency": "INR"
  }
}
```

**Error Responses:**

**422 - Invalid Token Amount:**
```json
{
  "error": {
    "code": "400_INVALID_INPUT",
    "message": "Invalid token amount",
    "details": {
      "tokens": "Must be between 50 and 10000"
    }
  }
}
```

---

### 2. GET /api/v1/wallet/me/purchases/{purchase_id}

Get purchase status.

**Response (200 OK):**
```json
{
  "purchase_id": "01JDKX3G8M2QWERTY9ABCD1234",
  "status": "paid",
  "tokens": 250,
  "inr_amount": 75000,
  "provider": "razorpay",
  "receipt_no": "QL-2025-000123",
  "created_at": "2025-11-08T12:25:00+00:00",
  "updated_at": "2025-11-08T12:30:00+00:00"
}
```

**Status Values:**
- `created`: Purchase created, awaiting payment
- `pending`: Payment initiated
- `paid`: Payment successful, tokens credited
- `failed`: Payment failed
- `expired`: Purchase expired (no payment)

---

### 3. POST /api/v1/payments/webhook/{provider}

Handle payment provider webhooks (server-to-server).

**URL Parameters:**
- `provider`: `razorpay` or `stripe`

**Headers:**
- `X-Razorpay-Signature` (for Razorpay)
- `Stripe-Signature` (for Stripe)

**Webhook Payload (Razorpay):**
```json
{
  "event": "payment.captured",
  "event_id": "evt_abc123",
  "payload": {
    "payment": {
      "entity": {
        "id": "pay_xyz789",
        "order_id": "order_9A33XWu170gUtm",
        "amount": 75000,
        "currency": "INR",
        "status": "captured"
      }
    }
  }
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "result": {
    "purchase_id": "01JDKX...",
    "receipt_no": "QL-2025-000123",
    "tokens_credited": 250,
    "new_balance": 500
  }
}
```

**Idempotency:**
- Duplicate webhooks return 200 with `already_processed: true`
- No double-credit occurs

**Security:**
- Signature verification (HMAC-SHA256)
- Event deduplication by `event_id`
- IP allowlist (recommended in production)

---

### 4. GET /api/v1/wallet/me/receipts

List user's receipts (paid purchases only).

**Query Parameters:**
- `limit` (optional, default 25, max 100)
- `cursor` (optional, for pagination)

**Response (200 OK):**
```json
{
  "items": [
    {
      "receipt_no": "QL-2025-000123",
      "purchase_id": "01JDKX...",
      "tokens": 250,
      "inr_amount": 75000,
      "paid_at": "2025-11-08T12:30:00+00:00"
    }
  ],
  "next_cursor": "opaque_cursor"
}
```

---

### 5. GET /api/v1/wallet/me/receipts/{receipt_no}

Get receipt details.

**Response (200 OK):**
```json
{
  "receipt_no": "QL-2025-000123",
  "purchase_id": "01JDKX...",
  "tokens": 250,
  "inr_amount": 75000,
  "provider": "razorpay",
  "provider_payment_id": "pay_xyz789",
  "paid_at": "2025-11-08T12:30:00+00:00",
  "created_at": "2025-11-08T12:25:00+00:00"
}
```

---

## Webhook Integration

### Razorpay Setup

**1. Get API Keys**

Login to Razorpay Dashboard → Settings → API Keys

- Key ID: `rzp_live_YOUR_KEY_ID`
- Key Secret: `YOUR_KEY_SECRET`

**2. Configure Webhook**

Dashboard → Settings → Webhooks → Add New Webhook

- **Webhook URL**: `https://your-domain.com/api/v1/payments/webhook/razorpay`
- **Secret**: Generate strong random secret
- **Events**: Select:
  - `payment.captured`
  - `payment.failed`
  - `order.paid`

**3. Update Config**

In `config.php`:
```php
const RAZORPAY_KEY_ID = 'rzp_live_YOUR_KEY_ID';
const RAZORPAY_KEY_SECRET = 'YOUR_WEBHOOK_SECRET';
```

### Stripe Setup

**1. Get API Keys**

Stripe Dashboard → Developers → API Keys

- Publishable key: `pk_live_...`
- Secret key: `sk_live_...`

**2. Configure Webhook**

Developers → Webhooks → Add Endpoint

- **Endpoint URL**: `https://your-domain.com/api/v1/payments/webhook/stripe`
- **Events**: Select:
  - `payment_intent.succeeded`
  - `payment_intent.payment_failed`
  - `charge.succeeded`
  - `charge.failed`

**3. Update Config**

```php
const STRIPE_KEY_ID = 'sk_live_YOUR_KEY';
const STRIPE_WEBHOOK_SECRET = 'whsec_YOUR_SECRET';
```

---

## Security

### 1. Webhook Signature Verification

**Razorpay:**
```php
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'];
$payload = file_get_contents('php://input');

$expectedSignature = hash_hmac('sha256', $payload, RAZORPAY_KEY_SECRET);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(400);
    exit('Invalid signature');
}
```

**Stripe:**
```php
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$payload = file_get_contents('php://input');

// Parse signature header
$signatureParts = parse_stripe_signature($signature);

$expectedSignature = hash_hmac('sha256',
    $signatureParts['t'] . '.' . $payload,
    STRIPE_WEBHOOK_SECRET
);

if (!hash_equals($expectedSignature, $signatureParts['v1'])) {
    http_response_code(400);
    exit('Invalid signature');
}
```

### 2. IP Allowlisting (Recommended)

**Razorpay IPs:**
```php
$allowedIPs = [
    '54.251.236.70',
    '54.251.236.71',
    // Add all Razorpay IPs
];

$clientIP = $_SERVER['REMOTE_ADDR'];
if (!in_array($clientIP, $allowedIPs)) {
    http_response_code(403);
    exit('Forbidden');
}
```

### 3. Event Deduplication

```php
// Check if event already processed
if (is_webhook_processed($pdo, 'razorpay', $eventId)) {
    http_response_code(200);
    echo json_encode(['message' => 'Already processed']);
    exit;
}
```

### 4. Server-Side Pricing

```php
// NEVER trust client-provided cost
$clientCost = $input['cost_per_unit']; // IGNORED

// Use server-side pricing
$serverCost = TOKEN_PRICE_PER_UNIT; // ₹3
$amount = $tokens * $serverCost * 100;
```

---

## Testing

### Manual Testing

#### 1. Create Purchase Intent

```bash
curl -X POST "http://your-domain.com/api/v1/wallet/me/purchases" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: purchase-test-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{
    "tokens": 250,
    "provider": "razorpay"
  }'
```

Save the `purchase_id` and `provider_payload.order_id`.

#### 2. Simulate Webhook (Razorpay)

```bash
# Generate signature
PAYLOAD='{"event":"payment.captured","event_id":"evt_test123","payload":{"payment":{"entity":{"id":"pay_test456","order_id":"order_9A33XWu170gUtm","amount":75000,"status":"captured"}}}}'

SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "YOUR_WEBHOOK_SECRET" | awk '{print $2}')

curl -X POST "http://your-domain.com/api/v1/payments/webhook/razorpay" \
  -H "X-Razorpay-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

#### 3. Check Purchase Status

```bash
curl -X GET "http://your-domain.com/api/v1/wallet/me/purchases/{purchase_id}" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)"
```

Should show `"status": "paid"` and `"receipt_no": "QL-2025-000123"`.

#### 4. Verify Wallet Balance

```bash
curl -X GET "http://your-domain.com/api/v1/wallet/me" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "X-Request-Id: $(uuidgen)"
```

Balance should be increased by 250 tokens.

### Acceptance Tests (Phase 3)

#### Test 1: Happy Path

**Setup:** User has 0 tokens
**Action:** Purchase 250 tokens, complete payment
**Expected:**
- Purchase status: `created` → `paid`
- Wallet balance: 0 → 250
- Receipt generated: `QL-2025-000001`
- Ledger entry: credit 250, reason `token_purchase`

#### Test 2: Under/Over-Payment Simulation

**Setup:** Create purchase for 250 tokens (₹750)
**Action:** Webhook shows `amount: 50000` (₹500, wrong amount)
**Expected:** Signature verification fails, no credit

#### Test 3: Duplicate Webhook

**Setup:** Purchase completed, tokens credited
**Action:** Send same webhook again (same `event_id`)
**Expected:**
- Webhook returns 200 OK
- Message: "Already processed"
- No double-credit in wallet

#### Test 4: Receipt Uniqueness

**Setup:** Complete 3 purchases on same day
**Action:** Check receipt numbers
**Expected:**
- `QL-2025-000001`
- `QL-2025-000002`
- `QL-2025-000003`

#### Test 5: Failed Payment

**Setup:** Create purchase
**Action:** Webhook with `event: payment.failed`
**Expected:**
- Purchase status: `failed`
- No tokens credited
- Wallet balance unchanged

---

## Deployment

### Step 1: Run Migration

```bash
mysql -u username -p database < migrations/003_token_purchases.sql
```

### Step 2: Configure Payment Provider

**For Razorpay:**

1. Login to Razorpay Dashboard
2. Generate API keys (Live mode)
3. Create webhook pointing to your domain
4. Copy webhook secret
5. Update `config.php`

**For Stripe:**

1. Login to Stripe Dashboard
2. Get API keys from Developers section
3. Create webhook endpoint
4. Copy webhook signing secret
5. Update `config.php`

### Step 3: Update Config

Edit `config.php`:

```php
const RAZORPAY_KEY_ID = 'rzp_live_YOUR_ACTUAL_KEY_ID';
const RAZORPAY_KEY_SECRET = 'YOUR_ACTUAL_WEBHOOK_SECRET';
```

### Step 4: Test Webhook Endpoint

```bash
# Check webhook endpoint is accessible
curl -X POST "https://your-domain.com/api/v1/payments/webhook/razorpay" \
  -H "Content-Type: application/json" \
  -d '{}'

# Should return 400 (missing signature), not 404
```

### Step 5: Test End-to-End

1. Create purchase intent via API
2. Complete payment using provider checkout
3. Verify webhook received and processed
4. Check wallet balance updated
5. Verify receipt generated

### Step 6: Monitor Logs

```bash
# Check webhook events table
mysql> SELECT * FROM payment_webhook_events ORDER BY created_at DESC LIMIT 10;

# Check for errors
mysql> SELECT * FROM payment_webhook_events WHERE status = 'error';
```

---

## Troubleshooting

### Issue: Webhook not received

**Cause:** Firewall, incorrect URL, or SSL issues

**Solution:**
1. Check webhook URL is publicly accessible
2. Ensure HTTPS (most providers require SSL)
3. Test with provider's webhook tester
4. Check firewall/security group rules

### Issue: "Invalid signature"

**Cause:** Wrong webhook secret or payload modification

**Solution:**
1. Verify webhook secret in config matches provider
2. Don't modify raw payload before verification
3. Check header name (`X-Razorpay-Signature` vs `Stripe-Signature`)

### Issue: Double credit on webhook retry

**Cause:** Idempotency not working

**Solution:**
1. Check `event_id` is being extracted correctly
2. Verify `payment_webhook_events` table has unique constraint on `event_id`
3. Check webhook handler uses `is_webhook_processed()`

### Issue: Receipt numbers not incrementing

**Cause:** Race condition in `generate_receipt_number()`

**Solution:**
1. Wrap receipt generation in transaction
2. Add database-level unique constraint on `receipt_no`
3. Retry on duplicate key error

### Issue: Provider order creation fails

**Cause:** Invalid API credentials or network error

**Solution:**
1. Verify API keys in config.php
2. Check provider SDK is installed (if using)
3. Test API connectivity with simple curl request
4. Check error logs for detailed message

---

## Next Steps (Future Enhancements)

- [ ] Refund support (credit reversal)
- [ ] Bulk purchase discounts
- [ ] Gift cards / voucher codes
- [ ] Payment method persistence
- [ ] Auto-recharge (subscription)
- [ ] Invoice PDF generation
- [ ] Email receipts
- [ ] Purchase analytics dashboard

---

**Implementation Date:** 2025-11-08
**API Version:** 1.0
**Phase:** 3 (Token Purchase)
