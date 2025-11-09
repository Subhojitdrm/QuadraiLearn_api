# Postman Collection Guide - Wallet & Token API

## 📦 Files Included

1. **`Wallet_Token_API.postman_collection.json`** - Complete API collection
2. **`Wallet_Token_API.postman_environment.json`** - Environment variables

---

## 🚀 Quick Setup

### Step 1: Import Collection

1. Open **Postman**
2. Click **"Import"** button (top left)
3. Click **"Upload Files"**
4. Select both files:
   - `Wallet_Token_API.postman_collection.json`
   - `Wallet_Token_API.postman_environment.json`
5. Click **"Import"**

### Step 2: Select Environment

1. Look for environment dropdown (top right)
2. Select **"Wallet & Token API - Production"**

### Step 3: Run Authentication

1. Open folder **"1. Authentication"**
2. Click **"Register User"** → Click **"Send"**
   - This will automatically save your `user_id`
3. Click **"Login"** → Update email/password → Click **"Send"**
   - This will automatically save your `access_token`

**✅ You're now ready to test all endpoints!**

---

## 📋 Collection Structure

### 1️⃣ Authentication
- **Register User** - Create new account (gets 250 free tokens)
- **Login** - Get JWT token (auto-saves to environment)
- **Logout** - End session

### 2️⃣ Wallet
- **Get Wallet Balance** - View regular + promo tokens
- **Get Transaction History** - View all transactions with pagination
- **Get Pricebook** - View token pricing tiers

### 3️⃣ Token Authorizations (Hold-Capture Pattern)
- **Create Authorization (HOLD)** - Reserve tokens for a feature
  - Auto-saves `authorization_id`
- **Capture Authorization (DEBIT)** - Actually deduct the tokens
  - Uses saved `authorization_id`
- **Void Authorization (RELEASE)** - Cancel and refund tokens
  - Uses saved `authorization_id`

### 4️⃣ Token Deduction (Simple)
- **Deduct Tokens** - Direct deduction without authorization

### 5️⃣ Purchases
- **Create Purchase** - Start token purchase (Razorpay/Stripe)
  - Auto-saves `purchase_id`
- **Get Purchase Status** - Check payment status

### 6️⃣ Receipts
- **List Receipts** - All purchase receipts
- **View Receipt** - Detailed receipt view

### 7️⃣ Referrals
- **Generate Referral Link** - Create referral code

### 8️⃣ Admin - Analytics
- **Token Overview** - Usage statistics
- **Token Composition** - Regular vs Promo breakdown
- **Token Trend** - Usage over time (7d, 30d, 90d)
- **Tokens by Feature** - Usage per feature
- **Create Analytics Export** - Export data (CSV/JSON)

### 9️⃣ Admin - User Management
- **Get User Wallet** - View any user's balance
- **Seed User Tokens** - Manually credit tokens

---

## 🔑 Environment Variables

These are automatically set/updated by test scripts:

| Variable | Description | Set By |
|----------|-------------|--------|
| `base_url` | API base URL | Manual |
| `access_token` | JWT token | Login request |
| `user_id` | Current user ID | Register/Login |
| `authorization_id` | Last created auth | Create Authorization |
| `purchase_id` | Last purchase | Create Purchase |
| `receipt_no` | Receipt number | Manual |

---

## 🧪 Testing Workflow

### Complete Flow Example:

1. **Register** → Auto-saves `user_id`
2. **Login** → Auto-saves `access_token`
3. **Get Wallet Balance** → See initial 250 tokens
4. **Create Authorization** → Hold 10 tokens, Auto-saves `authorization_id`
5. **Capture Authorization** → Deduct the 10 tokens
6. **Get Transaction History** → See all transactions
7. **Get Wallet Balance** → Confirm balance updated (240 tokens)

### Authorization Flow (Hold-Capture):

```
1. Create Authorization (HOLD)
   ↓
2a. Capture (SUCCESS) → Debit tokens
   OR
2b. Void (FAILED) → Refund tokens
```

