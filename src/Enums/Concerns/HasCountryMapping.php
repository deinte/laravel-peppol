<?php

declare(strict_types=1);

namespace Deinte\Peppol\Enums\Concerns;

use InvalidArgumentException;

/**
 * Trait for EAS codes to provide country-based lookups.
 *
 * This trait provides helper methods to find EAS codes based on:
 * - ISO 3166-1 alpha-2 country codes
 * - VAT scheme lookups
 * - Default/preferred schemes per country
 */
trait HasCountryMapping
{
    /**
     * Get all EAS codes for a specific country.
     *
     * Returns all available schemes for the given country code.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code (e.g., 'NL', 'BE')
     * @return array<self>
     */
    public static function forCountry(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);

        return array_filter(
            self::cases(),
            fn (self $case) => $case->countryCode() === $countryCode
        );
    }

    /**
     * Get the VAT scheme for a specific country.
     *
     * Returns the primary VAT identifier scheme for the country.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     *
     * @throws InvalidArgumentException if country has no VAT scheme
     */
    public static function vatSchemeForCountry(string $countryCode): self
    {
        $countryCode = strtoupper($countryCode);

        $vatScheme = match ($countryCode) {
            'FR' => self::VAT_FR,
            'DE' => self::VAT_DE,
            'IE' => self::VAT_IE,
            'IT' => self::VAT_IT,
            'LU' => self::VAT_LU,
            'NL' => self::VAT_NL,
            'BE' => self::VAT_BE,
            'AT' => self::VAT_AT,
            'DK' => self::VAT_DK,
            'ES' => self::VAT_ES,
            'FI' => self::VAT_FI,
            'GR' => self::VAT_GR,
            'PT' => self::VAT_PT,
            'SE' => self::VAT_SE,
            'GB' => self::VAT_GB,
            'NO' => self::VAT_NO,
            'CH' => self::VAT_CH,
            default => throw new InvalidArgumentException("No VAT scheme defined for country: {$countryCode}"),
        };

        return $vatScheme;
    }

    /**
     * Get the default/preferred business identifier scheme for a country.
     *
     * This returns the most commonly used national business register
     * identifier for the country (not VAT).
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     *
     * @throws InvalidArgumentException if country has no default scheme
     */
    public static function defaultSchemeForCountry(string $countryCode): self
    {
        $countryCode = strtoupper($countryCode);

        $scheme = match ($countryCode) {
            'NL' => self::NL_KVK,
            'BE' => self::BE_CBE,
            'DE' => self::DE_COMPANY_NUMBER,
            'FR' => self::FR_SIRET,
            'SE' => self::SE_ORGNR,
            'NO' => self::NO_ORGNR,
            'DK' => self::DK_CVR,
            'FI' => self::FI_OVT,
            'IT' => self::IT_IVA,
            'LT' => self::LT_LEGAL_ENTITY,
            'SG' => self::SG_UEN,
            'IS' => self::IS_KENNITALA,
            default => throw new InvalidArgumentException("No default scheme defined for country: {$countryCode}"),
        };

        return $scheme;
    }

    /**
     * Try to find the most appropriate scheme for a country and identifier type.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     * @param  string|null  $preferredType  Preferred type: 'vat', 'business', 'government'
     */
    public static function findSchemeForCountry(string $countryCode, ?string $preferredType = null): ?self
    {
        $countryCode = strtoupper($countryCode);

        try {
            return match ($preferredType) {
                'vat' => self::vatSchemeForCountry($countryCode),
                'business', null => self::defaultSchemeForCountry($countryCode),
                'government' => self::findGovernmentSchemeForCountry($countryCode),
                default => null,
            };
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Get government/public sector scheme for a country.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 country code
     *
     * @throws InvalidArgumentException if country has no government scheme
     */
    public static function findGovernmentSchemeForCountry(string $countryCode): self
    {
        $countryCode = strtoupper($countryCode);

        $scheme = match ($countryCode) {
            'NL' => self::NL_OIN,
            'DE' => self::DE_LEITWEG,
            'DK' => self::DK_P_NUMBER,
            default => throw new InvalidArgumentException("No government scheme defined for country: {$countryCode}"),
        };

        return $scheme;
    }

    /**
     * Get all VAT schemes across all countries.
     *
     * @return array<self>
     */
    public static function allVatSchemes(): array
    {
        return array_filter(
            self::cases(),
            fn (self $case) => $case->isVatScheme()
        );
    }

    /**
     * Get all national business register schemes.
     *
     * @return array<self>
     */
    public static function allNationalRegisterSchemes(): array
    {
        return array_filter(
            self::cases(),
            fn (self $case) => $case->isNationalRegister()
        );
    }

    /**
     * Get all international identifier schemes.
     *
     * @return array<self>
     */
    public static function allInternationalSchemes(): array
    {
        return array_filter(
            self::cases(),
            fn (self $case) => $case->isInternational()
        );
    }

    /**
     * Try to determine the country from an identifier value.
     *
     * This is a best-effort approach based on known patterns.
     * Returns null if country cannot be determined.
     *
     * @param  string  $identifier  The business identifier value
     */
    public static function guessCountryFromIdentifier(string $identifier): ?string
    {
        // Remove common prefixes and clean up
        $cleaned = strtoupper(preg_replace('/[^A-Z0-9]/', '', $identifier));

        // Check for country code prefixes in VAT numbers
        if (preg_match('/^([A-Z]{2})\d/', $cleaned, $matches)) {
            return $matches[1];
        }

        // Length-based guessing for common formats
        $length = strlen($cleaned);

        return match ($length) {
            8 => 'DK', // Likely Danish CVR or Dutch KvK
            9 => 'NO', // Likely Norwegian Org Number or DUNS
            10 => 'DK', // Likely Danish CPR
            14 => 'FR', // Likely French SIRET
            default => null,
        };
    }

    /**
     * Get schemes grouped by region.
     *
     * @return array<string, array<self>>
     */
    public static function groupedByRegion(): array
    {
        $schemes = self::cases();

        return [
            'Nordic' => array_filter($schemes, fn (self $s) => in_array($s->countryCode(), ['SE', 'NO', 'DK', 'FI', 'IS'])),
            'Benelux' => array_filter($schemes, fn (self $s) => in_array($s->countryCode(), ['NL', 'BE', 'LU'])),
            'DACH' => array_filter($schemes, fn (self $s) => in_array($s->countryCode(), ['DE', 'AT', 'CH'])),
            'Southern Europe' => array_filter($schemes, fn (self $s) => in_array($s->countryCode(), ['IT', 'ES', 'PT', 'GR'])),
            'Western Europe' => array_filter($schemes, fn (self $s) => in_array($s->countryCode(), ['FR', 'GB', 'IE'])),
            'International' => self::allInternationalSchemes(),
        ];
    }
}
