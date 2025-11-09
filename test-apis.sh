#!/bin/bash

# ============================================================================
# Wallet & Token API Test Script
# ============================================================================

# Configuration
BASE_URL="https://quadrailearn.quadravise.com/api"
# Or for local testing:
# BASE_URL="http://localhost/api"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper function to print section headers
print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

# Helper function to print success
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

# Helper function to print error
print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# Helper function to print info
print_info() {
    echo -e "${YELLOW}→ $1${NC}"
}

# Generate UUID (cross-platform)
generate_uuid() {
    if command -v uuidgen &> /dev/null; then
        uuidgen
    else
        cat /proc/sys/kernel/random/uuid 2>/dev/null || echo "$(date +%s)-$(shuf -i 1000-9999 -n 1)"
    fi
}

# ============================================================================
# Test 1: User Registration
# ============================================================================
test_registration() {
    print_header "TEST 1: User Registration"

    RANDOM_NUM=$((RANDOM % 10000))
    TEST_EMAIL="testuser${RANDOM_NUM}@example.com"
    TEST_USERNAME="testuser${RANDOM_NUM}"

    print_info "Registering user: ${TEST_USERNAME}"

    RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/register" \
        -H "Content-Type: application/json" \
        -d '{
            "firstName": "Test",
            "lastName": "User",
            "username": "'${TEST_USERNAME}'",
            "email": "'${TEST_EMAIL}'",
            "password": "TestPass123!",
            "confirmPassword": "TestPass123!",
            "interestedAreas": ["AI", "Math"],
            "primaryStudyNeed": "Testing"
        }')

    if echo "$RESPONSE" | jq -e '.ok' &> /dev/null; then
        print_success "User registered successfully"
        echo "$RESPONSE" | jq '.'

        # Save credentials for next tests
        echo "${TEST_EMAIL}" > /tmp/test_email.txt
        echo "TestPass123!" > /tmp/test_password.txt
    else
        print_error "Registration failed"
        echo "$RESPONSE" | jq '.'
        return 1
    fi
}

# ============================================================================
# Test 2: User Login
# ============================================================================
test_login() {
    print_header "TEST 2: User Login"

    TEST_EMAIL=$(cat /tmp/test_email.txt 2>/dev/null || echo "")
    TEST_PASSWORD=$(cat /tmp/test_password.txt 2>/dev/null || echo "")

    if [ -z "$TEST_EMAIL" ]; then
        print_error "No test email found. Run registration test first."
        return 1
    fi

    print_info "Logging in as: ${TEST_EMAIL}"

    RESPONSE=$(curl -s -X POST "${BASE_URL}/auth/login" \
        -H "Content-Type: application/json" \
        -d '{
            "identifier": "'${TEST_EMAIL}'",
            "password": "'${TEST_PASSWORD}'"
        }')

    TOKEN=$(echo "$RESPONSE" | jq -r '.token // empty')

    if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
        print_success "Login successful"
        echo "$RESPONSE" | jq '.'

        # Save token for subsequent tests
        echo "$TOKEN" > /tmp/test_token.txt
    else
        print_error "Login failed"
        echo "$RESPONSE" | jq '.'
        return 1
    fi
}

# ============================================================================
# Test 3: Get Wallet Balance
# ============================================================================
test_wallet_balance() {
    print_header "TEST 3: Get Wallet Balance"

    TOKEN=$(cat /tmp/test_token.txt 2>/dev/null || echo "")

    if [ -z "$TOKEN" ]; then
        print_error "No token found. Run login test first."
        return 1
    fi

    print_info "Fetching wallet balance..."

    RESPONSE=$(curl -s -X GET "${BASE_URL}/v1/wallet/me" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "X-Request-Id: $(generate_uuid)" \
        -H "X-Source: test-script")

    TOTAL=$(echo "$RESPONSE" | jq -r '.balances.total // 0')

    if [ "$TOTAL" -gt 0 ]; then
        print_success "Wallet balance retrieved: ${TOTAL} tokens"
        echo "$RESPONSE" | jq '.'
    else
        print_error "Failed to get wallet balance"
        echo "$RESPONSE" | jq '.'
        return 1
    fi
}

