<?php

declare(strict_types=1);

namespace Deinte\Peppol\Models;

use Deinte\Peppol\Enums\PeppolStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PEPPOL invoice status history.
 *
 * Tracks the status changes of a PEPPOL invoice over time
 * for auditing and debugging purposes.
 *
 * @property int $id
 * @property int $peppol_invoice_id
 * @property PeppolStatus $status
 * @property string|null $message
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PeppolInvoiceStatus extends Model
{
    protected $fillable = [
        'peppol_invoice_id',
        'status',
        'message',
        'metadata',
    ];

    protected $casts = [
        'status' => PeppolStatus::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the PEPPOL invoice this status belongs to.
     */
    public function peppolInvoice(): BelongsTo
    {
        return $this->belongsTo(PeppolInvoice::class);
    }
}
