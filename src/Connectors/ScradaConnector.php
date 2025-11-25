<?php

declare(strict_types=1);

namespace Deinte\Peppol\Connectors;

use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Exceptions\ConnectorException;
use Deinte\Peppol\Exceptions\InvalidInvoiceException;
use Deinte\Peppol\Exceptions\InvalidWebhookException;
use Deinte\Peppol\Exceptions\InvoiceNotFoundException;
use Deinte\Peppol\Exceptions\RegistrationException;
use Deinte\ScradaSdk\Dto\Address;
use Deinte\ScradaSdk\Dto\CreateSalesInvoiceData;
use Deinte\ScradaSdk\Dto\Customer;
use Deinte\ScradaSdk\Dto\InvoiceLine;
use Deinte\ScradaSdk\Exceptions\NotFoundException;
use Deinte\ScradaSdk\Exceptions\ScradaException;
use Deinte\ScradaSdk\Scrada;

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
    }

    public function lookupCompany(string $vatNumber): ?Company
    {
        try {
            // Create a minimal customer payload for lookup
            $result = $this->client->peppol()->lookupParty([
                'vatNumber' => $vatNumber,
            ]);

            // If the company can receive invoices, they're on PEPPOL
            if (! $result->canReceiveInvoices) {
                return new Company(
                    vatNumber: $vatNumber,
                    peppolId: null,
                );
            }

            // Extract PEPPOL ID from metadata if available
            $peppolId = $result->meta['peppolId'] ?? $result->meta['peppol_id'] ?? null;

            return new Company(
                vatNumber: $vatNumber,
                peppolId: $peppolId,
                metadata: $result->meta,
            );
        } catch (ScradaException $e) {
            throw ConnectorException::apiError($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            throw ConnectorException::connectionFailed($e->getMessage());
        }
    }

    public function sendInvoice(Invoice $invoice): InvoiceStatus
    {
        try {
            // Transform our Invoice DTO to Scrada's format
            $invoiceData = $this->transformInvoiceToScradaFormat($invoice);

            // Send the invoice
            $response = $this->client->salesInvoices()->create($invoiceData);

            // Map Scrada's status to our standardized status
            $status = $this->mapScradaStatus($response->status);

            return new InvoiceStatus(
                connectorInvoiceId: $response->id,
                status: $status,
                updatedAt: new \DateTimeImmutable(),
                metadata: ['scrada_response' => $response->toArray()],
            );
        } catch (ScradaException $e) {
            throw ConnectorException::apiError($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            throw InvalidInvoiceException::invalidFormat('invoice', $e->getMessage());
        }
    }

    public function getInvoiceStatus(string $invoiceId): InvoiceStatus
    {
        try {
            $status = $this->client->salesInvoices()->getSendStatus($invoiceId);

            // Determine status based on Scrada's SendStatus
            $peppolStatus = match (true) {
                $status->pending => PeppolStatus::PENDING,
                $status->peppolSent => PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
                default => PeppolStatus::CREATED,
            };

            return new InvoiceStatus(
                connectorInvoiceId: $invoiceId,
                status: $peppolStatus,
                updatedAt: new \DateTimeImmutable(),
                metadata: $status->meta,
            );
        } catch (NotFoundException $e) {
            throw InvoiceNotFoundException::withId($invoiceId);
        } catch (ScradaException $e) {
            throw ConnectorException::apiError($e->getMessage(), $e->getCode());
        }
    }

    public function getUblFile(string $invoiceId): string
    {
        try {
            return $this->client->salesInvoices()->getUbl($invoiceId);
        } catch (NotFoundException $e) {
            throw InvoiceNotFoundException::withId($invoiceId);
        } catch (ScradaException $e) {
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
        throw new \RuntimeException(
            'Retrieving received invoices not supported by Scrada connector. '.
            'This connector is for sending invoices only.'
        );
    }

    public function validateWebhookSignature(array $payload, string $signature): bool
    {
        // TODO: Webhook signature validation depends on Scrada's webhook implementation
        // For now, return true to allow webhook processing
        // This should be implemented once Scrada provides webhook signature details
        return true;
    }

    public function parseWebhookPayload(array $payload): array
    {
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

    /**
     * Transform our Invoice DTO to Scrada's CreateSalesInvoiceData format.
     */
    private function transformInvoiceToScradaFormat(Invoice $invoice): CreateSalesInvoiceData
    {
        // Parse customer data from additional data if available
        $customerData = $invoice->additionalData['customer'] ?? [];

        // Create customer DTO
        $customer = new Customer(
            code: $customerData['code'] ?? $invoice->recipientVatNumber,
            name: $customerData['name'] ?? '',
            email: $customerData['email'] ?? '',
            vatNumber: $invoice->recipientVatNumber,
            address: Address::fromArray($customerData['address'] ?? []),
            phone: $customerData['phone'] ?? null,
        );

        // Transform line items to Scrada's InvoiceLine format
        $lines = array_map(function ($lineItem) {
            return new InvoiceLine(
                description: $lineItem['description'] ?? '',
                quantity: (float) ($lineItem['quantity'] ?? 1),
                unitPrice: (float) ($lineItem['unitPrice'] ?? 0),
                vatPercentage: (float) ($lineItem['vatPerc'] ?? $lineItem['vatPercentage'] ?? 0),
                vatTypeId: $lineItem['vatTypeID'] ?? $lineItem['vatTypeId'] ?? '',
                categoryId: $lineItem['categoryID'] ?? $lineItem['categoryId'] ?? null,
                amountExclVat: isset($lineItem['amountExclVat']) ? (float) $lineItem['amountExclVat'] : null,
                amountInclVat: isset($lineItem['amountInclVat']) ? (float) $lineItem['amountInclVat'] : null,
            );
        }, $invoice->lineItems);

        // Calculate totals from line items
        $totalExclVat = 0;
        $totalVat = 0;

        foreach ($invoice->lineItems as $item) {
            $itemTotal = ($item['quantity'] ?? 1) * ($item['unitPrice'] ?? 0);
            $totalExclVat += $itemTotal;
            $totalVat += $itemTotal * (($item['vatPerc'] ?? $item['vatPercentage'] ?? 0) / 100);
        }

        return new CreateSalesInvoiceData(
            bookYear: $invoice->invoiceDate->format('Y'),
            journal: $invoice->additionalData['journal'] ?? 'SALES',
            number: $invoice->invoiceNumber,
            creditInvoice: $invoice->additionalData['creditInvoice'] ?? false,
            invoiceDate: $invoice->invoiceDate->format('Y-m-d'),
            invoiceExpiryDate: $invoice->dueDate->format('Y-m-d'),
            totalInclVat: $invoice->totalAmount,
            totalExclVat: $totalExclVat,
            totalVat: $totalVat,
            customer: $customer,
            lines: $lines,
            alreadySentToCustomer: $invoice->alreadySentToCustomer,
        );
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
}
