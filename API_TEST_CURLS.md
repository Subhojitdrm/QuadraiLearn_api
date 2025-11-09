# Wallet & Token API - cURL Test Commands

## Base Configuration

```bash
# Set your base URL
export BASE_URL="https://quadrailearn.quadravise.com/api"
# Or for local testing:
# export BASE_URL="http://localhost/api"

# Generate unique request ID and idempotency key
export REQUEST_ID=$(uuidgen)
export IDEMPOTENCY_KEY=$(uuidgen)
```

---

## 1. Authentication

### Register a New User
```bash
curl -X POST "${BASE_URL}/auth/register" \
  -H "Content-Type: application/json" \
  -d '{
    "firstName": "John",
    "lastName": "Doe",
    "username": "johndoe123",
    "email": "john.doe@example.com",
    "password": "SecurePass123!",
    "confirmPassword": "SecurePass123!",
    "interestedAreas": ["AI", "Mathematics"],
    "primaryStudyNeed": "Exam Preparation"
  }'
```

**Expected Response:**
```json
{
  "ok": true,
  "message": "Registration successful, and 250 tokens awarded.",
  "user": {
    "id": 1,
    "firstName": "John",
    "lastName": "Doe",
    "username": "johndoe123",
    "email": "john.doe@example.com"
  }
}
```

### Login
```bash
curl -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "identifier": "john.doe@example.com",
    "password": "SecurePass123!"
  }'
```

**Expected Response:**
```json
{
  "ok": true,
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 1,
    "username": "johndoe123",
    "email": "john.doe@example.com"
  }
}
```

**Save the token:**
```bash
export TOKEN="eyJ0eXAiOiJKV1QiLCJhbGc..."
```

---

## 2. Wallet APIs

### Get Wallet Balance
```bash
curl -X GET "${BASE_URL}/v1/wallet/me" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

**Expected Response:**
```json
{
  "balances": {
    "regular": 250,
    "promo": 0,
    "total": 250
  },
  "updated_at": "2025-11-09T12:30:00+00:00",
  "split": {
    "regular": 250,
    "promo": 0
  }
}
```

### Get Transaction History
```bash
# Get first 10 transactions
curl -X GET "${BASE_URL}/v1/wallet/me/transactions?limit=10" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"

# With pagination cursor
curl -X GET "${BASE_URL}/v1/wallet/me/transactions?limit=10&cursor=abc123" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

**Expected Response:**
```json
{
  "items": [
    {
      "id": "01JBWXYZ123ABC",
      "occurred_at": "2025-11-09T12:30:00+00:00",
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
  "next_cursor": "opaque_cursor_string"
}
```

### Get Pricebook (Token Pricing)
```bash
curl -X GET "${BASE_URL}/v1/wallet/pricebook" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

**Expected Response:**
```json
{
  "tiers": [
    {
      "tokens": 100,
      "inr_amount": 300,
      "per_token_cost": 3.00,
      "savings_percent": 0
    },
    {
      "tokens": 500,
      "inr_amount": 1400,
      "per_token_cost": 2.80,
      "savings_percent": 6.67
    }
  ]
}
```

---

## 3. Token Authorization APIs (Hold-Then-Capture Pattern)

### Create Authorization (HOLD tokens)
```bash
curl -X POST "${BASE_URL}/v1/tokens/authorizations" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "feature": "chapter_generation",
    "units": 1,
    "cost_per_unit": 10,
    "resource_key": "chapter_hash_abc123",
    "metadata": {
      "subject": "Mathematics",
      "grade": "VIII",
      "chapter_title": "Algebra Basics"
    }
  }'
```

**Expected Response:**
```json
{
  "authorization_id": "01JBWXYZ789DEF",
  "status": "held",
  "held_amount": 10,
  "hold_expires_at": "2025-11-09T12:45:00+00:00",
  "balance_preview": {
    "regular": 240,
    "promo": 0,
    "total": 240
  }
}
```

**Save authorization_id:**
```bash
export AUTH_ID="01JBWXYZ789DEF"
```

### Capture Authorization (DEBIT tokens)
```bash
curl -X POST "${BASE_URL}/v1/tokens/authorizations/capture?authorization_id=${AUTH_ID}" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "result_id": "chapter_123",
    "status_from_upstream": "success"
  }'
