<?php

namespace App\Services\Phone;

use App\Services\Phone\PhoneParser;

class PhoneValidator
{
    protected PhoneParser $parser;

    public function __construct(PhoneParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * Checks if a phone number is globally valid according to international standard length and formatting rules.
     */
    public function isValid(string $phoneNumber, ?string $defaultCountry = null): bool
    {
        $parsed = $this->parser->parse($phoneNumber, $defaultCountry);
        
        if (!$parsed) {
            return false;
        }

        return $parsed->isValid();
    }
}
