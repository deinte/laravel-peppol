<?php

declare(strict_types=1);

namespace Deinte\Peppol\Events;

use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an invoice is successfully dispatched to PEPPOL.
 */
class InvoiceDispatched
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PeppolInvoice $peppolInvoice,
    ) {}
}