# ============================================================================
# Test 4: Get Transaction History
# ============================================================================
test_transactions() {
    print_header "TEST 4: Get Transaction History"

    TOKEN=$(cat /tmp/test_token.txt 2>/dev/null || echo "")

    if [ -z "$TOKEN" ]; then
        print_error "No token found. Run login test first."
        return 1
    fi

    print_info "Fetching transaction history..."

    RESPONSE=$(curl -s -X GET "${BASE_URL}/v1/wallet/me/transactions?limit=5" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "X-Request-Id: $(generate_uuid)" \
        -H "X-Source: test-script")

    ITEMS_COUNT=$(echo "$RESPONSE" | jq '.items | length')

    if [ "$ITEMS_COUNT" -gt 0 ]; then
        print_success "Found ${ITEMS_COUNT} transactions"
        echo "$RESPONSE" | jq '.'
    else
        print_info "No transactions found (new account)"
        echo "$RESPONSE" | jq '.'
    fi
}

# ============================================================================
# Test 5: Create Token Authorization
# ============================================================================
test_authorization_create() {
    print_header "TEST 5: Create Token Authorization (HOLD)"

    TOKEN=$(cat /tmp/test_token.txt 2>/dev/null || echo "")

    if [ -z "$TOKEN" ]; then
        print_error "No token found. Run login test first."
        return 1
    fi

    print_info "Creating token authorization..."

    RESOURCE_KEY="test_chapter_$(date +%s)"

    RESPONSE=$(curl -s -X POST "${BASE_URL}/v1/tokens/authorizations" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -H "X-Request-Id: $(generate_uuid)" \
        -H "X-Idempotency-Key: $(generate_uuid)" \
        -H "X-Source: test-script" \
        -d '{
            "feature": "chapter_generation",
            "units": 1,
            "cost_per_unit": 10,
            "resource_key": "'${RESOURCE_KEY}'",
            "metadata": {
                "subject": "Mathematics",
                "grade": "VIII",
                "test": true
            }
        }')

    AUTH_ID=$(echo "$RESPONSE" | jq -r '.authorization_id // empty')

    if [ -n "$AUTH_ID" ] && [ "$AUTH_ID" != "null" ]; then
        print_success "Authorization created: ${AUTH_ID}"
        echo "$RESPONSE" | jq '.'

        # Save for next test
        echo "$AUTH_ID" > /tmp/test_auth_id.txt
    else
        print_error "Failed to create authorization"
        echo "$RESPONSE" | jq '.'
        return 1
    fi
}

# ============================================================================
# Test 6: Capture Authorization
# ============================================================================
test_authorization_capture() {
    print_header "TEST 6: Capture Authorization (DEBIT)"

    TOKEN=$(cat /tmp/test_token.txt 2>/dev/null || echo "")
    AUTH_ID=$(cat /tmp/test_auth_id.txt 2>/dev/null || echo "")

    if [ -z "$TOKEN" ] || [ -z "$AUTH_ID" ]; then
        print_error "Missing token or authorization ID. Run previous tests first."
        return 1
    fi

    print_info "Capturing authorization: ${AUTH_ID}"

    RESPONSE=$(curl -s -X POST "${BASE_URL}/v1/tokens/authorizations/capture?authorization_id=${AUTH_ID}" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -H "X-Request-Id: $(generate_uuid)" \
        -H "X-Idempotency-Key: $(generate_uuid)" \
        -H "X-Source: test-script" \
        -d '{
            "result_id": "test_chapter_result_001",
            "status_from_upstream": "success"
        }')

    STATUS=$(echo "$RESPONSE" | jq -r '.status // empty')

    if [ "$STATUS" == "captured" ]; then
        print_success "Authorization captured successfully"
        echo "$RESPONSE" | jq '.'
    else
        print_error "Failed to capture authorization"
        echo "$RESPONSE" | jq '.'
        return 1
    fi
}

