<?php

declare(strict_types=1);

namespace Deinte\Peppol\Contracts;

use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use RuntimeException;

/**
 * Interface for PEPPOL network connectors.
 *
 * Implementations of this interface provide integration with different
 * PEPPOL access points or service providers (e.g., Scrada, OpenPEPPOL, etc.)
 *
 * Note: Some methods are for receiving invoices and may not be supported
 * by all connectors. Check connector documentation for supported features.
 */
interface PeppolConnector
{
    /**
     * Look up a company on the PEPPOL network by VAT number.
     *
     * @param  string  $vatNumber  The VAT number to search for (e.g., 'BE0123456789')
     * @param  string|null  $taxNumber  Optional tax/enterprise number (e.g., KvK, CBE)
     * @param  string|null  $country  ISO 3166-1 alpha-2 country code
     * @param  string|null  $glnNumber  Optional GLN as fallback if VAT lookup fails
     * @return Company|null Returns company data if found, null otherwise
     *
     * @throws \Deinte\Peppol\Exceptions\ConnectorException
     */
    public function lookupCompany(
        string $vatNumber,
        ?string $taxNumber = null,
        ?string $country = null,
        ?string $glnNumber = null,
    ): ?Company;

    /**
     * Look up a company on the PEPPOL network by GLN (Global Location Number).
     *
     * @param  string  $glnNumber  The 13-digit GLN to search for
     * @param  string  $country  ISO 3166-1 alpha-2 country code (e.g., 'NL', 'BE')
     * @return Company|null Returns company data if found, null otherwise
     *
     * @throws \Deinte\Peppol\Exceptions\ConnectorException
     */
    public function lookupCompanyByGln(string $glnNumber, string $country): ?Company;

    /**
     * Send an invoice via the PEPPOL network.
     *
     * @param  Invoice  $invoice  The invoice data to send
     * @return InvoiceStatus The initial status of the sent invoice
     *
     * @throws \Deinte\Peppol\Exceptions\InvalidInvoiceException
     * @throws \Deinte\Peppol\Exceptions\ConnectorException
     */
    public function sendInvoice(Invoice $invoice): InvoiceStatus;

    /**
     * Get the current status of a sent invoice.
     *
     * @param  string  $invoiceId  The connector's invoice identifier
     * @return InvoiceStatus Current status of the invoice
     *
     * @throws \Deinte\Peppol\Exceptions\InvoiceNotFoundException
     * @throws \Deinte\Peppol\Exceptions\ConnectorException
     */
    public function getInvoiceStatus(string $invoiceId): InvoiceStatus;

    /**
     * Retrieve the UBL (Universal Business Language) file for a sent invoice.
     *
     * @param  string  $invoiceId  The connector's invoice identifier
     * @return string The UBL XML content
     *
     * @throws \Deinte\Peppol\Exceptions\InvoiceNotFoundException
     * @throws \Deinte\Peppol\Exceptions\ConnectorException
     */
    public function getUblFile(string $invoiceId): string;

    /**
     * Register a company to receive invoices on the PEPPOL network.
     *
     * Note: This method is for RECEIVING invoices and may not be supported
     * by all connectors. Check connector documentation.
     *
     * @param  Company  $company  The company data to register
     * @return bool Returns true if registration was successful
     *
     * @throws \Deinte\Peppol\Exceptions\ConnectorException
     * @throws RuntimeException If not supported by connector
     */
    public function registerCompany(Company $company): bool;

    /**
     * Retrieve received invoices for a registered company.
     *
     * Note: This method is for RECEIVING invoices and may not be supported
     * by all connectors. Check connector documentation.
     *
     * @param  string  $peppolId  The company's PEPPOL identifier
     * @param  array  $filters  Optional filters (date range, status, etc.)
     * @return array<Invoice> Array of received invoices
     *
     * @throws \Deinte\Peppol\Exceptions\ConnectorException
     * @throws RuntimeException If not supported by connector
     */
    public function getReceivedInvoices(string $peppolId, array $filters = []): array;

    /**
     * Validate webhook signature/authenticity.
     *
     * @param  array  $payload  The webhook payload
     * @param  string  $signature  The webhook signature
     * @return bool Returns true if signature is valid
     */
    public function validateWebhookSignature(array $payload, string $signature): bool;

    /**
     * Parse webhook payload into standardized format.
     *
     * @param  array  $payload  The webhook payload
     * @return array Standardized webhook data
     *
     * @throws RuntimeException If not supported by connector
     */
    public function parseWebhookPayload(array $payload): array;

    /**
     * Check if the connector is properly configured and can reach the API.
     *
     * @return array{healthy: bool, message?: string, error?: string}
     */
    public function healthCheck(): array;
}
