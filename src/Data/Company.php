<?php

declare(strict_types=1);

namespace Deinte\Peppol\Data;

use Deinte\Peppol\Enums\EasCode;
use InvalidArgumentException;

/**
 * Represents a company on the PEPPOL network.
 */
class Company
{
    public readonly string $vatNumber;

    public function __construct(
        string $vatNumber,
        public readonly ?string $peppolId = null,
        public readonly ?string $name = null,
        public readonly ?string $country = null,
        public readonly ?string $email = null,
        public readonly ?string $taxNumber = null,
        public readonly ?EasCode $taxNumberScheme = null,
        public readonly ?array $metadata = null,
    ) {
        $this->vatNumber = self::normalizeVatNumber($vatNumber);
    }

    /**
     * Normalize a VAT number by removing spaces, dots, and dashes.
     * Preserves the country prefix (e.g., BE, NL, FR).
     */
    public static function normalizeVatNumber(string $vatNumber): string
    {
        // Remove spaces, dots, dashes
        $normalized = preg_replace('/[\s.\-]/', '', $vatNumber);

        // Ensure uppercase
        return strtoupper($normalized);
    }

    public function isOnPeppol(): bool
    {
        return $this->peppolId !== null;
    }

    /**
     * Get the lookup identifier for Peppol lookups.
     *
     * Priority:
     * 1. Explicit tax_number if provided
     * 2. For BE: derive enterprise number from VAT (strip "BE" prefix)
     * 3. Fallback to VAT number
     */
    public function getLookupIdentifier(): string
    {
        // 1. Explicit tax number provided
        if ($this->taxNumber) {
            return $this->taxNumber;
        }

        // 2. Belgium: derive enterprise number from VAT
        if ($this->country === 'BE' && $this->vatNumber) {
            return preg_replace('/^BE/i', '', $this->vatNumber);
        }

        // 3. Fallback to VAT number
        return $this->vatNumber;
    }

    /**
     * Get the EAS scheme for the lookup identifier.
     *
     * Priority:
     * 1. Explicit tax_number_scheme if provided
     * 2. Default scheme for country (e.g., BE_CBE for Belgium)
     * 3. VAT scheme for country
     */
    public function getLookupScheme(): ?EasCode
    {
        // 1. Explicit scheme provided
        if ($this->taxNumberScheme) {
            return $this->taxNumberScheme;
        }

        // 2. If we have a tax number or can derive one (BE), use default scheme
        if ($this->taxNumber || $this->country === 'BE') {
            try {
                return EasCode::defaultSchemeForCountry($this->country ?? $this->guessCountryFromVat());
            } catch (InvalidArgumentException) {
                // Country not supported, fall through to VAT scheme
            }
        }

        // 3. Try VAT scheme for country
        try {
            return EasCode::vatSchemeForCountry($this->country ?? $this->guessCountryFromVat());
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Guess the country code from VAT number prefix.
     */
    private function guessCountryFromVat(): string
    {
        if (preg_match('/^([A-Z]{2})/i', $this->vatNumber, $matches)) {
            return strtoupper($matches[1]);
        }

        return '';
    }

    public function toArray(): array
    {
        return [
            'vat_number' => $this->vatNumber,
            'peppol_id' => $this->peppolId,
            'name' => $this->name,
            'country' => $this->country,
            'email' => $this->email,
            'tax_number' => $this->taxNumber,
            'tax_number_scheme' => $this->taxNumberScheme?->value,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        $scheme = null;
        if (isset($data['tax_number_scheme'])) {
            $scheme = EasCode::tryFrom($data['tax_number_scheme']);
        }

        return new self(
            vatNumber: $data['vat_number'],
            peppolId: $data['peppol_id'] ?? null,
            name: $data['name'] ?? null,
            country: $data['country'] ?? null,
            email: $data['email'] ?? null,
            taxNumber: $data['tax_number'] ?? null,
            taxNumberScheme: $scheme,
            metadata: $data['metadata'] ?? null,
        );
    }
}
