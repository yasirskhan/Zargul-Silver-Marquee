<?php

namespace App\Support;

/** Small maintained ISO-4217 subset with explicit minor-unit precision. */
final class Iso4217
{
    /** @var array<string,int> */
    public const MINOR_UNITS = [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'PKR' => 2, 'INR' => 2,
        'AED' => 2, 'SAR' => 2, 'CAD' => 2, 'AUD' => 2, 'SGD' => 2,
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0,
        'KWD' => 3, 'BHD' => 3, 'JOD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function isValid(string $code): bool
    {
        return preg_match('/\A[A-Z]{3}\z/', $code) === 1
            && array_key_exists($code, self::MINOR_UNITS);
    }

    public static function minorUnits(string $code): int
    {
        if (! self::isValid($code)) {
            throw new \InvalidArgumentException('Unsupported ISO 4217 currency.');
        }

        return self::MINOR_UNITS[$code];
    }

    /** @return array<string,int> */
    public static function all(): array
    {
        return self::MINOR_UNITS;
    }
}
