# Laravel PEPPOL

A flexible Laravel package for sending electronic invoices via the PEPPOL network with support for multiple connectors.

> **📖 NEW TO THIS PACKAGE?** Start with the [Getting Started Guide](GETTING_STARTED.md) for a complete step-by-step setup tutorial!

## Features

- **Send Invoices via PEPPOL**: Reliably dispatch invoices to any company on the PEPPOL network
- **Connector Architecture**: Swap between different PEPPOL service providers with ease
- **Default Scrada Integration**: Out-of-the-box integration with Scrada's PEPPOL API
- **Automatic Company Lookups**: Cache and validate PEPPOL participant status
- **Scheduled Dispatch**: Configure delayed invoice sending (e.g., 7 days after creation)
- **Status Tracking**: Complete audit trail of invoice lifecycle with polling support
- **Event-Driven**: Extensible through Laravel events
- **Polymorphic Design**: Works with any invoice model in your application

> **Note:** This package is currently designed for **sending invoices only**. Receiving invoices is not supported by the Scrada connector.

## Requirements

- PHP 8.3+
- Laravel 10.x, 11.x, or 12.x

## Installation

Install the package via Composer:

```bash
composer require deinte/laravel-peppol
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="peppol-migrations"
php artisan migrate
```

Publish the config file:

```bash
php artisan vendor:publish --tag="peppol-config"
```

## Configuration

Add your Scrada credentials to `.env`:

```env
SCRADA_API_KEY=your-api-key
SCRADA_API_SECRET=your-api-secret
SCRADA_COMPANY_ID=your-company-uuid

# Optional: Use test environment
SCRADA_BASE_URL=https://apitest.scrada.be

# Optional: Customize dispatch behavior
PEPPOL_DISPATCH_DELAY=7  # Days to wait before sending
PEPPOL_MAX_RETRIES=3
PEPPOL_RETRY_DELAY=60     # Minutes
```

## Usage Overview

> **📖 Detailed guide available:** See [GETTING_STARTED.md](GETTING_STARTED.md) for step-by-step instructions with full examples.

### 1. Implement the Invoice Transformer

Create a transformer that converts your invoice model to PEPPOL format:

```php
use Deinte\Peppol\Contracts\InvoiceTransformer;
use Deinte\Peppol\Data\Invoice as PeppolInvoice;
use Illuminate\Database\Eloquent\Model;

class MyInvoiceTransformer implements InvoiceTransformer
{
    public function toPeppolInvoice(Model $invoice): PeppolInvoice
    {
        return new PeppolInvoice(
            senderVatNumber: config('company.vat_number'),
            recipientVatNumber: $invoice->customer->vat_number,
            recipientPeppolId: $invoice->customer->peppol_id,
            invoiceNumber: $invoice->number,
            invoiceDate: $invoice->date,
            dueDate: $invoice->due_date,
            totalAmount: $invoice->total,
            currency: $invoice->currency,
            lineItems: $invoice->lines->map(fn($line) => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unit_price,
                'vatPerc' => $line->vat_percentage,
            ])->toArray(),
            pdfPath: $invoice->pdf_path,
            alreadySentToCustomer: $invoice->email_sent,
        );
    }
}
```

Register it in a service provider:

```php
use Deinte\Peppol\Contracts\InvoiceTransformer;

$this->app->bind(InvoiceTransformer::class, MyInvoiceTransformer::class);
```

### 2. Look Up Companies on PEPPOL

Check if a company is registered on the PEPPOL network:

```php
use Deinte\Peppol\Facades\Peppol;

// Lookup and cache company
$company = Peppol::lookupCompany('BE0123456789');

if ($company && $company->isOnPeppol()) {
    echo "Company is on PEPPOL with ID: {$company->peppol_id}";
}

// Force refresh from network
$company = Peppol::lookupCompany('BE0123456789', forceRefresh: true);
```

### 3. Schedule Invoice Dispatch

Schedule an invoice to be sent via PEPPOL:

```php
use Deinte\Peppol\Facades\Peppol;

$invoice = Invoice::find(1);

// Schedule with default delay (7 days)
$peppolInvoice = Peppol::scheduleInvoice(
    invoice: $invoice,
    recipientVatNumber: 'BE0123456789'
);

// Schedule with custom dispatch time
$peppolInvoice = Peppol::scheduleInvoice(
    invoice: $invoice,
    recipientVatNumber: 'BE0123456789',
    dispatchAt: now()->addDays(14)
);
```

### 4. Set Up Automatic Dispatch (Cron)

Create a scheduled command to dispatch pending invoices:

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        \Deinte\Peppol\Models\PeppolInvoice::readyToDispatch()
            ->each(function ($peppolInvoice) {
                \Deinte\Peppol\Jobs\DispatchPeppolInvoice::dispatch(
                    $peppolInvoice->id
                );
            });
    })->daily();
}
```

### 5. Manual Dispatch

Dispatch an invoice immediately:

```php
use Deinte\Peppol\Facades\Peppol;
use Deinte\Peppol\Jobs\DispatchPeppolInvoice;

// Via job (recommended)
DispatchPeppolInvoice::dispatch($peppolInvoice->id);

// Synchronously (for testing)
$transformer = app(\Deinte\Peppol\Contracts\InvoiceTransformer::class);
$invoiceData = $transformer->toPeppolInvoice($invoice);
$status = Peppol::dispatchInvoice($peppolInvoice, $invoiceData);
```

### 6. Check Invoice Status

Poll for invoice status updates:

```php
use Deinte\Peppol\Facades\Peppol;

