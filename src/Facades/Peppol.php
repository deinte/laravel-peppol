<?php

declare(strict_types=1);

namespace Deinte\Peppol\Facades;

use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;
use Deinte\Peppol\Models\PeppolCompany;
use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PeppolCompany|null lookupCompany(string $vatNumber, bool $forceRefresh = false, ?string $taxNumber = null, ?string $country = null)
 * @method static PeppolInvoice scheduleInvoice(Model $invoice, string $recipientVatNumber, ?\DateTimeInterface $dispatchAt = null, ?bool $skipPeppolDelivery = null)
 * @method static InvoiceStatus dispatchInvoice(PeppolInvoice $peppolInvoice, Invoice $invoiceData)
 * @method static InvoiceStatus getInvoiceStatus(PeppolInvoice $peppolInvoice)
 * @method static string getUblFile(PeppolInvoice $peppolInvoice)
 *
 * @see \Deinte\Peppol\PeppolService
 */
class Peppol extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'peppol';
    }
}
