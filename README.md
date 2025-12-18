# Laravel Peppol

[![Tests](https://github.com/deinte/laravel-peppol/actions/workflows/run-tests.yml/badge.svg)](https://github.com/deinte/laravel-peppol/actions/workflows/run-tests.yml)
[![PHPStan](https://github.com/deinte/laravel-peppol/actions/workflows/phpstan.yml/badge.svg)](https://github.com/deinte/laravel-peppol/actions/workflows/phpstan.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/deinte/laravel-peppol)](https://packagist.org/packages/deinte/laravel-peppol)
[![License](https://img.shields.io/packagist/l/deinte/laravel-peppol)](LICENSE.md)

A Laravel package for sending electronic invoices via the Peppol network.

> **Note:** This package was co-authored with AI assistance. Not all features have been tested in production. Please test thoroughly before use.

## Features

- Send invoices via Peppol network
- Automatic company lookup and caching
- Scheduled invoice dispatch with configurable delay
- Status tracking with polling support
- Event-driven architecture
- Polymorphic invoice support
- Default Scrada connector included

## Requirements

- PHP 8.3+
- Laravel 10, 11, or 12

## Installation

```bash
composer require deinte/laravel-peppol
```

Publish and run migrations:

```bash
php artisan vendor:publish --tag="peppol-migrations"
php artisan migrate
```

Publish config (optional):

```bash
php artisan vendor:publish --tag="peppol-config"
```

## Configuration

Add to your `.env`:

```env
SCRADA_API_KEY=your-api-key
SCRADA_API_SECRET=your-api-secret
SCRADA_COMPANY_ID=your-company-uuid
SCRADA_BASE_URL=https://apitest.scrada.be  # optional

PEPPOL_DISPATCH_DELAY=7   # days before sending
PEPPOL_MAX_RETRIES=3
PEPPOL_RETRY_DELAY=60     # minutes
```

## Quick Start

### 1. Create an Invoice Transformer

```php
use Deinte\Peppol\Contracts\InvoiceTransformer;
use Deinte\Peppol\Data\Invoice as PeppolInvoice;

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
            currency: 'EUR',
            lineItems: $invoice->lines->map(fn($line) => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unit_price,
                'vatPerc' => $line->vat_percentage,
            ])->toArray(),
            pdfContent: base64_encode(Storage::get($invoice->pdf_path)),
        );
    }
}
```

Register in a service provider:

```php
$this->app->bind(InvoiceTransformer::class, MyInvoiceTransformer::class);
```

### 2. Lookup Companies

```php
use Deinte\Peppol\Facades\Peppol;

$company = Peppol::lookupCompany('BE0123456789');

if ($company?->isOnPeppol()) {
    echo "Peppol ID: {$company->peppol_id}";
}
```

### 3. Schedule Invoice Dispatch

```php
use Deinte\Peppol\Facades\Peppol;

$peppolInvoice = Peppol::scheduleInvoice(
    invoice: $invoice,
    recipientVatNumber: 'BE0123456789'
);
```

### 4. Check Status

```php
$status = Peppol::getInvoiceStatus($peppolInvoice);

if ($status->status->isDelivered()) {
    echo "Invoice delivered!";
}
```

## Available Statuses

```php
use Deinte\Peppol\Enums\PeppolStatus;

PeppolStatus::CREATED
PeppolStatus::PENDING
PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION
PeppolStatus::ACCEPTED
PeppolStatus::REJECTED
PeppolStatus::FAILED_DELIVERY
```

## Events

- `InvoiceDispatched`
- `InvoiceStatusChanged`
- `InvoiceFailed`
- `CompanyFoundOnPeppol`

## Documentation

See [GETTING_STARTED.md](GETTING_STARTED.md) for a complete setup guide.

## Testing

```bash
composer test      # Run tests
composer analyse   # PHPStan
composer format    # Laravel Pint
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.

## Credits

- Built with [Spatie's Laravel Package Tools](https://github.com/spatie/laravel-package-tools)
- Powered by [Scrada](https://www.scrada.be) for Peppol integration
