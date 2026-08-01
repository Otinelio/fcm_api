<?php

namespace App\Services\Phone;

use App\Services\Phone\PhoneParser;

class CountryRules
{
    protected PhoneParser $parser;

    public function __construct(PhoneParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Extracts the ISO country code (e.g., 'TG', 'FR', 'CI') from the phone number.
     */
    public function getCountry(string $phoneNumber): ?string
    {
        $parsed = $this->parser->parse($phoneNumber);
        return $parsed ? $parsed->getCountry() : null;
    }

    /**
     * Checks if a phone number belongs to an African country.
     */
    public function isAfricanCountry(string $countryCode): bool
    {
        $africanCountries = [
            'DZ', 'AO', 'BJ', 'BW', 'BF', 'BI', 'CM', 'CV', 'CF', 'TD',
            'KM', 'CG', 'CD', 'DJ', 'EG', 'GQ', 'ER', 'SZ', 'ET', 'GA',
            'GM', 'GH', 'GN', 'GW', 'CI', 'KE', 'LS', 'LR', 'LY', 'MG',
            'MW', 'ML', 'MR', 'MU', 'MA', 'MZ', 'NA', 'NE', 'NG', 'RW',
            'ST', 'SN', 'SC', 'SL', 'SO', 'ZA', 'SS', 'SD', 'TZ', 'TG',
            'TN', 'UG', 'ZM', 'ZW'
        ];

        return in_array(strtoupper($countryCode), $africanCountries);
    }
}
