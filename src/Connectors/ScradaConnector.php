<?php

declare(strict_types=1);

namespace Deinte\Peppol\Connectors;

use DateTimeImmutable;
use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\EasCode;
use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Exceptions\ConnectorException;
use Deinte\Peppol\Exceptions\InvalidInvoiceException;
use Deinte\Peppol\Exceptions\InvoiceNotFoundException;
use Deinte\ScradaSdk\Data\Common\Address;
use Deinte\ScradaSdk\Data\Common\Attachment;
use Deinte\ScradaSdk\Data\Common\Customer;
use Deinte\ScradaSdk\Data\SalesInvoice\CreateSalesInvoiceData;
use Deinte\ScradaSdk\Data\SalesInvoice\InvoiceLine;
use Deinte\ScradaSdk\Data\SalesInvoice\InvoicePaymentMethod;
use Deinte\ScradaSdk\Data\SalesInvoice\SendStatusResponse;
use Deinte\ScradaSdk\Data\VatTypeId;
use Deinte\ScradaSdk\Enums\SendStatus;
use Deinte\ScradaSdk\Enums\TaxNumberType;
use Deinte\ScradaSdk\Enums\VatType;
use Deinte\ScradaSdk\Exceptions\NotFoundException;
use Deinte\ScradaSdk\Exceptions\ScradaException;
use Deinte\ScradaSdk\Scrada;
use Exception;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Scrada implementation of the PEPPOL connector.
 *
 * Integrates with the Scrada API for PEPPOL electronic invoicing.
 */
