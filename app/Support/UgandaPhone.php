<?php

namespace App\Support;

class UgandaPhone
{
    /**
     * Normalise a Ugandan mobile number to +256XXXXXXXXX, or null if it is not one.
     * Accepts 0772123456, 772123456, 256772123456 and +256 772 123 456.
     */
    public static function normalise(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (str_starts_with($digits, '256')) {
            $national = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            $national = $digits;
        }

        // Ugandan mobile numbers have nine digits after the country code and start with 7
        return preg_match('/^7\d{8}$/', $national) === 1 ? '+256'.$national : null;
    }
}
