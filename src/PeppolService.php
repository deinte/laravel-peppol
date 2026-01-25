<?php

declare(strict_types=1);

namespace Deinte\Peppol;

use DateTimeImmutable;
use DateTimeInterface;
use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\PeppolState;
use Deinte\Peppol\Exceptions\PeppolException;
use Deinte\Peppol\Jobs\DispatchPeppolInvoice;
use Deinte\Peppol\Models\PeppolCompany;
use Deinte\Peppol\Models\PeppolInvoice;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Main service for PEPPOL operations.
 *
 * This service provides a high-level API for working with PEPPOL
 * invoices and companies.
 */
class PeppolService
{
    public function __construct(
        private readonly PeppolConnector $connector,
    ) {}

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('peppol')->{$level}("[PeppolService] {$message}", $context);
    }

    /**
     * Look up a company on the PEPPOL network and cache the result.
     *
     * @param  string  $vatNumber  The VAT number to lookup
     * @param  bool  $forceRefresh  Skip cache and force API lookup
     * @param  string|null  $taxNumber  Optional tax/enterprise number (e.g., KvK, CBE)
     * @param  string|null  $country  ISO 3166-1 alpha-2 country code
     */
    public function lookupCompany(
        string $vatNumber,
        bool $forceRefresh = false,
        ?string $taxNumber = null,
        ?string $country = null,
    ): ?PeppolCompany {
        $vatNumber = Company::normalizeVatNumber($vatNumber);

        $this->log('info', 'Looking up company', [
            'vat_number' => $vatNumber,
            'tax_number' => $taxNumber,
            'country' => $country,
            'force_refresh' => $forceRefresh,
        ]);

        if (! $forceRefresh) {
            $cached = PeppolCompany::findByVatNumber($vatNumber);

            if ($cached && $this->isCacheValid($cached)) {
                $this->log('debug', 'Cache hit - returning cached company', [
                    'vat_number' => $vatNumber,
                    'peppol_id' => $cached->peppol_id,
                    'cached_at' => $cached->last_lookup_at?->toIso8601String(),
                ]);

                return $cached;
            }
        }

        $this->log('debug', 'Calling connector to lookup company', [
            'vat_number' => $vatNumber,
        ]);

        $company = $this->connector->lookupCompany($vatNumber, $taxNumber, $country);

        if (! $company) {
            $this->log('warning', 'Company lookup returned null', [
                'vat_number' => $vatNumber,
            ]);

            return null;
        }

        $this->log('info', 'Company lookup successful', [
            'vat_number' => $vatNumber,
            'peppol_id' => $company->peppolId,
            'on_peppol' => $company->peppolId !== null,
        ]);

        return $this->cacheCompany($company);
    }

    /**
     * Look up a company on the PEPPOL network by GLN (Global Location Number).
     *
     * @param  string  $glnNumber  The 13-digit GLN to lookup
     * @param  string  $country  ISO 3166-1 alpha-2 country code (e.g., 'NL', 'BE')
     */
    public function lookupCompanyByGln(string $glnNumber, string $country): ?Company
    {
        $this->log('info', 'Looking up company by GLN', [
            'gln_number' => $glnNumber,
            'country' => $country,
        ]);

        $company = $this->connector->lookupCompanyByGln($glnNumber, $country);

        if (! $company) {
            $this->log('warning', 'GLN lookup returned null', [
                'gln_number' => $glnNumber,
            ]);

            return null;
        }

        $this->log('info', 'GLN lookup successful', [
            'gln_number' => $glnNumber,
            'peppol_id' => $company->peppolId,
            'on_peppol' => $company->peppolId !== null,
        ]);

        return $company;
    }

    /**
     * Schedule an invoice for PEPPOL dispatch.
     *
     * When delay_days is 0, the invoice is dispatched immediately via the queue.
     *
     * @param  Model  $invoice  The invoice model to schedule
     * @param  string  $recipientVatNumber  The recipient's VAT number
     * @param  DateTimeInterface|null  $dispatchAt  When to dispatch (defaults to delay_days from config)
     * @param  bool|null  $skipDelivery  If true, invoice is stored in connector but not sent via PEPPOL.
     */
    public function scheduleInvoice(
        Model $invoice,
        string $recipientVatNumber,
        ?DateTimeInterface $dispatchAt = null,
        ?bool $skipDelivery = null,
    ): PeppolInvoice {
        $delayDays = (int) config('peppol.dispatch.delay_days', 7);

        $this->log('info', 'Scheduling invoice for PEPPOL dispatch', [
            'invoice_type' => $invoice::class,
            'invoice_id' => $invoice->getKey(),
            'recipient_vat' => $recipientVatNumber,
            'dispatch_at' => $dispatchAt?->format('Y-m-d H:i:s'),
            'skip_delivery' => $skipDelivery,
            'delay_days' => $delayDays,
            'immediate_dispatch' => $delayDays === 0 && $dispatchAt === null,
        ]);

        // Lookup company outside transaction (can be cached, has its own transaction)
        $recipientCompany = $this->lookupCompany($recipientVatNumber);

        // Only use explicit skipDelivery value - never infer from PEPPOL status
        if ($skipDelivery === null) {
            $skipDelivery = false;
        }

        $scheduledAt = $dispatchAt ?? now()->addDays($delayDays);

        // Wrap core database operations in transaction to ensure data integrity
        $peppolInvoice = DB::transaction(function () use ($invoice, $recipientCompany, $scheduledAt, $skipDelivery) {
            // Check for existing PeppolInvoice (with lock to prevent race conditions)
            $existingPeppolInvoice = PeppolInvoice::query()
                ->where('invoiceable_type', $invoice::class)
                ->where('invoiceable_id', $invoice->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingPeppolInvoice) {
                // Check if rescheduling is allowed
                if (! $existingPeppolInvoice->state->canReschedule()) {
                    $this->log('warning', 'Cannot reschedule invoice - state does not allow it', [
                        'peppol_invoice_id' => $existingPeppolInvoice->id,
                        'state' => $existingPeppolInvoice->state->value,
                    ]);

                    throw new RuntimeException(
                        "Cannot reschedule invoice in state '{$existingPeppolInvoice->state->value}'. Only scheduled or send_failed invoices can be rescheduled."
                    );
                }

                $this->log('info', 'Found existing PeppolInvoice - will reschedule', [
                    'peppol_invoice_id' => $existingPeppolInvoice->id,
                    'current_state' => $existingPeppolInvoice->state->value,
                ]);
            }

            $scheduleData = [
                'recipient_peppol_company_id' => $recipientCompany?->id,
                'scheduled_at' => $scheduledAt,
                'state' => PeppolState::SCHEDULED,
                'skip_delivery' => $skipDelivery,
                'error_message' => null,
                'error_details' => null,
                'dispatch_attempts' => 0,
                'next_retry_at' => null,
            ];

            if ($existingPeppolInvoice) {
                $existingPeppolInvoice->update($scheduleData);

                $existingPeppolInvoice->logs()->create([
                    'from_state' => $existingPeppolInvoice->state->value,
                    'to_state' => PeppolState::SCHEDULED->value,
                    'message' => 'Invoice rescheduled',
                    'actor' => auth()->user()?->email ?? 'system',
                ]);

                $this->log('info', 'Existing PeppolInvoice updated', [
                    'peppol_invoice_id' => $existingPeppolInvoice->id,
                    'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                ]);

                return $existingPeppolInvoice->fresh();
            }

            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => $invoice::class,
                'invoiceable_id' => $invoice->getKey(),
                'connector_type' => config('peppol.default_connector', 'scrada'),
                ...$scheduleData,
            ]);

            $peppolInvoice->logs()->create([
                'from_state' => null,
                'to_state' => PeppolState::SCHEDULED->value,
                'message' => 'Invoice scheduled for dispatch',
                'actor' => auth()->user()?->email ?? 'system',
            ]);

            $this->log('info', 'New PeppolInvoice created', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'skip_delivery' => $skipDelivery,
            ]);

            return $peppolInvoice;
        });

        // Dispatch immediately if delay_days is 0 and no explicit dispatchAt was provided
        if ($delayDays === 0 && $dispatchAt === null) {
            $this->log('info', 'Dispatching invoice immediately (delay_days=0)', [
                'peppol_invoice_id' => $peppolInvoice->id,
            ]);

            DispatchPeppolInvoice::dispatch($peppolInvoice->id);
        }

        return $peppolInvoice;
    }

    /**
     * Dispatch an invoice immediately.
     *
     * Uses the model's state transition methods for clean state management.
     */
    public function dispatchInvoice(PeppolInvoice $peppolInvoice, Invoice $invoiceData): InvoiceStatus
    {
        // Ensure recipient company is loaded for state determination
        $peppolInvoice->loadMissing('recipientCompany');

        $this->log('info', 'Dispatching invoice', [
            'peppol_invoice_id' => $peppolInvoice->id,
            'invoice_number' => $invoiceData->invoiceNumber,
            'recipient_vat' => $invoiceData->recipientVatNumber,
            'current_state' => $peppolInvoice->state->value,
            'attempt' => $peppolInvoice->dispatch_attempts + 1,
            'recipient_on_peppol' => $peppolInvoice->recipientCompany?->isOnPeppol() ?? 'unknown',
        ]);

        // If already has a connector ID (sent before), poll for status instead
        if ($peppolInvoice->connector_invoice_id) {
            // Handle special "existing:" prefix (invoice existed in connector before we sent)
            if (str_starts_with($peppolInvoice->connector_invoice_id, 'existing:')) {
                $this->log('info', 'Invoice already existed in connector - skipping', [
                    'peppol_invoice_id' => $peppolInvoice->id,
                ]);

                return new InvoiceStatus(
                    connectorInvoiceId: $peppolInvoice->connector_invoice_id,
                    status: $this->mapStateToPeppolStatus($peppolInvoice->state),
                    updatedAt: new DateTimeImmutable,
                    message: 'Invoice already existed in connector',
                );
            }

            $this->log('info', 'Invoice already has connector ID - polling status', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'connector_invoice_id' => $peppolInvoice->connector_invoice_id,
            ]);

            return $this->getInvoiceStatus($peppolInvoice);
        }

        // Mark as sending (increments dispatch_attempts)
        $peppolInvoice->markAsSending();

        // Dry-run mode: log payload and skip actual send
        if (config('peppol.dispatch.dry_run', false)) {
            $this->log('warning', 'DRY-RUN MODE: Skipping actual dispatch', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'invoice_number' => $invoiceData->invoiceNumber,
                'invoice_data' => $invoiceData->toArray(),
            ]);

            // Reset to scheduled state so it can be retried when dry-run is disabled
            $peppolInvoice->update([
                'state' => PeppolState::SCHEDULED,
                'dispatch_attempts' => $peppolInvoice->dispatch_attempts - 1,
            ]);

            return new InvoiceStatus(
                connectorInvoiceId: 'dry-run',
                status: Enums\PeppolStatus::CREATED,
                updatedAt: new DateTimeImmutable,
                message: 'Dry-run mode - invoice not sent',
            );
        }

        try {
            $status = $this->connector->sendInvoice($invoiceData);

            // Mark as sent successfully
            $peppolInvoice->markAsSent($status->connectorInvoiceId, $status->message);

            $this->log('info', 'Invoice dispatched successfully', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'connector_invoice_id' => $status->connectorInvoiceId,
                'status' => $status->status->value,
            ]);

            return $status;
        } catch (Exception $e) {
            // Extract structured error data
            $errorDetails = null;
            if ($e instanceof PeppolException && $e->getContext()) {
                $errorDetails = $e->getContext();
            }

            // Mark as send failed (will set state to send_failed or failed based on attempts)
            $peppolInvoice->markAsSendFailed($e->getMessage(), $errorDetails);

            $this->log('error', 'Connector upload failed', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'attempt' => $peppolInvoice->dispatch_attempts,
                'max_attempts' => config('peppol.dispatch.max_attempts', 3),
                'error' => $e->getMessage(),
                'can_retry' => $peppolInvoice->canRetry(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the current status of a PEPPOL invoice.
     */
    public function getInvoiceStatus(PeppolInvoice $peppolInvoice): InvoiceStatus
    {
        $this->log('debug', 'Getting invoice status', [
            'peppol_invoice_id' => $peppolInvoice->id,
            'connector_invoice_id' => $peppolInvoice->connector_invoice_id,
            'current_state' => $peppolInvoice->state->value,
        ]);

        if (! $peppolInvoice->connector_invoice_id) {
            throw new RuntimeException('Invoice has not been dispatched yet');
        }

        // Handle special "existing:" prefix
        if (str_starts_with($peppolInvoice->connector_invoice_id, 'existing:')) {
            return new InvoiceStatus(
                connectorInvoiceId: $peppolInvoice->connector_invoice_id,
                status: $this->mapStateToPeppolStatus($peppolInvoice->state),
                updatedAt: new DateTimeImmutable,
                message: 'Invoice already existed in connector - status cannot be polled',
            );
        }

        $status = $this->connector->getInvoiceStatus($peppolInvoice->connector_invoice_id);
        $newState = $this->mapPeppolStatusToState($status->status);

        // Check if recipient is not on PEPPOL - invoice is stored but not delivered via PEPPOL
        if ($status->recipientNotOnPeppol) {
            $newState = PeppolState::STORED;

            $this->log('info', 'Recipient not on PEPPOL - marking as stored', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'message' => $status->message,
            ]);
        }

        // Provide meaningful message for polling state
        $message = $status->message;
        if ($message === null && $newState === PeppolState::POLLING) {
            $message = 'Scrada is still processing - awaiting delivery confirmation';
        }

        // Update state if changed
        if ($newState !== $peppolInvoice->state) {
            $this->log('info', 'Invoice status changed', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'old_state' => $peppolInvoice->state->value,
                'new_state' => $newState->value,
            ]);

            $peppolInvoice->updateDeliveryStatus($newState, $message);
        } elseif ($newState === PeppolState::POLLING) {
            // Log every poll attempt even if state unchanged
            $peppolInvoice->logs()->create([
                'from_state' => PeppolState::POLLING->value,
                'to_state' => PeppolState::POLLING->value,
                'message' => $message,
                'actor' => 'poll',
            ]);
        }

        // Schedule next poll if still pending
        if ($newState->shouldPoll()) {
            $peppolInvoice->scheduleNextPoll();
        }

        return $status;
    }

    /**
     * Get the UBL file for a dispatched invoice.
     */
    public function getUblFile(PeppolInvoice $peppolInvoice): string
    {
        if (! $peppolInvoice->connector_invoice_id) {
            throw new RuntimeException('Invoice has not been dispatched yet');
        }

        return $this->connector->getUblFile($peppolInvoice->connector_invoice_id);
    }

    /**
     * Map PeppolState to legacy PeppolStatus for connector compatibility.
     */
    private function mapStateToPeppolStatus(PeppolState $state): Enums\PeppolStatus
    {
        return match ($state) {
            PeppolState::SCHEDULED, PeppolState::SENDING, PeppolState::SEND_FAILED => Enums\PeppolStatus::PENDING,
            PeppolState::SENT, PeppolState::POLLING => Enums\PeppolStatus::CREATED,
            PeppolState::DELIVERED, PeppolState::STORED => Enums\PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
            PeppolState::ACCEPTED => Enums\PeppolStatus::ACCEPTED,
            PeppolState::REJECTED => Enums\PeppolStatus::REJECTED,
            PeppolState::FAILED, PeppolState::CANCELLED => Enums\PeppolStatus::FAILED_DELIVERY,
        };
    }

    /**
     * Map legacy PeppolStatus to new PeppolState.
     */
    private function mapPeppolStatusToState(Enums\PeppolStatus $status): PeppolState
    {
        return match ($status) {
            Enums\PeppolStatus::PENDING, Enums\PeppolStatus::CREATED => PeppolState::POLLING,
            Enums\PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION => PeppolState::DELIVERED,
            Enums\PeppolStatus::ACCEPTED => PeppolState::ACCEPTED,
            Enums\PeppolStatus::REJECTED => PeppolState::REJECTED,
            Enums\PeppolStatus::FAILED_DELIVERY => PeppolState::FAILED,
        };
    }

    /**
     * Cache a company lookup result.
     */
    private function cacheCompany(Company $company): PeppolCompany
    {
        return PeppolCompany::updateOrCreate(
            ['vat_number' => $company->vatNumber],
            [
                'peppol_id' => $company->peppolId,
                'name' => $company->name,
                'country' => $company->country,
                'is_active' => true,
                'last_lookup_at' => now(),
            ]
        );
    }

    /**
     * Check if a cached company lookup is still valid.
     */
    private function isCacheValid(PeppolCompany $company): bool
    {
        if (! $company->last_lookup_at) {
            return false;
        }

        $cacheHours = config('peppol.lookup.cache_hours', 168);

        return $company->last_lookup_at->diffInHours(now()) < $cacheHours;
    }
}