class ScradaConnector implements PeppolConnector
{
    private readonly Scrada $client;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $companyId,
        ?string $baseUrl = null,
    ) {
        $this->client = new Scrada(
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            companyId: $companyId,
            baseUrl: $baseUrl,
        );

        $this->log('debug', 'ScradaConnector initialized', [
            'company_id' => $companyId,
            'base_url' => $baseUrl ?? 'default',
        ]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('peppol')->{$level}("[ScradaConnector] {$message}", $context);
    }

    /**
     * Lookup a company on the PEPPOL network.
     *
     * For countries with tax number schemes (BE, NL, FR), first tries lookup by tax number.
     * If not found, falls back to VAT number lookup.
     *
     * @param  string  $vatNumber  The VAT number to lookup
     * @param  string|null  $taxNumber  Optional tax/enterprise number (e.g., KvK, CBE)
     * @param  string|null  $country  ISO 3166-1 alpha-2 country code
     */
    public function lookupCompany(
        string $vatNumber,
        ?string $taxNumber = null,
        ?string $country = null,
    ): ?Company {
        $company = new Company(
            vatNumber: $vatNumber,
            country: $country ?? $this->guessCountryFromVat($vatNumber),
            taxNumber: $taxNumber,
        );

        $lookupIdentifier = $company->getLookupIdentifier();
        $lookupScheme = $company->getLookupScheme();
        $usedTaxNumberLookup = $this->mapEasSchemeToScradaTaxType($lookupScheme) !== null;

        // Use the derived lookup identifier as tax number if different from VAT
        // This captures the enterprise number for BE, KvK for NL, etc.
        $derivedTaxNumber = $taxNumber ?? ($lookupIdentifier !== $vatNumber ? $lookupIdentifier : null);

        $this->log('info', 'API: Looking up company on PEPPOL network', [
            'vat_number' => $vatNumber,
            'tax_number' => $derivedTaxNumber,
            'country' => $company->country,
            'lookup_identifier' => $lookupIdentifier,
            'lookup_scheme' => $lookupScheme?->value,
            'using_tax_number_lookup' => $usedTaxNumberLookup,
        ]);

        $startTime = microtime(true);

        try {
            // First attempt: lookup by tax number (enterprise number) if applicable
            $customerForLookup = $this->buildLookupCustomer($lookupIdentifier, $lookupScheme, $vatNumber);

            $this->log('debug', 'API: Scrada lookupParty customer', [
                'customer' => $customerForLookup->toArray(),
            ]);

            $result = $this->client->peppol()->lookupParty($customerForLookup);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log('debug', 'API: Scrada lookupParty response received', [
                'vat_number' => $vatNumber,
                'lookup_identifier' => $lookupIdentifier,
                'duration_ms' => $duration,
                'can_receive_invoices' => $result->canReceiveInvoices(),
                'meta' => $result->meta ?? [],
            ]);

            // If not found with tax number, try fallback to VAT number
            if (! $result->canReceiveInvoices() && $usedTaxNumberLookup) {
                $this->log('info', 'API: Tax number lookup returned not found, trying VAT number fallback', [
                    'vat_number' => $vatNumber,
                    'lookup_identifier' => $lookupIdentifier,
                ]);

                $fallbackResult = $this->lookupByVatNumberOnly($vatNumber, $company->country);

                if ($fallbackResult !== null && $fallbackResult->canReceiveInvoices()) {
                    $totalDuration = round((microtime(true) - $startTime) * 1000, 2);
                    $peppolId = $this->buildPeppolId(
                        EasCode::vatSchemeForCountry($company->country),
                        $vatNumber,
                        $fallbackResult->meta
                    );

                    $this->log('info', 'API: Company found on PEPPOL network via VAT fallback', [
                        'vat_number' => $vatNumber,
                        'peppol_id' => $peppolId,
                        'duration_ms' => $totalDuration,
                    ]);

                    return new Company(
                        vatNumber: $vatNumber,
                        peppolId: $peppolId,
                        country: $company->country,
                        taxNumber: $derivedTaxNumber,
                        taxNumberScheme: EasCode::vatSchemeForCountry($company->country),
                        metadata: $fallbackResult->meta,
                    );
                }
            }

            if (! $result->canReceiveInvoices()) {
                $this->log('info', 'API: Company NOT on PEPPOL network', [
                    'vat_number' => $vatNumber,
                    'lookup_identifier' => $lookupIdentifier,
                    'tax_number' => $derivedTaxNumber,
                    'duration_ms' => $duration,
                ]);

                return new Company(
                    vatNumber: $vatNumber,
                    peppolId: null,
                    country: $company->country,
                    taxNumber: $derivedTaxNumber,
                    taxNumberScheme: $lookupScheme,
                );
            }

            $peppolId = $this->buildPeppolId($lookupScheme, $lookupIdentifier, $result->meta);

            $this->log('info', 'API: Company found on PEPPOL network', [
                'vat_number' => $vatNumber,
                'peppol_id' => $peppolId,
                'tax_number' => $derivedTaxNumber,
                'duration_ms' => $duration,
            ]);

            return new Company(
                vatNumber: $vatNumber,
                peppolId: $peppolId,
                country: $company->country,
                taxNumber: $derivedTaxNumber,
                taxNumberScheme: $lookupScheme,
                metadata: $result->meta,
            );
        } catch (ScradaException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $responseData = $e->getResponseData();

            $this->log('error', 'API: Scrada API error during company lookup', [
                'vat_number' => $vatNumber,
                'lookup_identifier' => $lookupIdentifier,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_data' => $responseData,
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode(), $responseData);
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log('error', 'API: Connection failed during company lookup', [
                'vat_number' => $vatNumber,
                'lookup_identifier' => $lookupIdentifier,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::connectionFailed($e->getMessage());
        }
    }

    /**
     * Lookup by VAT number only (fallback when tax number lookup fails).
     *
     * Tries multiple VAT number formats:
     * 1. Full VAT number with prefix (e.g., BE0833557226)
     * 2. VAT number without prefix (e.g., 0833557226)
     */
    private function lookupByVatNumberOnly(string $vatNumber, ?string $country): ?object
    {
        // Try with full VAT number first (with country prefix)
        $result = $this->doVatLookup($vatNumber, $country);

        if ($result !== null && $result->canReceiveInvoices()) {
            return $result;
        }

        // Try without country prefix (e.g., "0833557226" instead of "BE0833557226")
        $vatWithoutPrefix = preg_replace('/^[A-Z]{2}/i', '', $vatNumber);
        if ($vatWithoutPrefix !== $vatNumber) {
            $this->log('debug', 'API: Trying VAT lookup without country prefix', [
                'original' => $vatNumber,
                'without_prefix' => $vatWithoutPrefix,
            ]);

            $result = $this->doVatLookup($vatWithoutPrefix, $country);

            if ($result !== null && $result->canReceiveInvoices()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Perform the actual VAT lookup API call.
     *
     * Includes the PEPPOL participant ID in standard format using VAT scheme.
     */
    private function doVatLookup(string $vatNumber, ?string $country): ?object
    {
        $effectiveCountry = $country ?? $this->guessCountryFromVat($vatNumber) ?? 'BE';

        // Get VAT scheme for country (e.g., 9925 for Belgium)
        $vatScheme = null;
        try {
            $vatScheme = EasCode::vatSchemeForCountry($effectiveCountry);
        } catch (InvalidArgumentException) {
            // No VAT scheme for country
        }

        // Build peppolID in standard format if we have a VAT scheme
        $peppolID = $vatScheme !== null ? "{$vatScheme->value}:{$vatNumber}" : null;

        $customer = new Customer(
            name: 'Lookup',
            address: new Address(
                street: '-',
                streetNumber: '-',
                city: '-',
                zipCode: '-',
                countryCode: $effectiveCountry,
            ),
            peppolID: $peppolID,
            vatNumber: $vatNumber,
        );

        $this->log('debug', 'API: Scrada lookupParty VAT fallback customer', [
            'customer' => $customer->toArray(),
        ]);

        try {
            $result = $this->client->peppol()->lookupParty($customer);

            $this->log('debug', 'API: Scrada lookupParty VAT fallback response', [
                'vat_number' => $vatNumber,
                'can_receive_invoices' => $result->canReceiveInvoices(),
                'meta' => $result->meta ?? [],
            ]);

            return $result;
        } catch (Exception $e) {
            $this->log('warning', 'API: VAT fallback lookup failed', [
                'vat_number' => $vatNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a Customer object for PEPPOL lookup.
     *
     * Uses tax number lookup for BE/NL/FR when applicable, falls back to VAT number.
     */
    private function buildLookupCustomer(string $identifier, ?EasCode $scheme, string $vatNumber): Customer
    {
        $countryCode = $scheme?->countryCode() ?? $this->guessCountryFromVat($vatNumber) ?? 'BE';
        $taxNumberType = $this->mapEasSchemeToScradaTaxType($scheme);

        // Build peppolID in standard format (e.g., "0208:0833557226")
        $peppolID = $scheme !== null ? "{$scheme->value}:{$identifier}" : null;

        return new Customer(
            name: 'Lookup',
            address: new Address(
                street: '-',
                streetNumber: '-',
                city: '-',
                zipCode: '-',
                countryCode: $countryCode,
            ),
            peppolID: $peppolID,
            taxNumberType: $taxNumberType,
            taxNumber: $taxNumberType !== null ? $identifier : null,
            vatNumber: $taxNumberType === null ? $vatNumber : null,
        );
    }

    /**
     * Map EAS scheme to Scrada's TaxNumberType enum.
     */
    private function mapEasSchemeToScradaTaxType(?EasCode $scheme): ?TaxNumberType
    {
        if ($scheme === null) {
            return null;
        }

        return match ($scheme) {
            EasCode::BE_CBE => TaxNumberType::ENTERPRISE_NUMBER_BE,
            EasCode::NL_KVK => TaxNumberType::KVK_NL,
            EasCode::FR_SIRET, EasCode::SIRENE => TaxNumberType::SIRENE_FR,
            default => null,
        };
    }

    /**
     * Build the full Peppol ID from scheme and identifier.
     */
    private function buildPeppolId(?EasCode $scheme, string $identifier, array $meta): ?string
    {
        if (isset($meta['peppolId'])) {
            return $meta['peppolId'];
        }

        if (isset($meta['peppol_id'])) {
            return $meta['peppol_id'];
        }

        if ($scheme !== null) {
            return "{$scheme->value}:{$identifier}";
        }

        return null;
    }

    /**
     * Guess the country code from a VAT number prefix.
     */
    private function guessCountryFromVat(string $vatNumber): ?string
    {
        if (preg_match('/^([A-Z]{2})/i', $vatNumber, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    public function sendInvoice(Invoice $invoice): InvoiceStatus
    {
        $this->log('info', 'API: Sending invoice to Scrada', [
            'invoice_number' => $invoice->invoiceNumber,
            'recipient_vat' => $invoice->recipientVatNumber,
            'total_amount' => $invoice->totalAmount,
            'line_items_count' => count($invoice->lineItems),
        ]);

        $startTime = microtime(true);

        try {
            $invoiceData = $this->transformInvoiceToScradaFormat($invoice);

            $this->log('debug', 'API: Invoice transformed to Scrada format', [
                'invoice_number' => $invoice->invoiceNumber,
                'book_year' => $invoiceData->bookYear,
                'total_incl_vat' => $invoiceData->totalInclVat,
                'total_excl_vat' => $invoiceData->totalExclVat,
                'lines_count' => count($invoiceData->lines),
            ]);

            $this->log('debug', 'API: Full invoice payload being sent', [
                'invoice_number' => $invoice->invoiceNumber,
                'payload' => $invoiceData->toArray(),
            ]);

            $response = $this->client->salesInvoices()->create($invoiceData);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $status = $this->mapScradaStatus($response->status);

            $this->log('info', 'API: Invoice sent successfully', [
                'invoice_number' => $invoice->invoiceNumber,
                'scrada_invoice_id' => $response->id,
                'scrada_status' => $response->status,
                'mapped_status' => $status->value,
                'duration_ms' => $duration,
            ]);

            return new InvoiceStatus(
                connectorInvoiceId: $response->id,
                status: $status,
                updatedAt: new DateTimeImmutable,
                metadata: ['scrada_response' => $response->toArray()],
            );
        } catch (ScradaException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $responseData = $e->getResponseData();

            // Check if invoice already exists (error code 110365)
            if ($this->isInvoiceAlreadyExistsError($responseData)) {
                $this->log('info', 'API: Invoice already exists in Scrada - treating as success', [
                    'invoice_number' => $invoice->invoiceNumber,
                    'duration_ms' => $duration,
                ]);

                // Return final status so it won't be polled
                // We can't poll because Scrada doesn't return the actual invoice ID
                return new InvoiceStatus(
                    connectorInvoiceId: "existing:{$invoice->invoiceNumber}",
                    status: PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
                    updatedAt: new DateTimeImmutable,
                    message: 'Invoice already exists in Scrada - status unknown',
                    metadata: [
                        'already_existed' => true,
                        'scrada_response' => $responseData,
                    ],
                );
            }

            $this->log('error', 'API: Scrada API error during invoice send', [
                'invoice_number' => $invoice->invoiceNumber,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_body' => $e->getResponseBody(),
                'response_data' => $responseData,
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode(), $responseData);
        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log('error', 'API: Failed to send invoice', [
                'invoice_number' => $invoice->invoiceNumber,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'duration_ms' => $duration,
            ]);

            throw InvalidInvoiceException::invalidFormat('invoice', $e->getMessage());
        }
    }

    public function getInvoiceStatus(string $invoiceId): InvoiceStatus
    {
        $this->log('debug', 'API: Getting invoice status from Scrada', [
            'invoice_id' => $invoiceId,
        ]);

        $startTime = microtime(true);

        try {
            $status = $this->client->salesInvoices()->getSendStatus($invoiceId);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Map Scrada SendStatusResponse to PeppolStatus
            $peppolStatus = $this->mapSendStatusResponseToPeppolStatus($status);

            // Check if recipient is not on PEPPOL (stored but not delivered via PEPPOL)
            // NONE means no send configuration in Scrada - typically because recipient not on PEPPOL
            $recipientNotOnPeppol = in_array($status->status, [
                SendStatus::ERROR_NOT_ON_PEPPOL,
                SendStatus::NOT_ON_PEPPOL_SEND_BY_EMAIL,
                SendStatus::BLOCKED_SEND_BY_EMAIL,
                SendStatus::BLOCKED,
                SendStatus::NONE,
            ], true);

            // Use error message from response or status label for failed statuses
            $errorMessage = $status->errorMessage ?? ($peppolStatus->isFailed() ? $status->status?->label() : null);

            $this->log('debug', 'API: Invoice status retrieved', [
                'invoice_id' => $invoiceId,
                'scrada_status' => $status->status?->value,
                'is_success' => $status->isSuccess(),
                'is_error' => $status->isError(),
                'is_pending' => $status->isPending(),
                'recipient_not_on_peppol' => $recipientNotOnPeppol,
                'mapped_status' => $peppolStatus->value,
                'error_message' => $errorMessage,
                'duration_ms' => $duration,
            ]);

            return new InvoiceStatus(
                connectorInvoiceId: $invoiceId,
                status: $peppolStatus,
                updatedAt: new DateTimeImmutable,
                metadata: $status->toArray(),
                message: $errorMessage,
                recipientNotOnPeppol: $recipientNotOnPeppol,
            );
        } catch (NotFoundException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log('warning', 'API: Invoice not found in Scrada', [
                'invoice_id' => $invoiceId,
                'duration_ms' => $duration,
            ]);

            throw InvoiceNotFoundException::withId($invoiceId);
        } catch (ScradaException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $responseData = $e->getResponseData();

            $this->log('error', 'API: Scrada API error during status check', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_data' => $responseData,
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode(), $responseData);
        }
    }

    public function getUblFile(string $invoiceId): string
    {
        $this->log('debug', 'API: Getting UBL file from Scrada', [
            'invoice_id' => $invoiceId,
        ]);

        $startTime = microtime(true);

        try {
            $ubl = $this->client->salesInvoices()->getUbl($invoiceId);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log('debug', 'API: UBL file retrieved', [
                'invoice_id' => $invoiceId,
                'ubl_length' => strlen($ubl),
                'duration_ms' => $duration,
            ]);

            return $ubl;
        } catch (NotFoundException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->log('warning', 'API: UBL file not found', [
                'invoice_id' => $invoiceId,
                'duration_ms' => $duration,
            ]);

            throw InvoiceNotFoundException::withId($invoiceId);
        } catch (ScradaException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $responseData = $e->getResponseData();

            $this->log('error', 'API: Scrada API error during UBL retrieval', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_data' => $responseData,
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode(), $responseData);
        }
    }

    /**
     * Company registration is not supported by this connector.
     *
     * The Scrada connector is designed for SENDING invoices only.
     * Company registration for RECEIVING invoices must be done via Scrada portal.
     *
     * @throws RuntimeException Always throws - not supported
     */
    public function registerCompany(Company $company): bool
    {
        $this->log('warning', 'Attempted unsupported operation: registerCompany', [
            'vat_number' => $company->vatNumber,
        ]);

        throw new RuntimeException(
            'Company registration not supported by Scrada connector. '.
            'This connector is for sending invoices only. '.
            'To receive invoices, register your company via the Scrada portal.'
        );
    }

    /**
     * Retrieving received invoices is not supported by this connector.
     *
     * The Scrada connector is designed for SENDING invoices only.
     *
     * @throws RuntimeException Always throws - not supported
     */
    public function getReceivedInvoices(string $peppolId, array $filters = []): array
    {
        $this->log('warning', 'Attempted unsupported operation: getReceivedInvoices', [
            'peppol_id' => $peppolId,
        ]);

        throw new RuntimeException(
            'Retrieving received invoices not supported by Scrada connector. '.
            'This connector is for sending invoices only.'
        );
    }

    public function validateWebhookSignature(array $payload, string $signature): bool
    {
        throw new RuntimeException(
            'Webhook validation not supported by Scrada connector. '.
            'Scrada does not currently provide webhook functionality.'
        );
    }

    public function parseWebhookPayload(array $payload): array
    {
        throw new RuntimeException(
            'Webhook parsing not supported by Scrada connector. '.
            'Scrada does not currently provide webhook functionality.'
        );
    }

    public function transformInvoiceToScradaFormat(Invoice $invoice): CreateSalesInvoiceData
    {
        $customerData = $invoice->additionalData['customer'] ?? [];

        // Determine if this is a domestic invoice (same sender/recipient country)
        $customerCountry = strtoupper($customerData['address']['countryCode'] ?? 'BE');
        $senderCountry = 'BE'; // Scrada is configured for Belgian sender
        $isDomestic = $customerCountry === $senderCountry;

        $customer = new Customer(
            name: $customerData['name'] ?? '',
            address: Address::fromArray($customerData['address'] ?? []),
            code: $customerData['code'] ?? $invoice->recipientVatNumber,
            phone: $customerData['phone'] ?? null,
            email: $customerData['email'] ?? '',
            vatNumber: $customerData['vatNumber'] ?? $invoice->recipientVatNumber,
        );

        // Debug: log raw line items from transformer
        $this->log('debug', 'API: Raw line items from transformer', [
            'invoice_number' => $invoice->invoiceNumber,
            'line_items' => $invoice->lineItems,
            'is_domestic' => $isDomestic,
            'customer_country' => $customerCountry,
        ]);

        $lines = [];
        foreach ($invoice->lineItems as $index => $lineItem) {
            $lines[] = $this->createInvoiceLine($lineItem, $index + 1, $senderCountry, $customerCountry, $invoice->invoiceNumber);
        }

        // Calculate totals from line items to ensure consistency
        $totalExclVat = 0;
        $totalVat = 0;

        foreach ($invoice->lineItems as $item) {
            // Use totalExclVat from line item if provided, otherwise calculate
            $itemTotal = isset($item['totalExclVat']) ? (float) $item['totalExclVat'] : (($item['quantity'] ?? 1) * ($item['unitPrice'] ?? 0));
            $totalExclVat += $itemTotal;

            // Use vatAmount from line item if provided (this is the actual VAT from invoice rules)
            // Otherwise calculate from percentage
            if (isset($item['vatAmount'])) {
                $totalVat += (float) $item['vatAmount'];
            } else {
                $totalVat += $itemTotal * (($item['vatPerc'] ?? $item['vatPercentage'] ?? 0) / 100);
            }
        }

        // Round to 2 decimal places to avoid floating point precision issues
        $totalExclVat = round($totalExclVat, 2);
        $totalVat = round($totalVat, 2);

        $this->log('debug', 'API: Calculated totals from line items', [
            'invoice_number' => $invoice->invoiceNumber,
            'line_items_count' => count($invoice->lineItems),
            'total_excl_vat' => $totalExclVat,
            'total_vat' => $totalVat,
            'calculated_total_incl_vat' => round($totalExclVat + $totalVat, 2),
            'invoice_total_amount' => round($invoice->totalAmount, 2),
        ]);

        // Ensure totalInclVat = totalExclVat + totalVat (Scrada validates this)
        // Use the invoice's total amount if it matches, otherwise calculate from parts
        $calculatedTotalInclVat = round($totalExclVat + $totalVat, 2);
        $invoiceTotalAmount = round($invoice->totalAmount, 2);

        // If there's a mismatch, prefer the calculated values for consistency
        if (abs($invoiceTotalAmount - $calculatedTotalInclVat) > 0.01) {
            $this->log('warning', 'Total mismatch detected, using calculated values', [
                'invoice_total' => $invoiceTotalAmount,
                'calculated_total' => $calculatedTotalInclVat,
                'total_excl_vat' => $totalExclVat,
                'total_vat' => $totalVat,
            ]);
            // Adjust totalVat to make the equation work with the invoice total
            $totalVat = round($invoiceTotalAmount - $totalExclVat, 2);
        }

        $totalInclVat = round($totalExclVat + $totalVat, 2);

        // Debug: Log the InvoiceLine objects to verify vatAmount is set
        foreach ($lines as $idx => $line) {
            $this->log('debug', 'API: InvoiceLine object values', [
                'invoice_number' => $invoice->invoiceNumber,
                'line_index' => $idx,
                'line_vatPercentage' => $line->vatPercentage,
                'line_vatType' => $line->vatType,
                'line_totalExclVat' => $line->totalExclVat,
                'line_vatAmount' => $line->vatAmount,
                'line_toArray' => $line->toArray(),
            ]);
        }

        // Build attachments array
        $attachments = $this->buildAttachments($invoice);

        // Extract delivery data if provided (for Event invoices / Peppol e-invoicing)
        $deliveryData = $invoice->additionalData['delivery'] ?? [];

        return new CreateSalesInvoiceData(
            bookYear: $invoice->invoiceDate->format('Y'),
            journal: $invoice->additionalData['journal'] ?? 'SALES',
            number: $invoice->invoiceNumber,
            creditInvoice: $invoice->additionalData['creditInvoice'] ?? false,
            invoiceDate: $invoice->invoiceDate->format('Y-m-d'),
            invoiceExpiryDate: $invoice->dueDate->format('Y-m-d'),
            totalInclVat: $totalInclVat,
            totalExclVat: $totalExclVat,
            totalVat: $totalVat,
            customer: $customer,
            lines: $lines,
            alreadySentToCustomer: $invoice->alreadySentToCustomer,
            attachments: $attachments,
            paymentMethods: array_map(
                fn (array $method) => InvoicePaymentMethod::fromArray($method),
                $invoice->paymentMethods
            ),
            purchaseOrderReference: $invoice->purchaseOrderReference,
            projectReference: $invoice->projectReference,
            salesOrderReference: $invoice->salesOrderReference,
            deliveryDate: $deliveryData['date'] ?? null,
            deliveryStreet: $deliveryData['street'] ?? null,
            deliveryStreetNumber: $deliveryData['streetNumber'] ?? null,
            deliveryCity: $deliveryData['city'] ?? null,
            deliveryZipCode: $deliveryData['zipCode'] ?? null,
            deliveryCountryCode: $deliveryData['countryCode'] ?? null,
        );
    }

    /**
     * Build attachments array for the invoice.
     *
     * PDF can be provided via:
     * - pdfContent: Base64-encoded content (preferred, takes precedence)
     * - pdfPath: Local file path that will be read
     *
     * @return array<int, Attachment>
     */
    private function buildAttachments(Invoice $invoice): array
    {
        $attachments = [];

        // Option 1: Pre-encoded base64 content (preferred)
        if ($invoice->pdfContent !== null) {
            $filename = $invoice->pdfFilename ?? "{$invoice->invoiceNumber}.pdf";
            $attachments[] = Attachment::pdf($filename, $invoice->pdfContent);

            $this->log('debug', 'API: PDF attachment included (base64 content)', [
                'invoice_number' => $invoice->invoiceNumber,
                'pdf_filename' => $filename,
                'pdf_size_bytes' => strlen($invoice->pdfContent),
            ]);

            return $attachments;
        }

        // Option 2: Local file path
        if ($invoice->pdfPath !== null) {
            if (! file_exists($invoice->pdfPath)) {
                $this->log('warning', 'API: PDF file not found', [
                    'invoice_number' => $invoice->invoiceNumber,
                    'pdf_path' => $invoice->pdfPath,
                ]);

                return $attachments;
            }

            $content = file_get_contents($invoice->pdfPath);

            if ($content === false) {
                $this->log('warning', 'API: Failed to read PDF file', [
                    'invoice_number' => $invoice->invoiceNumber,
                    'pdf_path' => $invoice->pdfPath,
                ]);

                return $attachments;
            }

            $filename = $invoice->pdfFilename ?? basename($invoice->pdfPath);
            $attachments[] = Attachment::pdf($filename, base64_encode($content));

            $this->log('debug', 'API: PDF attachment included (local file)', [
                'invoice_number' => $invoice->invoiceNumber,
                'pdf_filename' => $filename,
                'pdf_size_bytes' => strlen($content),
            ]);
        }

        return $attachments;
    }

    /**
     * Map Scrada's status string to our PeppolStatus enum.
     *
     * Used for simple string statuses (e.g., from CreateSalesInvoiceResponse).
     */
    private function mapScradaStatus(string $status): PeppolStatus
    {
        return match (strtolower($status)) {
            'draft' => PeppolStatus::CREATED,
            'pending' => PeppolStatus::PENDING,
            'sent' => PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
            'accepted' => PeppolStatus::ACCEPTED,
            'rejected' => PeppolStatus::REJECTED,
            'failed' => PeppolStatus::FAILED_DELIVERY,
            default => PeppolStatus::CREATED,
        };
    }

    /**
     * Map Scrada SendStatusResponse to PeppolStatus enum.
     *
     * Uses the SDK's SendStatus enum and helper methods to determine
     * the correct PeppolStatus.
     */
    private function mapSendStatusResponseToPeppolStatus(SendStatusResponse $response): PeppolStatus
    {
        $status = $response->status;

        // Handle "not on PEPPOL" cases first - these are NOT failures
        // The invoice IS stored in Scrada, just not delivered via PEPPOL
        // NONE means no send configuration - typically because recipient not on PEPPOL
        if ($status === SendStatus::ERROR_NOT_ON_PEPPOL
            || $status === SendStatus::NOT_ON_PEPPOL_SEND_BY_EMAIL
            || $status === SendStatus::BLOCKED_SEND_BY_EMAIL
            || $status === SendStatus::NONE
        ) {
            return PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION;
        }

        // Use the response's helper methods
        if ($response->isSuccess()) {
            return PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION;
        }

        if ($response->isError()) {
            return PeppolStatus::FAILED_DELIVERY;
        }

        if ($response->isPending()) {
            return PeppolStatus::PENDING;
        }

        // Fall back to mapping the SendStatus enum directly
        if ($status === null) {
            return PeppolStatus::CREATED;
        }

        return match ($status) {
            SendStatus::CREATED => PeppolStatus::CREATED,
            SendStatus::PROCESSED => PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
            SendStatus::RETRY => PeppolStatus::PENDING,
            SendStatus::CANCELED, SendStatus::ERROR, SendStatus::ERROR_ALREADY_SENT,
            SendStatus::ERROR_SEND_BY_EMAIL, SendStatus::BLOCKED => PeppolStatus::FAILED_DELIVERY,
            // Already handled above, but for completeness
            SendStatus::ERROR_NOT_ON_PEPPOL, SendStatus::NOT_ON_PEPPOL_SEND_BY_EMAIL,
            SendStatus::BLOCKED_SEND_BY_EMAIL, SendStatus::NONE => PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
        };
    }

    /**
     * Check if Scrada error response indicates invoice already exists.
     *
     * Error structure:
     * - errorCode 100008 (parent): "There are error(s) when adding Sales invoice"
     * - innerErrors[].errorCode 110365: "Invoice number {number} in book year {year} already exists!"
     */
    private function isInvoiceAlreadyExistsError(?array $responseData): bool
    {
        if ($responseData === null) {
            return false;
        }

        // Check for parent error code 100008 with inner error 110365
        if (($responseData['errorCode'] ?? null) !== 100008) {
            return false;
        }

        foreach ($responseData['innerErrors'] ?? [] as $innerError) {
            if (($innerError['errorCode'] ?? null) === 110365) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the connector is properly configured and can reach the Scrada API.
     *
     * Performs a simple PEPPOL lookup to verify API connectivity.
     *
     * @return array{healthy: bool, message?: string, error?: string}
     */
    public function healthCheck(): array
    {
        $this->log('info', 'API: Performing health check');

        try {
            // Use a known Belgian company to verify API connectivity
            // We're not checking if the company exists, just that the API responds
            $testCustomer = new Customer(
                name: 'Health Check',
                address: new Address(
                    street: '-',
                    streetNumber: '-',
                    city: '-',
                    zipCode: '-',
                    countryCode: 'BE',
                ),
                vatNumber: 'BE0000000000',
            );

            $this->client->peppol()->lookupParty($testCustomer);

            $this->log('info', 'API: Health check successful');

            return [
                'healthy' => true,
                'message' => 'Successfully connected to Scrada API',
            ];
        } catch (ScradaException $e) {
            $this->log('error', 'API: Health check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            $this->log('error', 'API: Health check failed with unexpected error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create an invoice line with proper VAT type resolution.
     *
     * VAT country can be explicitly set per line item (e.g., for event invoices
     * where EU Article 55 requires VAT based on service location, not customer location).
     */
    private function createInvoiceLine(
        array $lineItem,
        int $lineNumber,
        string $senderCountry,
        string $customerCountry,
        string $invoiceNumber
    ): InvoiceLine {
        $vatPercentage = (float) ($lineItem['vatPerc'] ?? $lineItem['vatPercentage'] ?? 21);
        $totalExclVat = isset($lineItem['totalExclVat']) ? (float) $lineItem['totalExclVat'] : null;
        $vatAmount = isset($lineItem['vatAmount']) ? (float) $lineItem['vatAmount'] : null;

        // VAT country determines which country's VAT rules apply
        // For event/catering services, this is the event location (EU Article 55)
        $vatCountry = isset($lineItem['vatCountry'])
            ? strtoupper($lineItem['vatCountry'])
            : $customerCountry;

        $vatInfo = $this->resolveVatInfo($vatCountry, $vatPercentage, $senderCountry, $invoiceNumber);

        $this->log('debug', 'API: Creating InvoiceLine', [
            'invoice_number' => $invoiceNumber,
            'line_number' => $lineNumber,
            'vat_percentage' => $vatPercentage,
            'vat_country' => $vatCountry,
            'vat_type_id' => $vatInfo['vatTypeID'],
        ]);

        return new InvoiceLine(
            description: $lineItem['description'] ?? '',
            quantity: (float) ($lineItem['quantity'] ?? 1),
            unitPrice: (float) ($lineItem['unitPrice'] ?? 0),
            vatPercentage: $vatPercentage,
            vatType: $vatInfo['vatType'],
            lineNumber: $lineNumber,
            totalExclVat: $totalExclVat,
            vatAmount: $vatAmount,
            vatTypeID: $vatInfo['vatTypeID'],
        );
    }

    /**
     * Resolve VAT type and VAT type ID for a given country and percentage.
     *
     * @return array{vatType: VatType, vatTypeID: string|null}
     */
    private function resolveVatInfo(
        string $vatCountry,
        float $vatPercentage,
        string $senderCountry,
        string $invoiceNumber
    ): array {
        $isDomestic = $vatCountry === $senderCountry;

        $vatType = $isDomestic
            ? VatType::fromPercentageDomestic($vatPercentage)
            : VatType::fromPercentageCrossBorderB2B($vatPercentage);

        $vatTypeID = $this->resolveVatTypeId($vatCountry, $vatPercentage, $isDomestic, $invoiceNumber);

        return [
            'vatType' => $vatType,
            'vatTypeID' => $vatTypeID,
        ];
    }

    /**
     * Resolve the Scrada VAT type UUID for a country and percentage.
     */
    private function resolveVatTypeId(
        string $vatCountry,
        float $vatPercentage,
        bool $isDomestic,
        string $invoiceNumber
    ): ?string {
        if (! VatTypeId::isCountrySupported($vatCountry)) {
            return null;
        }

        try {
            // Cross-border B2B with 0% VAT uses "not applicable"
            if (! $isDomestic && $vatPercentage === 0.0) {
                return VatTypeId::notApplicable($vatCountry)->getValue();
            }

            return VatTypeId::fromCountryAndPercentage($vatCountry, $vatPercentage)->getValue();
        } catch (InvalidArgumentException $e) {
            $this->log('warning', 'API: Could not determine vatTypeID', [
                'invoice_number' => $invoiceNumber,
                'vat_country' => $vatCountry,
                'vat_percentage' => $vatPercentage,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