### Purchase Flow:

```
1. Create Purchase → Get provider payload
   ↓
2. User completes payment on provider site
   ↓
3. Get Purchase Status → Check if paid
   ↓
4. List Receipts → View receipt
```

---

## 🎯 Common Use Cases

### Use Case 1: Chapter Generation
```
1. Create Authorization (10 tokens for chapter)
2. Generate chapter (in your app)
3a. Success → Capture Authorization
3b. Failure → Void Authorization (refund)
```

### Use Case 2: Buy Tokens
```
1. Get Pricebook (see prices)
2. Create Purchase (100 tokens)
3. Complete payment (Razorpay)
4. Get Purchase Status (verify paid)
5. Get Wallet Balance (see new balance)
```

### Use Case 3: Check Usage Analytics (Admin)
```
1. Token Overview (total stats)
2. Tokens by Feature (breakdown)
3. Token Trend (7 days)
4. Create Export (download CSV)
```

---

## 🔒 Authentication

All requests (except Register/Login) require JWT token in header:

```
Authorization: Bearer {{access_token}}
```

**This is handled automatically** by the collection's Auth settings!

---

## 🛠️ Custom Headers

Some requests include special headers:

- **`X-Request-Id`** - Unique ID for request tracing (auto-generated GUID)
- **`X-Idempotency-Key`** - Prevent duplicate operations (auto-generated GUID)
- **`X-Source`** - Request source identifier (allowed: `web`, `admin`, `service`, `mobile`)

---

## 💡 Tips & Tricks

### 1. **Random Test Data**
The collection uses Postman variables for random data:
- `{{$randomInt}}` - Random number
- `{{$guid}}` - Random GUID
- `{{$timestamp}}` - Current timestamp

### 2. **View Saved Variables**
Click **👁️ icon** (top right) → Select environment to view saved values

### 3. **Copy as cURL**
Right-click any request → **"Code Snippet"** → Select **"cURL"**

### 4. **Duplicate Requests**
Right-click request → **"Duplicate"** to create test variations

### 5. **Run Collection**
Click **"..."** on collection → **"Run collection"** → Run all tests sequentially

---

## 🐛 Troubleshooting

### ❌ "Unauthorized" Error
**Solution:** Run **Login** request again to refresh token

### ❌ "authorization_id not found"
**Solution:** Run **Create Authorization** first to save the ID

### ❌ "Missing X-Idempotency-Key"
**Solution:** Header should auto-populate with `{{$guid}}` - check if enabled

### ❌ Base URL not working
**Solution:**
1. Check environment is selected (top right)
2. Verify `base_url` variable is set correctly

---

## 📊 Test Scripts

Several requests include **test scripts** that automatically save variables:

```javascript
// Auto-save token after login
if (pm.response.code === 200) {
    const response = pm.response.json();
    pm.collectionVariables.set('access_token', response.token);
}
```

You can view/edit these in the **"Tests"** tab of each request.

---

## 🌐 Environments

You can create multiple environments for different stages:

### Production
```json
{
  "base_url": "https://quadrailearn.quadravise.com/api"
}
```

### Local Development
```json
{
  "base_url": "http://localhost/api"
}
```

### Staging
```json
{
  "base_url": "https://staging.quadrailearn.quadravise.com/api"
}
```

To create new environment:
1. Click ⚙️ (Settings) → **"Environments"**
2. Click **"+"** → Name it → Add variables
3. Switch between environments using dropdown

---

## 📝 Notes

- All timestamps are in **ISO 8601 format** (UTC)
- Token amounts are in **integer** values (not decimals)
- Currency amounts (INR) are in **paise** (₹1 = 100 paise)
- ULIDs are used for primary keys (26 characters)
- Pagination uses **cursor-based** pagination (not page numbers)

---

## 🎉 Happy Testing!

For issues or questions, check the API documentation or contact the development team.
