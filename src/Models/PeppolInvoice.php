<?php

declare(strict_types=1);

namespace Deinte\Peppol\Models;

use Deinte\Peppol\Enums\PeppolStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

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
 * @property string|null $connector_type
 * @property string|null $connector_status
 * @property string|null $connector_error
 * @property \Illuminate\Support\Carbon|null $connector_uploaded_at
 * @property PeppolStatus $status
 * @property bool $skip_peppol_delivery
 * @property string|null $status_message
 * @property int $poll_attempts
 * @property \Illuminate\Support\Carbon|null $next_poll_at
 * @property \Illuminate\Support\Carbon|null $scheduled_dispatch_at
 * @property \Illuminate\Support\Carbon|null $dispatched_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property array|null $metadata
 * @property array|null $request_payload
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
        'connector_type',
        'connector_status',
        'connector_error',
        'connector_uploaded_at',
        'status',
        'skip_peppol_delivery',
        'status_message',
        'poll_attempts',
        'next_poll_at',
        'scheduled_dispatch_at',
        'dispatched_at',
        'delivered_at',
        'metadata',
        'request_payload',
        'poll_response',
    ];

    protected $casts = [
        'status' => PeppolStatus::class,
        'skip_peppol_delivery' => 'boolean',
        'poll_attempts' => 'integer',
        'metadata' => 'array',
        'request_payload' => 'array',
        'poll_response' => 'array',
        'connector_uploaded_at' => 'datetime',
        'next_poll_at' => 'datetime',
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
        DB::transaction(function () use ($status, $message, $metadata) {
            $updateData = [
                'status' => $status,
                'status_message' => $message,
            ];

            if ($status->isDelivered() && $this->delivered_at === null) {
                $updateData['delivered_at'] = now();
            }

            $this->update($updateData);

            $this->statuses()->create([
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata,
            ]);
        });
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
    public function scopeReadyToDispatch(Builder $query): Builder
    {
        return $query->whereNull('dispatched_at')
            ->whereNotNull('scheduled_dispatch_at')
            ->where('scheduled_dispatch_at', '<=', now());
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus(Builder $query, PeppolStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get invoices that need status polling.
     *
     * Includes:
     * - Dispatched invoices not in final status
     * - Failed invoices that haven't exceeded max retries and are due for polling
     */
    public function scopeNeedsPolling(Builder $query, int $maxPollAttempts = 5): Builder
    {
        $finalStatuses = [
            PeppolStatus::ACCEPTED,
            PeppolStatus::REJECTED,
            PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
        ];

        return $query
            ->whereNotNull('dispatched_at')
            ->whereNotNull('connector_invoice_id')
            ->where('skip_peppol_delivery', false)
            ->where(function (Builder $q) use ($finalStatuses, $maxPollAttempts) {
                // Normal invoices not in final status
                $q->whereNotIn('status', [
                    ...$finalStatuses,
                    PeppolStatus::FAILED_DELIVERY,
                ]);

                // OR failed invoices that can still be retried
                $q->orWhere(function (Builder $failed) use ($maxPollAttempts) {
                    $failed->where('status', PeppolStatus::FAILED_DELIVERY)
                        ->where('poll_attempts', '<', $maxPollAttempts)
                        ->where(function (Builder $timing) {
                            $timing->whereNull('next_poll_at')
                                ->orWhere('next_poll_at', '<=', now());
                        });
                });
            });
    }

    /**
     * Schedule the next poll attempt for a failed invoice.
     *
     * Uses exponential backoff: 1h, 4h, 12h, 24h, 48h
     * Updates attributes in memory so no fresh() call is needed after.
     */
    public function scheduleNextPoll(): void
    {
        $delays = config('peppol.poll.retry_delays', [1, 4, 12, 24, 48]); // hours
        $attempt = $this->poll_attempts;

        $delayHours = $delays[$attempt] ?? $delays[array_key_last($delays)];

        $this->poll_attempts = $attempt + 1;
        $this->next_poll_at = now()->addHours($delayHours);
        $this->save();
    }

    /**
     * Check if this invoice has exceeded max poll attempts.
     */
    public function hasExceededMaxPollAttempts(): bool
    {
        $maxAttempts = config('peppol.poll.max_attempts', 5);

        return $this->poll_attempts >= $maxAttempts;
    }

    /**
     * Reset poll attempts (e.g., when status changes from failed to something else).
     */
    public function resetPollAttempts(): void
    {
        $this->update([
            'poll_attempts' => 0,
            'next_poll_at' => null,
        ]);
    }

    /**
     * Get the parsed connector error data.
     *
     * Returns structured error data: ['message' => '...', 'context' => [...]]
     *
     * @return array<string, mixed>|null
     */
    public function getConnectorErrorData(): ?array
    {
        if ($this->connector_error === null) {
            return null;
        }

        $decoded = json_decode($this->connector_error, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