```

**Expected Response:**
```json
{
  "status": "captured",
  "debited": 10,
  "balances": {
    "regular": 240,
    "promo": 0,
    "total": 240
  },
  "transaction_id": "01JBWXYZABC456"
}
```

### Void Authorization (RELEASE hold)
```bash
curl -X POST "${BASE_URL}/v1/tokens/authorizations/void?authorization_id=${AUTH_ID}" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "reason": "generation_failed"
  }'
```

**Expected Response:**
```json
{
  "status": "voided",
  "refunded": 10,
  "balances": {
    "regular": 250,
    "promo": 0,
    "total": 250
  },
  "transaction_id": "01JBWXYZCBA987"
}
```

---

## 4. Token Purchase APIs

### Create Purchase
```bash
curl -X POST "${BASE_URL}/v1/wallet/me/purchases" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "tokens": 250,
    "provider": "razorpay"
  }'
```

**Expected Response:**
```json
{
  "purchase_id": "01JBWXYZPURCHASE",
  "status": "created",
  "tokens": 250,
  "inr_amount": 750,
  "provider": "razorpay",
  "provider_payload": {
    "order_id": "order_9A33XWu170gUtm",
    "amount": 75000,
    "currency": "INR",
    "key": "rzp_test_xxxxxx"
  }
}
```

**Save purchase_id:**
```bash
export PURCHASE_ID="01JBWXYZPURCHASE"
```

### Check Purchase Status
```bash
curl -X GET "${BASE_URL}/v1/wallet/me/purchases/${PURCHASE_ID}" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

**Expected Response:**
```json
{
  "purchase_id": "01JBWXYZPURCHASE",
  "status": "paid",
  "tokens": 250,
  "inr_amount": 750,
  "provider": "razorpay",
  "receipt_no": "RCPT-2025-001",
  "created_at": "2025-11-09T12:00:00+00:00",
  "completed_at": "2025-11-09T12:05:00+00:00"
}
```

---

## 5. Receipt APIs

### List Receipts
```bash
curl -X GET "${BASE_URL}/v1/wallet/me/receipts?limit=10" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

**Expected Response:**
```json
{
  "items": [
    {
      "receipt_no": "RCPT-2025-001",
      "purchase_id": "01JBWXYZPURCHASE",
      "tokens": 250,
      "inr_amount": 750,
      "date": "2025-11-09T12:05:00+00:00",
      "status": "paid"
    }
  ],
  "next_cursor": null
}
```

### View Receipt Details
```bash
export RECEIPT_NO="RCPT-2025-001"

curl -X GET "${BASE_URL}/v1/wallet/me/receipts/${RECEIPT_NO}" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

**Expected Response:**
```json
{
  "receipt_no": "RCPT-2025-001",
  "purchase_id": "01JBWXYZPURCHASE",
  "user": {
    "name": "John Doe",
    "email": "john.doe@example.com"
  },
  "tokens": 250,
  "inr_amount": 750,
  "provider": "razorpay",
  "provider_payment_id": "pay_ABC123XYZ",
  "date": "2025-11-09T12:05:00+00:00",
  "status": "paid"
}
```

---

## 6. Referral APIs

### Generate Referral Link
```bash
curl -X POST "${BASE_URL}/v1/wallet/me/referrals/link" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "campaign_id": "01JBCAMPAIGN123"
  }'
```

**Expected Response:**
```json
{
  "referral_code": "JOHN123ABC",
  "referral_link": "https://quadrailearn.quadravise.com/signup?ref=JOHN123ABC",
  "campaign": {
    "name": "Friend Referral Bonus",
    "bonus_amount": 50,
    "token_type": "promo"
  }
}
```

---

## 7. Direct Token Deduction (Legacy/Simple)

### Deduct Tokens (Simple method without authorization)
```bash
curl -X POST "${BASE_URL}/v1/tokens/deduct" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "amount": 10,
    "reason": "chapter_generation",
    "reference_id": "chapter_456",
    "metadata": {
      "chapter_title": "Linear Equations"
    }
  }'
```

**Expected Response:**
```json
{
  "debited": 10,
  "balances": {
    "regular": 240,
    "promo": 0,
    "total": 240
  },
  "transaction_id": "01JBWXYZDEDUCT1"
}
```

---

## 8. Admin Analytics APIs

### Get Token Overview
```bash
curl -X GET "${BASE_URL}/v1/admin/analytics/tokens/overview?from=2025-11-01&to=2025-11-09" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

### Get Token Composition
```bash
curl -X GET "${BASE_URL}/v1/admin/analytics/tokens/composition" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

