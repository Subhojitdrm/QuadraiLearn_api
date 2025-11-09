<?php
declare(strict_types=1);

/**
 * ULID Generator
 *
 * Universally Unique Lexicographically Sortable Identifier
 * - 26 characters (base32 encoded)
 * - Timestamp-based (first 10 chars)
 * - Randomness (last 16 chars)
 * - Lexicographically sortable
 * - Case insensitive
 *
 * Format: TTTTTTTTTTRRRRRRRRRRRRRRRR
 * T = Timestamp (48 bits)
 * R = Randomness (80 bits)
 */

class ULID
{
    // Crockford's Base32 alphabet (excludes I, L, O, U to avoid confusion)
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const ENCODING_LEN = 32;

    // ULID component lengths
    private const TIME_LEN = 10;
    private const RANDOM_LEN = 16;
    private const TOTAL_LEN = 26;

    /**
     * Generate a new ULID
     *
     * @param int|null $timestamp Unix timestamp in milliseconds (null = now)
     * @return string 26-character ULID
     */
    public static function generate(?int $timestamp = null): string
    {
        if ($timestamp === null) {
            $timestamp = (int)(microtime(true) * 1000);
        }

        $timePart = self::encodeTime($timestamp);
        $randomPart = self::encodeRandom();

        return $timePart . $randomPart;
    }

    /**
     * Encode timestamp to base32 (10 characters)
     *
     * @param int $timestamp Unix timestamp in milliseconds
     * @return string 10-character base32 encoded timestamp
     */
    private static function encodeTime(int $timestamp): string
    {
        $chars = '';
        for ($i = self::TIME_LEN - 1; $i >= 0; $i--) {
            $mod = $timestamp % self::ENCODING_LEN;
            $chars = self::ENCODING[$mod] . $chars;
            $timestamp = (int)($timestamp / self::ENCODING_LEN);
        }
        return $chars;
    }

    /**
     * Encode random bytes to base32 (16 characters)
     *
     * @return string 16-character base32 encoded random string
     */
    private static function encodeRandom(): string
    {
        $bytes = random_bytes(10); // 80 bits of randomness
        $chars = '';

        // Convert bytes to base32
        $num = gmp_init(bin2hex($bytes), 16);
        for ($i = 0; $i < self::RANDOM_LEN; $i++) {
            list($num, $remainder) = gmp_div_qr($num, gmp_init(self::ENCODING_LEN));
            $chars = self::ENCODING[gmp_intval($remainder)] . $chars;
        }

        return str_pad($chars, self::RANDOM_LEN, '0', STR_PAD_LEFT);
    }

    /**
     * Validate ULID format
     *
     * @param string $ulid ULID to validate
     * @return bool True if valid ULID format
     */
    public static function isValid(string $ulid): bool
    {
        if (strlen($ulid) !== self::TOTAL_LEN) {
            return false;
        }

        // Check if all characters are valid base32
        return preg_match('/^[' . self::ENCODING . ']{26}$/', strtoupper($ulid)) === 1;
    }

    /**
     * Extract timestamp from ULID
     *
     * @param string $ulid ULID
     * @return int Unix timestamp in milliseconds
     */
    public static function getTimestamp(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException('Invalid ULID format');
        }

        $timePart = strtoupper(substr($ulid, 0, self::TIME_LEN));
        $timestamp = 0;

        for ($i = 0; $i < self::TIME_LEN; $i++) {
            $char = $timePart[$i];
            $value = strpos(self::ENCODING, $char);
            $timestamp = $timestamp * self::ENCODING_LEN + $value;
        }

        return $timestamp;
    }
}

/**
 * Helper function to generate ULID
 *
 * @return string 26-character ULID
 */
function ulid(): string
{
    return ULID::generate();
}
