<?php

declare(strict_types=1);

namespace Deinte\Peppol\Data;

/**
 * Represents a company on the PEPPOL network.
 */
class Company
{
    public function __construct(
        public readonly string $vatNumber,
        public readonly ?string $peppolId = null,
        public readonly ?string $name = null,
        public readonly ?string $country = null,
        public readonly ?string $email = null,
        public readonly ?array $metadata = null,
    ) {}

    public function isOnPeppol(): bool
    {
        return $this->peppolId !== null;
    }

    public function toArray(): array
    {
        return [
            'vat_number' => $this->vatNumber,
            'peppol_id' => $this->peppolId,
            'name' => $this->name,
            'country' => $this->country,
            'email' => $this->email,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            vatNumber: $data['vat_number'],
            peppolId: $data['peppol_id'] ?? null,
            name: $data['name'] ?? null,
            country: $data['country'] ?? null,
            email: $data['email'] ?? null,
            metadata: $data['metadata'] ?? null,
        );
    }
}
