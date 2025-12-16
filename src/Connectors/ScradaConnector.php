<?php

declare(strict_types=1);

namespace Deinte\Peppol\Connectors;

use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\EasCode;
use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Exceptions\ConnectorException;
use Deinte\Peppol\Exceptions\InvalidInvoiceException;
use Deinte\Peppol\Exceptions\InvoiceNotFoundException;
use Deinte\ScradaSdk\Data\Address;
use Deinte\ScradaSdk\Data\Attachment;
use Deinte\ScradaSdk\Data\CreateSalesInvoiceData;
use Deinte\ScradaSdk\Data\Customer;
use Deinte\ScradaSdk\Data\InvoiceLine;
use Deinte\ScradaSdk\Data\SendStatus;
use Deinte\ScradaSdk\Exceptions\NotFoundException;
use Deinte\ScradaSdk\Exceptions\ScradaException;
use Deinte\ScradaSdk\Scrada;
use Illuminate\Support\Facades\Log;

/**
 * Scrada implementation of the PEPPOL connector.
 *
 * Integrates with the Scrada API for PEPPOL electronic invoicing.
 */
class ScradaConnector implements PeppolConnector
{
    private const SCRADA_TAX_TYPE_BE_CBE = 1;

    private const SCRADA_TAX_TYPE_NL_KVK = 2;

    private const SCRADA_TAX_TYPE_FR_SIRET = 3;

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
            $payload = $this->buildLookupPayload($lookupIdentifier, $lookupScheme, $vatNumber);

            $this->log('debug', 'API: Scrada lookupParty payload', [
                'payload' => $payload,
            ]);

            $result = $this->client->peppol()->lookupParty($payload);

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

