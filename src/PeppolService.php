<?php

declare(strict_types=1);

namespace Deinte\Peppol;

use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Exceptions\PeppolException;
use Deinte\Peppol\Models\PeppolCompany;
use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        // Normalize the VAT number (remove spaces, dots, dashes)
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
                    'cache_age_hours' => $cached->last_lookup_at?->diffInHours(now()),
                ]);

                return $cached;
            }

            if ($cached) {
                $this->log('debug', 'Cache expired - will refresh', [
                    'vat_number' => $vatNumber,
                    'cached_at' => $cached->last_lookup_at?->toIso8601String(),
                    'cache_age_hours' => $cached->last_lookup_at?->diffInHours(now()),
                    'cache_max_hours' => config('peppol.lookup.cache_hours', 168),
                ]);
            } else {
                $this->log('debug', 'Cache miss - no cached entry found', [
                    'vat_number' => $vatNumber,
                ]);
            }
        } else {
            $this->log('debug', 'Force refresh requested - skipping cache', [
                'vat_number' => $vatNumber,
            ]);
        }

        $this->log('debug', 'Calling connector to lookup company', [
            'vat_number' => $vatNumber,
            'tax_number' => $taxNumber,
            'country' => $country,
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
            'name' => $company->name,
            'country' => $company->country,
            'tax_number' => $company->taxNumber,
            'tax_number_scheme' => $company->taxNumberScheme?->value,
        ]);

        return $this->cacheCompany($company);
    }

    /**
     * Schedule an invoice for PEPPOL dispatch.
     *
     * If the invoice already has a pending PeppolInvoice record (not yet dispatched),
     * it will be updated instead of creating a duplicate.
     *
     * @param  Model  $invoice  The invoice model to schedule
     * @param  string  $recipientVatNumber  The recipient's VAT number
     * @param  \DateTimeInterface|null  $dispatchAt  When to dispatch (defaults to delay_days from config)
     * @param  bool|null  $skipPeppolDelivery  If true, invoice is stored in connector but not sent via PEPPOL.
     *                                         If null, automatically determined based on recipient PEPPOL status.
     */
    public function scheduleInvoice(
        Model $invoice,
        string $recipientVatNumber,
        ?\DateTimeInterface $dispatchAt = null,
        ?bool $skipPeppolDelivery = null,
    ): PeppolInvoice {
        $this->log('info', 'Scheduling invoice for PEPPOL dispatch', [
            'invoice_type' => $invoice::class,
            'invoice_id' => $invoice->getKey(),
            'recipient_vat' => $recipientVatNumber,
            'dispatch_at' => $dispatchAt?->format('Y-m-d H:i:s'),
            'skip_peppol_delivery' => $skipPeppolDelivery,
        ]);

        // Check for any existing PeppolInvoice (pending or dispatched)
        $existingPeppolInvoice = PeppolInvoice::query()
            ->where('invoiceable_type', $invoice::class)
            ->where('invoiceable_id', $invoice->getKey())
            ->first();

        if ($existingPeppolInvoice) {
            // If already dispatched successfully, return the existing record
            if ($existingPeppolInvoice->dispatched_at && $existingPeppolInvoice->connector_status === 'SUCCESS') {
                $this->log('info', 'Invoice already dispatched - returning existing PeppolInvoice', [
                    'peppol_invoice_id' => $existingPeppolInvoice->id,
                    'invoice_id' => $invoice->getKey(),
                    'connector_invoice_id' => $existingPeppolInvoice->connector_invoice_id,
                ]);

                return $existingPeppolInvoice;
            }

            $this->log('info', 'Found existing PeppolInvoice - updating instead of creating', [
                'peppol_invoice_id' => $existingPeppolInvoice->id,
                'invoice_id' => $invoice->getKey(),
                'dispatched_at' => $existingPeppolInvoice->dispatched_at,
                'connector_status' => $existingPeppolInvoice->connector_status,
            ]);
        }

        $recipientCompany = $this->lookupCompany($recipientVatNumber);
        $isOnPeppol = $recipientCompany?->isOnPeppol() ?? false;

        if ($skipPeppolDelivery === null) {
            $skipPeppolDelivery = ! $isOnPeppol;
        }

        if (! $isOnPeppol) {
            $this->log('info', 'Recipient not on PEPPOL network - invoice will be stored but not delivered via PEPPOL', [
                'recipient_vat' => $recipientVatNumber,
                'company_found' => $recipientCompany !== null,
                'peppol_id' => $recipientCompany?->peppol_id,
                'skip_peppol_delivery' => $skipPeppolDelivery,
            ]);
        }

        $scheduleData = [
            'recipient_peppol_company_id' => $recipientCompany?->id,
            'scheduled_dispatch_at' => $dispatchAt ?? now()->addDays(config('peppol.dispatch.delay_days', 7)),
            'status' => PeppolStatus::PENDING,
            'skip_peppol_delivery' => $skipPeppolDelivery,
        ];

        if ($existingPeppolInvoice) {
            $existingPeppolInvoice->update($scheduleData);
            $peppolInvoice = $existingPeppolInvoice->fresh();

            $this->log('info', 'Existing PeppolInvoice updated', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'invoice_id' => $invoice->getKey(),
                'recipient_vat' => $recipientVatNumber,
                'scheduled_dispatch_at' => $peppolInvoice->scheduled_dispatch_at?->format('Y-m-d H:i:s'),
                'skip_peppol_delivery' => $skipPeppolDelivery,
                'is_on_peppol' => $isOnPeppol,
            ]);
        } else {
            $peppolInvoice = PeppolInvoice::create([
                'invoiceable_type' => $invoice::class,
                'invoiceable_id' => $invoice->getKey(),
                ...$scheduleData,
            ]);

            $this->log('info', 'New PeppolInvoice created', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'invoice_id' => $invoice->getKey(),
                'recipient_vat' => $recipientVatNumber,
                'scheduled_dispatch_at' => $peppolInvoice->scheduled_dispatch_at?->format('Y-m-d H:i:s'),
                'skip_peppol_delivery' => $skipPeppolDelivery,
                'is_on_peppol' => $isOnPeppol,
            ]);
        }

        return $peppolInvoice;
    }

    /**
     * Dispatch an invoice immediately.
     */
    public function dispatchInvoice(PeppolInvoice $peppolInvoice, Invoice $invoiceData): InvoiceStatus
    {
        $this->log('info', 'Dispatching invoice', [
            'peppol_invoice_id' => $peppolInvoice->id,
            'invoice_number' => $invoiceData->invoiceNumber,
            'recipient_vat' => $invoiceData->recipientVatNumber,
            'total_amount' => $invoiceData->totalAmount,
        ]);

        $connectorType = config('peppol.default_connector', 'scrada');
        $existingConnectorId = $peppolInvoice->connector_invoice_id;

        // If invoice already existed in connector (no real ID available), do nothing
        if ($existingConnectorId && str_starts_with($existingConnectorId, 'existing:')) {
            $this->log('info', 'Invoice already existed in connector - skipping dispatch', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'connector_invoice_id' => $existingConnectorId,
            ]);

            return new InvoiceStatus(
                connectorInvoiceId: $existingConnectorId,
                status: $peppolInvoice->status,
                updatedAt: $peppolInvoice->updated_at ? new \DateTimeImmutable($peppolInvoice->updated_at->toDateTimeString()) : new \DateTimeImmutable,
                message: 'Invoice already existed in connector - no action taken',
            );
        }

        // If invoice has a real connector ID, poll for status instead of re-sending
        if ($existingConnectorId) {
            $this->log('info', 'Invoice already has connector ID - polling for status', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'connector_invoice_id' => $existingConnectorId,
            ]);

            return $this->getInvoiceStatus($peppolInvoice);
        }

        // Capture the request payload (sanitized to remove base64 content)
        $requestPayload = $this->sanitizePayload($invoiceData->toArray());

        try {
            $status = $this->connector->sendInvoice($invoiceData);

            // Wrap updates in transaction to ensure consistency
            DB::transaction(function () use ($peppolInvoice, $status, $connectorType, $requestPayload) {
                $peppolInvoice->update([
                    'connector_invoice_id' => $status->connectorInvoiceId,
                    'connector_type' => $connectorType,
                    'connector_status' => 'SUCCESS',
                    'connector_error' => null,
                    'connector_uploaded_at' => now(),
                    'dispatched_at' => now(),
                    'request_payload' => $requestPayload,
                ]);

                $peppolInvoice->updateStatus(
                    status: $status->status,
                    message: $status->message,
                    metadata: $status->metadata,
                );
            });

            $this->log('info', 'Invoice dispatched successfully', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'connector_type' => $connectorType,
                'connector_invoice_id' => $status->connectorInvoiceId,
                'status' => $status->status->value,
                'message' => $status->message,
            ]);

            return $status;
        } catch (\Exception $e) {
            // Extract structured error data from exception context (if available)
            $errorData = $e instanceof PeppolException && $e->getContext()
                ? [
                    'message' => $e->getMessage(),
                    'context' => $e->getContext(),
                ]
                : $e->getMessage();

            // Track failed connector upload (still save the payload for debugging)
            $peppolInvoice->update([
                'connector_type' => $connectorType,
                'connector_status' => 'FAILED',
                'connector_error' => is_array($errorData) ? json_encode($errorData) : $errorData,
                'dispatched_at' => now(),
                'request_payload' => $requestPayload,
            ]);

            $this->log('error', 'Connector upload failed', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'connector_type' => $connectorType,
                'error' => $e->getMessage(),
                'error_context' => $e instanceof PeppolException ? $e->getContext() : null,
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
            'current_status' => $peppolInvoice->status->value,
        ]);

        if (! $peppolInvoice->connector_invoice_id) {
            $this->log('error', 'Cannot get status - invoice not dispatched', [
                'peppol_invoice_id' => $peppolInvoice->id,
            ]);

            throw new \RuntimeException('Invoice has not been dispatched yet');
        }

        // Handle invoices that already existed in connector (we don't have a real ID to poll)
        if (str_starts_with($peppolInvoice->connector_invoice_id, 'existing:')) {
            $this->log('info', 'Invoice has existing: prefix - returning current status without polling', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'connector_invoice_id' => $peppolInvoice->connector_invoice_id,
            ]);

            return new InvoiceStatus(
                connectorInvoiceId: $peppolInvoice->connector_invoice_id,
                status: $peppolInvoice->status,
                updatedAt: $peppolInvoice->updated_at ? new \DateTimeImmutable($peppolInvoice->updated_at->toDateTimeString()) : new \DateTimeImmutable,
                message: 'Invoice already existed in connector - status cannot be polled',
            );
        }

        $status = $this->connector->getInvoiceStatus($peppolInvoice->connector_invoice_id);

        // Save the poll response for debugging
        $pollResponse = [
            'status' => $status->status->value,
            'message' => $status->message,
            'metadata' => $status->metadata,
            'polled_at' => now()->toIso8601String(),
        ];
        $peppolInvoice->update(['poll_response' => $pollResponse]);

        if ($status->status !== $peppolInvoice->status) {
            $this->log('info', 'Invoice status changed', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'old_status' => $peppolInvoice->status->value,
                'new_status' => $status->status->value,
                'message' => $status->message,
            ]);

            $peppolInvoice->updateStatus(
                status: $status->status,
                message: $status->message,
                metadata: $status->metadata,
            );
        } else {
            $this->log('debug', 'Invoice status unchanged', [
                'peppol_invoice_id' => $peppolInvoice->id,
                'status' => $status->status->value,
            ]);
        }

        return $status;
    }

    /**
     * Get the UBL file for a dispatched invoice.
     */
    public function getUblFile(PeppolInvoice $peppolInvoice): string
    {
        $this->log('debug', 'Getting UBL file', [
            'peppol_invoice_id' => $peppolInvoice->id,
            'connector_invoice_id' => $peppolInvoice->connector_invoice_id,
        ]);

        if (! $peppolInvoice->connector_invoice_id) {
            $this->log('error', 'Cannot get UBL - invoice not dispatched', [
                'peppol_invoice_id' => $peppolInvoice->id,
            ]);

            throw new \RuntimeException('Invoice has not been dispatched yet');
        }

        return $this->connector->getUblFile($peppolInvoice->connector_invoice_id);
    }

    /**
     * Cache a company lookup result.
     */
    private function cacheCompany(Company $company): PeppolCompany
    {
        $this->log('debug', 'Caching company lookup result', [
            'vat_number' => $company->vatNumber,
            'peppol_id' => $company->peppolId,
            'tax_number' => $company->taxNumber,
            'tax_number_scheme' => $company->taxNumberScheme?->value,
        ]);

        return PeppolCompany::updateOrCreate(
            ['vat_number' => $company->vatNumber],
            [
                'peppol_id' => $company->peppolId,
                'name' => $company->name,
                'country' => $company->country,
                'email' => $company->email,
                'tax_number' => $company->taxNumber,
                'tax_number_scheme' => $company->taxNumberScheme,
                'metadata' => $company->metadata,
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

    /**
     * Sanitize a payload array by removing base64 content.
     *
     * This prevents storing large binary data in the database while
     * still preserving the payload structure for debugging.
     */
    private function sanitizePayload(array $data): array
    {
        return $this->recursiveSanitize($data);
    }

    /**
     * Recursively sanitize an array, replacing base64 content with placeholders.
     */
    private function recursiveSanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->recursiveSanitize($value);
            } elseif (is_string($value)) {
                // Check if this looks like base64 content (longer than 500 chars and valid base64)
                if (strlen($value) > 500 && $this->looksLikeBase64($value)) {
                    $data[$key] = '[BASE64_CONTENT_REMOVED:'.strlen($value).'_bytes]';
                }
            }
        }

        return $data;
    }

    /**
     * Check if a string looks like base64-encoded content.
     */
    private function looksLikeBase64(string $value): bool
    {
        // Check if string only contains base64 characters
        if (! preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
            return false;
        }

        // Check if length is valid for base64 (should be divisible by 4)
        if (strlen($value) % 4 !== 0) {
            return false;
        }

        // Try to decode a small portion to verify
        $decoded = base64_decode(substr($value, 0, 100), true);

        return $decoded !== false;
    }
}
