<?php

namespace App\Services\Fraud;

use App\Models\Client;
use App\Services\Phone\PhoneValidator;
use App\Services\Phone\CountryRules;
use App\Services\Phone\PhoneParser;
use Illuminate\Support\Facades\Log;

class FraudRiskService
{
    protected PhoneValidator $validator;
    protected CountryRules $countryRules;
    protected PhoneParser $parser;

    public function __construct(PhoneValidator $validator, CountryRules $countryRules, PhoneParser $parser)
    {
        $this->validator = $validator;
        $this->countryRules = $countryRules;
        $this->parser = $parser;
    }

    /**
     * Calculates the risk score for a given phone number.
     * Returns an array with 'score' (0-100) and 'level' (trusted, suspicious, high_risk).
     * 
     * @param string $phoneNumber The raw or normalized phone number
     * @param string|null $ipAddress The IP address of the user (optional for geolocation checks)
     * @param int|null $clientId The ID of the client (for updates where we shouldn't penalize the existing number)
     */
    public function evaluate(string $phoneNumber, ?string $ipAddress = null, ?int $clientId = null): array
    {
        $score = 0;

        // 1. Is it a valid number technically?
        if (!$this->validator->isValid($phoneNumber)) {
            $score += 100; // Invalid number is immediate high risk
            return $this->buildResult($score);
        }

        // Normalize for database checking
        $normalizedPhone = $this->parser->normalize($phoneNumber);

        // 2. Is the number already used by another account?
        $query = Client::where('phone', $normalizedPhone);
        if ($clientId) {
            $query->where('id', '!=', $clientId);
        }
        
        if ($query->exists()) {
            // Number is already used by someone else. High risk of fraud or duplicate account.
            $score += 80; 
        }

        // 3. Country consistency and prioritization
        $country = $this->countryRules->getCountry($phoneNumber);
        
        if ($country) {
            if (!$this->countryRules->isAfricanCountry($country)) {
                // Example rule: Non-African countries might carry a slightly higher base risk
                // if the core market is strictly Africa.
                $score += 10;
            }
            
            // TODO: In the future, check if IP geolocation matches the phone country code.
            // if ($ipAddress && GeoIP::getCountry($ipAddress) !== $country) {
            //     $score += 20;
            // }
        } else {
            // Could not determine country
            $score += 30;
        }

        return $this->buildResult($score);
    }

    /**
     * Helper to determine risk level based on score.
     */
    protected function buildResult(int $score): array
    {
        $level = 'trusted'; // < 30
        
        if ($score >= 80) {
            $level = 'high_risk';
        } elseif ($score >= 30) {
            $level = 'suspicious';
        }

        return [
            'score' => min($score, 100), // Cap at 100
            'level' => $level,
        ];
    }

    /**
     * Throws an exception if the risk is too high.
     * Can be called directly from controllers.
     * 
     * @throws \Illuminate\Validation\ValidationException
     */
    public function throwOnHighRisk(string $phoneNumber, ?string $ipAddress = null, ?int $clientId = null): array
    {
        $result = $this->evaluate($phoneNumber, $ipAddress, $clientId);

        if ($result['level'] === 'high_risk') {
            Log::warning('High risk phone number attempt detected', [
                'phone' => $phoneNumber,
                'ip' => $ipAddress,
                'score' => $result['score']
            ]);
            
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phone' => ['Ce numéro de téléphone présente un risque de sécurité ou est invalide.']
            ]);
        }

        return $result;
    }
}
