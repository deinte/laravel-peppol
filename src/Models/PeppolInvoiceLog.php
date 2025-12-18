<?php

declare(strict_types=1);

namespace Deinte\Peppol\Models;

use Deinte\Peppol\Enums\PeppolState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Activity log entry for PEPPOL invoice state transitions.
 *
 * @property int $id
 * @property int $peppol_invoice_id
 * @property string|null $from_state
 * @property string $to_state
 * @property string|null $message
 * @property array|null $details
 * @property string|null $actor
 * @property \Illuminate\Support\Carbon $created_at
 */
class PeppolInvoiceLog extends Model
{
    public $timestamps = false;

    protected $table = 'peppol_invoice_logs';

    protected $fillable = [
        'peppol_invoice_id',
        'from_state',
        'to_state',
        'message',
        'details',
        'actor',
        'created_at',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PeppolInvoiceLog $log) {
            $log->created_at = $log->created_at ?? now();
        });
    }

    /**
     * Get the parent PEPPOL invoice.
     */
    public function peppolInvoice(): BelongsTo
    {
        return $this->belongsTo(PeppolInvoice::class);
    }

    /**
     * Get the from state as enum (nullable).
     */
    public function getFromStateEnumAttribute(): ?PeppolState
    {
        return $this->from_state ? PeppolState::tryFrom($this->from_state) : null;
    }

    /**
     * Get the to state as enum.
     */
    public function getToStateEnumAttribute(): ?PeppolState
    {
        return PeppolState::tryFrom($this->to_state);
    }

    /**
     * Check if this log entry represents an error.
     */
    public function isError(): bool
    {
        $toState = $this->to_state_enum;

        return $toState && $toState->isFailure();
    }

    /**
     * Check if this log entry represents success.
     */
    public function isSuccess(): bool
    {
        $toState = $this->to_state_enum;

        return $toState && $toState->isSuccess();
    }

    /**
     * Get a human-readable description of the transition.
     */
    public function getDescriptionAttribute(): string
    {
        $fromLabel = $this->from_state_enum?->label() ?? 'initial';
        $toLabel = $this->to_state_enum?->label() ?? $this->to_state;

        return "{$fromLabel} → {$toLabel}";
    }
}
