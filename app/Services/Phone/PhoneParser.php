<?php

namespace App\Services\Phone;

use Propaganistas\LaravelPhone\PhoneNumber;
use libphonenumber\NumberParseException;

class PhoneParser
{
    /**
     * Parses a raw phone number string into a PhoneNumber object.
     * Optionally takes a default country if the number doesn't have an explicit country code.
     */
    public function parse(string $phoneNumber, ?string $defaultCountry = null): ?PhoneNumber
    {
        try {
            if ($defaultCountry) {
                return new PhoneNumber($phoneNumber, $defaultCountry);
            }
            // Assume it has a country code prefix (e.g., +228)
            return new PhoneNumber($phoneNumber);
        } catch (NumberParseException $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Normalizes the phone number to E.164 format (e.g., +22890000000).
     */
    public function normalize(string $phoneNumber, ?string $defaultCountry = null): ?string
    {
        $parsed = $this->parse($phoneNumber, $defaultCountry);
        return $parsed ? $parsed->formatE164() : null;
    }
}
