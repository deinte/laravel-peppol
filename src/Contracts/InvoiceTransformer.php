<?php

declare(strict_types=1);

namespace Deinte\Peppol\Contracts;

use Deinte\Peppol\Data\Invoice;
use Illuminate\Database\Eloquent\Model;

/**
 * Transforms application invoices to PEPPOL format.
 *
 * Implement this interface to define how your application's invoice
 * models should be converted to the PEPPOL invoice format.
 */
interface InvoiceTransformer
{
    /**
     * Transform an application invoice to PEPPOL invoice data.
     *
     * @param  Model  $invoice  Your application's invoice model
     * @return Invoice The PEPPOL-formatted invoice data
     *
     * @throws \Deinte\Peppol\Exceptions\InvalidInvoiceException
     */
    public function toPeppolInvoice(Model $invoice): Invoice;
}