### Get Token Trend
```bash
curl -X GET "${BASE_URL}/v1/admin/analytics/tokens/trend?period=7d" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

### Get Tokens by Feature
```bash
curl -X GET "${BASE_URL}/v1/admin/analytics/tokens/by-feature?from=2025-11-01&to=2025-11-09" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "X-Request-Id: ${REQUEST_ID}" \
  -H "X-Source: api-test"
```

---

## Testing Workflow Example

### Complete Token Authorization Flow
```bash
#!/bin/bash

# 1. Login and get token
TOKEN=$(curl -s -X POST "${BASE_URL}/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"identifier":"john.doe@example.com","password":"SecurePass123!"}' \
  | jq -r '.token')

echo "Token: ${TOKEN}"

# 2. Check initial balance
echo -e "\n=== Initial Balance ==="
curl -s -X GET "${BASE_URL}/v1/wallet/me" \
  -H "Authorization: Bearer ${TOKEN}" \
  | jq '.'

# 3. Create authorization (hold tokens)
echo -e "\n=== Create Authorization ==="
AUTH_RESPONSE=$(curl -s -X POST "${BASE_URL}/v1/tokens/authorizations" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "feature": "chapter_generation",
    "units": 1,
    "cost_per_unit": 10,
    "resource_key": "test_chapter_001",
    "metadata": {"subject": "Math"}
  }')

echo "$AUTH_RESPONSE" | jq '.'
AUTH_ID=$(echo "$AUTH_RESPONSE" | jq -r '.authorization_id')

# 4. Capture authorization (debit tokens)
echo -e "\n=== Capture Authorization ==="
curl -s -X POST "${BASE_URL}/v1/tokens/authorizations/capture?authorization_id=${AUTH_ID}" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -H "X-Source: api-test" \
  -d '{
    "result_id": "chapter_final_001",
    "status_from_upstream": "success"
  }' | jq '.'

# 5. Check final balance
echo -e "\n=== Final Balance ==="
curl -s -X GET "${BASE_URL}/v1/wallet/me" \
  -H "Authorization: Bearer ${TOKEN}" \
  | jq '.'

# 6. View transaction history
echo -e "\n=== Transaction History ==="
curl -s -X GET "${BASE_URL}/v1/wallet/me/transactions?limit=5" \
  -H "Authorization: Bearer ${TOKEN}" \
  | jq '.'
```

---

## Error Testing

### Test Invalid Authorization
```bash
curl -X GET "${BASE_URL}/v1/wallet/me" \
  -H "Authorization: Bearer invalid_token_here" \
  -H "X-Request-Id: ${REQUEST_ID}"
```

**Expected Response (401):**
```json
{
  "error": "Unauthorized",
  "message": "Invalid or expired token"
}
```

### Test Missing Idempotency Key
```bash
curl -X POST "${BASE_URL}/v1/tokens/authorizations" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -d '{
    "feature": "chapter_generation",
    "units": 1,
    "resource_key": "test"
  }'
```

**Expected Response (400):**
```json
{
  "error": "Bad Request",
  "message": "X-Idempotency-Key header is required"
}
```

### Test Insufficient Balance
```bash
curl -X POST "${BASE_URL}/v1/tokens/deduct" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Request-Id: $(uuidgen)" \
  -H "X-Idempotency-Key: $(uuidgen)" \
  -d '{
    "amount": 99999,
    "reason": "test"
  }'
```

**Expected Response (400):**
```json
{
  "error": "Insufficient Balance",
  "message": "Not enough tokens available"
}
```

---

## Postman Collection

You can also import these into Postman. Save this as `wallet-token-api.postman_collection.json`:

```json
{
  "info": {
    "name": "Wallet & Token API",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "variable": [
    {
      "key": "base_url",
      "value": "https://quadrailearn.quadravise.com/api"
    },
    {
      "key": "token",
      "value": ""
    }
  ]
}
```

---

## Notes

1. **Request-Id**: Generate unique UUID for each request for tracing
2. **Idempotency-Key**: Required for all POST/PUT/DELETE operations to prevent duplicate actions
3. **Authorization**: Use Bearer token from login response
4. **X-Source**: Optional header to identify request source (e.g., "mobile-app", "web", "api-test")

All timestamps are in ISO 8601 format (UTC).
