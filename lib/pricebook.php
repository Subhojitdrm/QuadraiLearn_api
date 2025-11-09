<?php
declare(strict_types=1);

/**
 * Pricebook Service
 *
 * Manages pricing for features that consume tokens
 */

require_once __DIR__ . '/errors.php';

/**
 * Pricebook configuration
 * Maps features to their token costs
 */
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

/**
 * Get pricing for a specific feature
 *
 * @param string $feature Feature name
 * @return array|null Pricing data or null if not found
 */
function get_pricebook_entry(string $feature): ?array
{
    return PRICEBOOK[$feature] ?? null;
}

/**
 * Get unit cost for a feature
 *
 * @param string $feature Feature name
 * @return int|null Unit cost or null if not found
 */
function get_feature_cost(string $feature): ?int
{
    $entry = get_pricebook_entry($feature);
    return $entry ? $entry['unit_cost'] : null;
}

/**
 * Get all pricebook entries
 *
 * @return array All pricebook entries
 */
function get_all_pricebook_entries(): array
{
    return PRICEBOOK;
}

/**
 * Validate feature exists in pricebook
 *
 * @param string $feature Feature name
 * @return void Exits with validation error if not found
 */
function validate_feature(string $feature): void
{
    if (!isset(PRICEBOOK[$feature])) {
        validation_error('Unknown feature', [
            'feature' => "Feature '{$feature}' not found in pricebook"
        ]);
    }
}

/**
 * Calculate total cost for units
 *
 * @param string $feature Feature name
 * @param int $units Number of units
 * @return int Total cost
 */
function calculate_cost(string $feature, int $units): int
{
    $cost = get_feature_cost($feature);
    if ($cost === null) {
        validation_error('Unknown feature', ['feature' => "Feature '{$feature}' not found"]);
    }

    return $cost * $units;
}