$status = Peppol::getInvoiceStatus($peppolInvoice);

echo "Status: {$status->status->label()}";  // "Delivered", "Accepted", etc.

if ($status->status->isDelivered()) {
    echo "Invoice successfully delivered!";
}
```

**Set up periodic status checks:**

```php
// In your scheduler (app/Console/Kernel.php)
protected function schedule(Schedule $schedule)
{
    // Check status of pending/delivered invoices every hour
    $schedule->call(function () {
        \Deinte\Peppol\Models\PeppolInvoice::whereIn('status', [
            \Deinte\Peppol\Enums\PeppolStatus::PENDING,
            \Deinte\Peppol\Enums\PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
        ])->each(function ($peppolInvoice) {
            try {
                \Deinte\Peppol\Facades\Peppol::getInvoiceStatus($peppolInvoice);
            } catch (\Exception $e) {
                \Log::error("Failed to check PEPPOL status: {$e->getMessage()}");
            }
        });
    })->hourly();
}
```

### 7. Retrieve UBL Files

```php
use Deinte\Peppol\Facades\Peppol;

$ubl = Peppol::getUblFile($peppolInvoice);

// Save to disk
Storage::put("invoices/ubl/{$invoice->number}.xml", $ubl);
```

### 8. Listen to Events

Hook into the invoice lifecycle:

```php
use Deinte\Peppol\Events\InvoiceDispatched;
use Deinte\Peppol\Events\InvoiceStatusChanged;
use Deinte\Peppol\Events\InvoiceFailed;
use Deinte\Peppol\Events\CompanyFoundOnPeppol;

// In EventServiceProvider
protected $listen = [
    InvoiceDispatched::class => [
        SendInvoiceDispatchedNotification::class,
    ],
    InvoiceStatusChanged::class => [
        UpdateInvoiceStatus::class,
        NotifyAccountant::class,
    ],
    InvoiceFailed::class => [
        AlertAdministrator::class,
    ],
    CompanyFoundOnPeppol::class => [
        UpdateCustomerPeppolStatus::class,
    ],
];
```


## Model Integration

Add PEPPOL support to your invoice model:

```php
use Deinte\Peppol\Models\PeppolInvoice;

class Invoice extends Model
{
    public function peppolInvoice()
    {
        return $this->morphOne(PeppolInvoice::class, 'invoiceable');
    }

    public function sendViaPeppol(string $recipientVatNumber)
    {
        return \Deinte\Peppol\Facades\Peppol::scheduleInvoice(
            invoice: $this,
            recipientVatNumber: $recipientVatNumber
        );
    }
}
```

Usage:

```php
$invoice = Invoice::find(1);
$peppolInvoice = $invoice->sendViaPeppol('BE0123456789');

// Check if invoice has been sent via PEPPOL
if ($invoice->peppolInvoice) {
    echo "Status: {$invoice->peppolInvoice->status->label()}";
}
```

## Database Schema

The package creates three tables:

- **peppol_companies**: Cached PEPPOL participant lookups
- **peppol_invoices**: Links your invoices to PEPPOL dispatches (polymorphic)
- **peppol_invoice_statuses**: Complete audit trail of status changes

## Available Status Codes

```php
use Deinte\Peppol\Enums\PeppolStatus;

PeppolStatus::CREATED                          // Invoice created, not sent
PeppolStatus::PENDING                          // Queued for sending
PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION   // Sent successfully
PeppolStatus::ACCEPTED                         // Confirmed by recipient
PeppolStatus::REJECTED                         // Rejected by recipient
PeppolStatus::FAILED_DELIVERY                  // Failed to deliver
```

## Creating Custom Connectors

Implement the `PeppolConnector` interface to add support for other PEPPOL providers:

```php
use Deinte\Peppol\Contracts\PeppolConnector;
use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Data\Invoice;
use Deinte\Peppol\Data\InvoiceStatus;

class MyCustomConnector implements PeppolConnector
{
    public function lookupCompany(string $vatNumber): ?Company
    {
        // Implement lookup logic
    }

    public function sendInvoice(Invoice $invoice): InvoiceStatus
    {
        // Implement send logic
    }

    // ... implement other methods
}
```

Register in config:

```php
// config/peppol.php
'default_connector' => 'custom',

'connectors' => [
    'custom' => [
        'api_key' => env('CUSTOM_API_KEY'),
        // ... custom config
    ],
],
```

Update service provider:

```php
return match ($connectorName) {
    'scrada' => new ScradaConnector(...),
    'custom' => new MyCustomConnector(...),
    default => throw new \InvalidArgumentException("Unknown connector: {$connectorName}"),
};
```

## Testing

The package includes factories and test helpers:

```php
use Deinte\Peppol\Models\PeppolInvoice;
use Deinte\Peppol\Models\PeppolCompany;

// In your tests
$company = PeppolCompany::factory()->onPeppol()->create();
$peppolInvoice = PeppolInvoice::factory()->dispatched()->create();
```

## Roadmap

- [ ] Automated status polling with configurable intervals
- [ ] Self-billing support
- [ ] Credit note support
- [ ] Additional connector implementations (OpenPEPPOL, etc.)
- [ ] Support for receiving invoices (requires Scrada SDK updates)

## Contributing

Contributions are welcome! Please ensure:

- Code follows PSR-12 standards
- All tests pass
- New features include tests and documentation

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Dante Schrauwen](https://github.com/deinte)
- Built with [Spatie's Laravel Package Tools](https://github.com/spatie/laravel-package-tools)
- Powered by [Scrada](https://www.scrada.be) for PEPPOL integration
