<?php

declare(strict_types=1);

namespace Deinte\Peppol\Events;

use Deinte\Peppol\Models\PeppolCompany;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a company is found on the PEPPOL network.
 */
class CompanyFoundOnPeppol
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PeppolCompany $company,
    ) {}
}
