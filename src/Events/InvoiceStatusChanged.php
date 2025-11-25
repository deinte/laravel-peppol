<?php

declare(strict_types=1);

namespace Deinte\Peppol\Events;

use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a PEPPOL invoice status changes.
 */
class InvoiceStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PeppolInvoice $peppolInvoice,
        public readonly array $webhookData = [],
    ) {}
}
