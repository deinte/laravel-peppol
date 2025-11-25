<?php

declare(strict_types=1);

namespace Deinte\Peppol\Models;

use Deinte\Peppol\Enums\PeppolStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * PEPPOL invoice tracking.
 *
 * This model uses a polymorphic relationship to link to your application's
 * invoice models, allowing you to attach PEPPOL functionality to any invoice.
 *
 * @property int $id
 * @property string $invoiceable_type
 * @property int $invoiceable_id
 * @property int|null $recipient_peppol_company_id
 * @property string|null $connector_invoice_id
 * @property PeppolStatus $status
 * @property string|null $status_message
 * @property \Illuminate\Support\Carbon|null $scheduled_dispatch_at
 * @property \Illuminate\Support\Carbon|null $dispatched_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PeppolInvoice extends Model
{
    protected $fillable = [
        'invoiceable_type',
        'invoiceable_id',
        'recipient_peppol_company_id',
        'connector_invoice_id',
        'status',
        'status_message',
        'scheduled_dispatch_at',
        'dispatched_at',
        'delivered_at',
        'metadata',
    ];

    protected $casts = [
        'status' => PeppolStatus::class,
        'metadata' => 'array',
        'scheduled_dispatch_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the parent invoiceable model (your app's invoice).
     */
    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the recipient company.
     */
    public function recipientCompany(): BelongsTo
    {
        return $this->belongsTo(PeppolCompany::class, 'recipient_peppol_company_id');
    }

    /**
     * Get the status history for this invoice.
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(PeppolInvoiceStatus::class)->orderBy('created_at', 'desc');
    }

    /**
     * Update the status and create a history entry.
     */
    public function updateStatus(PeppolStatus $status, ?string $message = null, ?array $metadata = null): void
    {
        $this->update([
            'status' => $status,
            'status_message' => $message,
        ]);

        // Update delivered_at timestamp if status indicates delivery
        if ($status->isDelivered() && $this->delivered_at === null) {
            $this->update(['delivered_at' => now()]);
        }

        // Create status history entry
        $this->statuses()->create([
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Check if the invoice is ready to be dispatched.
     */
    public function isReadyToDispatch(): bool
    {
        return $this->dispatched_at === null
            && $this->scheduled_dispatch_at !== null
            && $this->scheduled_dispatch_at <= now();
    }

    /**
     * Scope to get invoices ready for dispatch.
     */
    public function scopeReadyToDispatch($query)
    {
        return $query->whereNull('dispatched_at')
            ->whereNotNull('scheduled_dispatch_at')
            ->where('scheduled_dispatch_at', '<=', now());
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, PeppolStatus $status)
    {
        return $query->where('status', $status);
    }
}
