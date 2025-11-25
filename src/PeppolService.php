<?php

declare(strict_types=1);

namespace Deinte\Peppol;

use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Models\PeppolCompany;
use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Look up a company on the PEPPOL network and cache the result.
     */
    public function lookupCompany(string $vatNumber, bool $forceRefresh = false): ?PeppolCompany
    {
        // Check cache first unless force refresh
        if (! $forceRefresh) {
            $cached = PeppolCompany::findByVatNumber($vatNumber);

            if ($cached && $this->isCacheValid($cached)) {
                return $cached;
            }
        }

        // Perform lookup via connector
        $company = $this->connector->lookupCompany($vatNumber);

        if (! $company) {
            return null;
        }

        // Cache the result
        return $this->cacheCompany($company);
    }

    /**
     * Schedule an invoice for PEPPOL dispatch.
     */
    public function scheduleInvoice(
        Model $invoice,
        string $recipientVatNumber,
        ?\DateTimeInterface $dispatchAt = null
    ): PeppolInvoice {
        // Look up recipient company
        $recipientCompany = $this->lookupCompany($recipientVatNumber);

        if (! $recipientCompany || ! $recipientCompany->isOnPeppol()) {
            throw new \RuntimeException("Recipient {$recipientVatNumber} is not on PEPPOL");
        }

        // Create PEPPOL invoice record
        return PeppolInvoice::create([
            'invoiceable_type' => $invoice::class,
            'invoiceable_id' => $invoice->getKey(),
            'recipient_peppol_company_id' => $recipientCompany->id,
            'scheduled_dispatch_at' => $dispatchAt ?? now()->addDays(config('peppol.dispatch.delay_days', 7)),
            'status' => PeppolStatus::PENDING,
        ]);
    }

    /**
     * Dispatch an invoice immediately.
     */
    public function dispatchInvoice(PeppolInvoice $peppolInvoice, Invoice $invoiceData): InvoiceStatus
    {
        // Send via connector
        $status = $this->connector->sendInvoice($invoiceData);

        // Update PEPPOL invoice record
        $peppolInvoice->update([
            'connector_invoice_id' => $status->connectorInvoiceId,
            'dispatched_at' => now(),
        ]);

        // Update status
        $peppolInvoice->updateStatus(
            status: $status->status,
            message: $status->message,
            metadata: $status->metadata,
        );

        return $status;
    }

    /**
     * Get the current status of a PEPPOL invoice.
     */
    public function getInvoiceStatus(PeppolInvoice $peppolInvoice): InvoiceStatus
    {
        if (! $peppolInvoice->connector_invoice_id) {
            throw new \RuntimeException('Invoice has not been dispatched yet');
        }

        $status = $this->connector->getInvoiceStatus($peppolInvoice->connector_invoice_id);

        // Update local status if changed
        if ($status->status !== $peppolInvoice->status) {
            $peppolInvoice->updateStatus(
                status: $status->status,
                message: $status->message,
                metadata: $status->metadata,
            );
        }

        return $status;
    }

    /**
     * Get the UBL file for a dispatched invoice.
     */
    public function getUblFile(PeppolInvoice $peppolInvoice): string
    {
        if (! $peppolInvoice->connector_invoice_id) {
            throw new \RuntimeException('Invoice has not been dispatched yet');
        }

        return $this->connector->getUblFile($peppolInvoice->connector_invoice_id);
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
                'email' => $company->email,
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
}