            $this->log('error', 'API: Scrada API error during company lookup', [
                'vat_number' => $vatNumber,
                'lookup_identifier' => $lookupIdentifier,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
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
        } catch (\InvalidArgumentException) {
            // No VAT scheme for country
        }

        $payload = [
            'name' => 'Lookup',
            'address' => [
                'street' => '-',
                'streetNumber' => '-',
                'city' => '-',
                'zipCode' => '-',
                'countryCode' => $effectiveCountry,
            ],
            'vatNumber' => $vatNumber,
        ];

        // Include the PEPPOL participant ID in standard format
        if ($vatScheme !== null) {
            $payload['peppolId'] = "{$vatScheme->value}:{$vatNumber}";
        }

        $this->log('debug', 'API: Scrada lookupParty VAT fallback payload', [
            'payload' => $payload,
        ]);

        try {
            $result = $this->client->peppol()->lookupParty($payload);

            $this->log('debug', 'API: Scrada lookupParty VAT fallback response', [
                'vat_number' => $vatNumber,
                'can_receive_invoices' => $result->canReceiveInvoices(),
                'meta' => $result->meta ?? [],
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->log('warning', 'API: VAT fallback lookup failed', [
                'vat_number' => $vatNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build the Scrada lookup payload based on the scheme.
     *
     * Uses the PEPPOL participant identifier format: {EAS_CODE}:{IDENTIFIER}
     * For example: 0208:0833557226 (Belgian enterprise number)
     *              9925:BE0833557226 (Belgian VAT number)
     */
    private function buildLookupPayload(string $identifier, ?EasCode $scheme, string $vatNumber): array
    {
        $countryCode = $scheme?->countryCode() ?? $this->guessCountryFromVat($vatNumber) ?? 'BE';

        $payload = [
            'name' => 'Lookup',
            'address' => [
                'street' => '-',
                'streetNumber' => '-',
                'city' => '-',
                'zipCode' => '-',
                'countryCode' => $countryCode,
            ],
        ];

        $taxNumberType = $this->mapEasSchemeToScradaTaxType($scheme);

        if ($taxNumberType !== null) {
            $payload['taxNumberType'] = $taxNumberType;
            $payload['taxNumber'] = $identifier;
        } else {
            $payload['vatNumber'] = $vatNumber;
        }

        // Also include the PEPPOL participant ID in standard format
        if ($scheme !== null) {
            $payload['peppolId'] = "{$scheme->value}:{$identifier}";
        }

        return $payload;
    }

    /**
     * Map EAS scheme to Scrada's internal taxNumberType.
     */
    private function mapEasSchemeToScradaTaxType(?EasCode $scheme): ?int
    {
        if ($scheme === null) {
            return null;
        }

        return match ($scheme) {
            EasCode::BE_CBE => self::SCRADA_TAX_TYPE_BE_CBE,
            EasCode::NL_KVK => self::SCRADA_TAX_TYPE_NL_KVK,
            EasCode::FR_SIRET, EasCode::SIRENE => self::SCRADA_TAX_TYPE_FR_SIRET,
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
                updatedAt: new \DateTimeImmutable,
                metadata: ['scrada_response' => $response->toArray()],
            );
        } catch (ScradaException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Capture additional response details for debugging
            $responseBody = method_exists($e, 'getResponseBody') ? $e->getResponseBody() : null;
            $responseData = method_exists($e, 'getResponseData') ? $e->getResponseData() : null;

            $this->log('error', 'API: Scrada API error during invoice send', [
                'invoice_number' => $invoice->invoiceNumber,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'response_body' => $responseBody,
                'response_data' => $responseData,
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
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

            // Map Scrada status string to PeppolStatus
            // Priority: check status string first, then boolean flags
            $peppolStatus = $this->mapScradaStatusToPeppolStatus($status);

            // Extract error message from metadata if present
            $errorMessage = $status->meta['errorMessage'] ?? null;
            if (empty($errorMessage) && $peppolStatus->isFailed()) {
                $errorMessage = $status->status; // Use status string as error message
            }

            $this->log('debug', 'API: Invoice status retrieved', [
                'invoice_id' => $invoiceId,
                'scrada_status' => $status->status,
                'pending' => $status->pending,
                'peppol_sent' => $status->peppolSent,
                'mapped_status' => $peppolStatus->value,
                'error_message' => $errorMessage,
                'duration_ms' => $duration,
            ]);

            return new InvoiceStatus(
                connectorInvoiceId: $invoiceId,
                status: $peppolStatus,
                updatedAt: new \DateTimeImmutable,
                metadata: $status->meta,
                message: $errorMessage,
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

            $this->log('error', 'API: Scrada API error during status check', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode());
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

            $this->log('error', 'API: Scrada API error during UBL retrieval', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'duration_ms' => $duration,
            ]);

            throw ConnectorException::apiError($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Company registration is not supported by this connector.
     *
     * The Scrada connector is designed for SENDING invoices only.
     * Company registration for RECEIVING invoices must be done via Scrada portal.
     *
     * @throws \RuntimeException Always throws - not supported
     */
    public function registerCompany(Company $company): bool
    {
        $this->log('warning', 'Attempted unsupported operation: registerCompany', [
            'vat_number' => $company->vatNumber,
        ]);

        throw new \RuntimeException(
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
     * @throws \RuntimeException Always throws - not supported
     */
    public function getReceivedInvoices(string $peppolId, array $filters = []): array
    {
        $this->log('warning', 'Attempted unsupported operation: getReceivedInvoices', [
            'peppol_id' => $peppolId,
        ]);

        throw new \RuntimeException(
            'Retrieving received invoices not supported by Scrada connector. '.
            'This connector is for sending invoices only.'
        );
    }

    public function validateWebhookSignature(array $payload, string $signature): bool
    {
        $this->log('debug', 'Validating webhook signature', [
            'payload_keys' => array_keys($payload),
        ]);

        // TODO: Webhook signature validation depends on Scrada's webhook implementation
        // For now, return true to allow webhook processing
        // This should be implemented once Scrada provides webhook signature details
        return true;
    }

    public function parseWebhookPayload(array $payload): array
    {
        $this->log('debug', 'Parsing webhook payload', [
            'payload' => $payload,
        ]);

        // TODO: Parse Scrada webhook payload into standardized format
        // This depends on the webhook structure Scrada sends
        // For now, return a basic structure
        return [
            'invoice_id' => $payload['invoiceId'] ?? $payload['invoice_id'] ?? null,
            'status' => $this->mapScradaStatus($payload['status'] ?? 'PENDING'),
            'message' => $payload['message'] ?? null,
            'metadata' => $payload,
        ];
    }

    private function transformInvoiceToScradaFormat(Invoice $invoice): CreateSalesInvoiceData
    {
        $customerData = $invoice->additionalData['customer'] ?? [];

        $customer = new Customer(
            code: $customerData['code'] ?? $invoice->recipientVatNumber,
            name: $customerData['name'] ?? '',
            email: $customerData['email'] ?? '',
            vatNumber: $invoice->recipientVatNumber,
            address: Address::fromArray($customerData['address'] ?? []),
            phone: $customerData['phone'] ?? null,
        );

        // Debug: log raw line items from transformer
        $this->log('debug', 'API: Raw line items from transformer', [
            'invoice_number' => $invoice->invoiceNumber,
            'line_items' => $invoice->lineItems,
        ]);

        $lineNumber = 0;
        $lines = array_map(function ($lineItem) use (&$lineNumber, $invoice) {
            $lineNumber++;
            $vatPercentage = (float) ($lineItem['vatPerc'] ?? $lineItem['vatPercentage'] ?? 21);
            $totalExclVat = isset($lineItem['totalExclVat']) ? (float) $lineItem['totalExclVat'] : null;
            $vatAmount = isset($lineItem['vatAmount']) ? (float) $lineItem['vatAmount'] : null;

            $this->log('debug', 'API: Creating InvoiceLine', [
                'invoice_number' => $invoice->invoiceNumber,
                'line_number' => $lineNumber,
                'vatPercentage' => $vatPercentage,
                'totalExclVat' => $totalExclVat,
                'vatAmount' => $vatAmount,
                'raw_vatPerc' => $lineItem['vatPerc'] ?? 'NOT SET',
                'raw_vatAmount' => $lineItem['vatAmount'] ?? 'NOT SET',
            ]);

            return new InvoiceLine(
                description: $lineItem['description'] ?? '',
                quantity: (float) ($lineItem['quantity'] ?? 1),
                unitPrice: (float) ($lineItem['unitPrice'] ?? 0),
                vatPercentage: $vatPercentage,
                vatType: InvoiceLine::vatPercentageToType($vatPercentage),
                lineNumber: $lineNumber,
                totalExclVat: $totalExclVat,
                vatAmount: $vatAmount,
            );
        }, $invoice->lineItems);

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
        );
    }

    /**
     * Build attachments array for the invoice.
     *
     * @return array<int, Attachment>
     */
    private function buildAttachments(Invoice $invoice): array
    {
        $attachments = [];

        // Try local path first
        if ($invoice->pdfPath !== null && file_exists($invoice->pdfPath)) {
            $filename = basename($invoice->pdfPath);
            $content = file_get_contents($invoice->pdfPath);

            if ($content !== false) {
                $attachments[] = Attachment::pdf($filename, base64_encode($content));

                $this->log('debug', 'API: PDF attachment included (local file)', [
                    'invoice_number' => $invoice->invoiceNumber,
                    'pdf_filename' => $filename,
                    'pdf_size_bytes' => filesize($invoice->pdfPath),
                ]);
            }

            return $attachments;
        }

        // Fallback to URL
        if ($invoice->pdfUrl !== null) {
            try {
                $this->log('debug', 'API: Fetching PDF from URL', [
                    'invoice_number' => $invoice->invoiceNumber,
                    'pdf_url' => $invoice->pdfUrl,
                ]);

                $pdfContent = @file_get_contents($invoice->pdfUrl);

                if ($pdfContent !== false) {
                    $filename = basename(parse_url($invoice->pdfUrl, PHP_URL_PATH) ?? "{$invoice->invoiceNumber}.pdf");
                    $attachments[] = Attachment::pdf($filename, base64_encode($pdfContent));

                    $this->log('debug', 'API: PDF attachment included (from URL)', [
                        'invoice_number' => $invoice->invoiceNumber,
                        'pdf_filename' => $filename,
                        'pdf_size_bytes' => strlen($pdfContent),
                    ]);
                } else {
                    $this->log('warning', 'API: Failed to fetch PDF from URL', [
                        'invoice_number' => $invoice->invoiceNumber,
                        'pdf_url' => $invoice->pdfUrl,
                    ]);
                }
            } catch (\Exception $e) {
                $this->log('warning', 'API: Exception fetching PDF from URL', [
                    'invoice_number' => $invoice->invoiceNumber,
                    'pdf_url' => $invoice->pdfUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $attachments;
    }

    /**
     * Map Scrada's status string to our PeppolStatus enum.
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
            default => PeppolStatus::PENDING,
        };
    }

    /**
     * Map Scrada SendStatus to PeppolStatus enum.
     *
     * This method considers both the status string and boolean flags
     * to determine the correct PeppolStatus. Error statuses like
     * "Error not on Peppol" are properly handled.
     */
    private function mapScradaStatusToPeppolStatus(SendStatus $status): PeppolStatus
    {
        $statusLower = strtolower($status->status);

        // Check for error status strings first
        if (str_contains($statusLower, 'error')) {
            return PeppolStatus::FAILED_DELIVERY;
        }

        // Check for rejection
        if (str_contains($statusLower, 'reject')) {
            return PeppolStatus::REJECTED;
        }

        // Check boolean flags
        if ($status->peppolSent) {
            return PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION;
        }

        if ($status->pending) {
            return PeppolStatus::PENDING;
        }

        // Map known status strings
        return match ($statusLower) {
            'draft', 'created' => PeppolStatus::CREATED,
            'sent', 'delivered' => PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
            'accepted' => PeppolStatus::ACCEPTED,
            'rejected' => PeppolStatus::REJECTED,
            'failed' => PeppolStatus::FAILED_DELIVERY,
            default => PeppolStatus::CREATED,
        };
    }
}