# ============================================================================
# Test 7: Get Pricebook
# ============================================================================
test_pricebook() {
    print_header "TEST 7: Get Token Pricebook"

    TOKEN=$(cat /tmp/test_token.txt 2>/dev/null || echo "")

    if [ -z "$TOKEN" ]; then
        print_error "No token found. Run login test first."
        return 1
    fi

    print_info "Fetching pricebook..."

    RESPONSE=$(curl -s -X GET "${BASE_URL}/v1/wallet/pricebook" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "X-Request-Id: $(generate_uuid)" \
        -H "X-Source: test-script")

    TIERS_COUNT=$(echo "$RESPONSE" | jq '.tiers | length // 0')

    if [ "$TIERS_COUNT" -gt 0 ]; then
        print_success "Found ${TIERS_COUNT} pricing tiers"
        echo "$RESPONSE" | jq '.'
    else
        print_error "Failed to get pricebook"
        echo "$RESPONSE" | jq '.'
        return 1
    fi
}

# ============================================================================
# Test 8: Create Purchase (without actual payment)
# ============================================================================
test_purchase_create() {
    print_header "TEST 8: Create Token Purchase"

    TOKEN=$(cat /tmp/test_token.txt 2>/dev/null || echo "")

    if [ -z "$TOKEN" ]; then
        print_error "No token found. Run login test first."
        return 1
    fi

    print_info "Creating purchase intent..."

    RESPONSE=$(curl -s -X POST "${BASE_URL}/v1/wallet/me/purchases" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" \
        -H "X-Request-Id: $(generate_uuid)" \
        -H "X-Idempotency-Key: $(generate_uuid)" \
        -H "X-Source: test-script" \
        -d '{
            "tokens": 100,
            "provider": "razorpay"
        }')

    PURCHASE_ID=$(echo "$RESPONSE" | jq -r '.purchase_id // empty')

    if [ -n "$PURCHASE_ID" ] && [ "$PURCHASE_ID" != "null" ]; then
        print_success "Purchase created: ${PURCHASE_ID}"
        echo "$RESPONSE" | jq '.'

        # Save for next test
        echo "$PURCHASE_ID" > /tmp/test_purchase_id.txt
    else
        print_error "Failed to create purchase"
        echo "$RESPONSE" | jq '.'
        return 1
    fi
}

# ============================================================================
# Main Test Runner
# ============================================================================
main() {
    echo -e "${BLUE}"
    echo "╔═══════════════════════════════════════════════════════════════╗"
    echo "║         Wallet & Token API - Test Suite                      ║"
    echo "║         Base URL: ${BASE_URL}                                ║"
    echo "╚═══════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"

    # Check if jq is installed
    if ! command -v jq &> /dev/null; then
        print_error "jq is required but not installed. Please install jq to run these tests."
        exit 1
    fi

    # Run tests
    TESTS_PASSED=0
    TESTS_FAILED=0

    # Test 1: Registration
    if test_registration; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
    fi

    # Test 2: Login
    if test_login; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
        echo -e "\n${RED}Stopping tests - login failed${NC}\n"
        exit 1
    fi

    # Test 3: Wallet Balance
    if test_wallet_balance; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
    fi

    # Test 4: Transactions
    if test_transactions; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
    fi

    # Test 5: Create Authorization
    if test_authorization_create; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
    fi

    # Test 6: Capture Authorization
    if test_authorization_capture; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
    fi

    # Test 7: Pricebook
    if test_pricebook; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
    fi

    # Test 8: Create Purchase
    if test_purchase_create; then
        ((TESTS_PASSED++))
    else
        ((TESTS_FAILED++))
    fi

    # Summary
    print_header "TEST SUMMARY"
    echo -e "${GREEN}Tests Passed: ${TESTS_PASSED}${NC}"
    echo -e "${RED}Tests Failed: ${TESTS_FAILED}${NC}"
    echo ""

    if [ $TESTS_FAILED -eq 0 ]; then
        print_success "All tests passed! 🎉"
    else
        print_error "Some tests failed. Check output above for details."
    fi

    # Cleanup
    print_info "Cleaning up temporary files..."
    rm -f /tmp/test_*.txt
}

# Run main function
main
