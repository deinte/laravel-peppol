<?php

declare(strict_types=1);

namespace Deinte\Peppol\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cached PEPPOL company lookups.
 *
 * @property int $id
 * @property string $vat_number
 * @property string|null $peppol_id
 * @property string|null $name
 * @property string|null $country
 * @property string|null $email
 * @property bool $is_active
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $last_lookup_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PeppolCompany extends Model
{
    protected $fillable = [
        'vat_number',
        'peppol_id',
        'name',
        'country',
        'email',
        'is_active',
        'metadata',
        'last_lookup_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'last_lookup_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the invoices sent to this company.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(PeppolInvoice::class, 'recipient_peppol_company_id');
    }

    /**
     * Check if the company is registered on PEPPOL.
     */
    public function isOnPeppol(): bool
    {
        return $this->peppol_id !== null;
    }

    /**
     * Scope to get only companies on PEPPOL.
     */
    public function scopeOnPeppol($query)
    {
        return $query->whereNotNull('peppol_id');
    }

    /**
     * Scope to get only active companies.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find by VAT number.
     */
    public static function findByVatNumber(string $vatNumber): ?self
    {
        return static::where('vat_number', $vatNumber)->first();
    }
}
