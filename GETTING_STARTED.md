# Getting Started with Laravel PEPPOL

This guide will walk you through setting up and using the Laravel PEPPOL package to send invoices via the PEPPOL network using Scrada.

## Table of Contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Implementing the Invoice Transformer](#implementing-the-invoice-transformer)
4. [Setting Up Scheduled Jobs](#setting-up-scheduled-jobs)
5. [Understanding Status Polling](#understanding-status-polling)
6. [Usage Examples](#usage-examples)
7. [Testing](#testing)
8. [Production Deployment](#production-deployment)

---

## Installation

### Step 1: Install via Composer

```bash
composer require deinte/laravel-peppol
```

### Step 2: Publish and Run Migrations

```bash
# Publish migrations
php artisan vendor:publish --tag="peppol-migrations"

# Run migrations
php artisan migrate
```

This creates three tables:
- `peppol_companies` - Cached PEPPOL participant lookups
- `peppol_invoices` - Links your invoices to PEPPOL dispatches
- `peppol_invoice_statuses` - Complete audit trail of status changes

### Step 3: Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="peppol-config"
```

This creates `config/peppol.php` for customization.

---

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
# Scrada API Credentials
SCRADA_API_KEY=your-api-key
SCRADA_API_SECRET=your-api-secret
SCRADA_COMPANY_ID=your-company-uuid

# Use test environment during development
SCRADA_BASE_URL=https://apitest.scrada.be

# Optional: Customize dispatch behavior
PEPPOL_DISPATCH_DELAY=7        # Days to wait before sending
PEPPOL_MAX_RETRIES=3           # Number of retry attempts
PEPPOL_RETRY_DELAY=60          # Minutes between retries

# Optional: Customize lookup caching
PEPPOL_LOOKUP_CACHE_HOURS=168  # Cache PEPPOL lookups for 7 days

# Optional: Status polling configuration
PEPPOL_POLLING_ENABLED=true    # Enable automatic polling
PEPPOL_POLLING_INTERVAL=60     # Poll every 60 minutes
```

### Get Your Scrada Credentials

1. Sign up at [Scrada](https://www.scrada.be)
2. Go to Settings → API
3. Create API credentials
4. Copy your API Key, API Secret, and Company ID

**For testing**: Use `https://apitest.scrada.be` as base URL to connect to Scrada's test environment.

---

## Implementing the Invoice Transformer

The package needs to know how to convert YOUR invoice model to PEPPOL format. You do this by implementing the `InvoiceTransformer` interface.

### Step 1: Create Your Transformer

Create a new file: `app/Services/MyInvoiceTransformer.php`

```php
<?php

namespace App\Services;

use Deinte\Peppol\Contracts\InvoiceTransformer;
use Deinte\Peppol\Data\Invoice as PeppolInvoice;
use Illuminate\Database\Eloquent\Model;

class MyInvoiceTransformer implements InvoiceTransformer
{
    public function toPeppolInvoice(Model $invoice): PeppolInvoice
    {
        // Assuming you have an Invoice model with relationships to Customer and InvoiceLines

        return new PeppolInvoice(
            senderVatNumber: config('company.vat_number'), // Your company's VAT
            recipientVatNumber: $invoice->customer->vat_number,
            recipientPeppolId: $invoice->customer->peppol_id,
            invoiceNumber: $invoice->invoice_number,
            invoiceDate: $invoice->invoice_date,
            dueDate: $invoice->due_date,
            totalAmount: $invoice->total_incl_vat,
            currency: $invoice->currency ?? 'EUR',

            // Transform your line items
            lineItems: $invoice->lines->map(function ($line) {
                return [
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unitPrice' => $line->unit_price,
                    'vatPerc' => $line->vat_percentage,
                    'vatTypeID' => $line->vat_type_id ?? 'S', // S = Standard rate
                ];
            })->toArray(),

            // Optional: PDF path or URL
            pdfPath: $invoice->pdf_path,

            // Flag if you already emailed the invoice
            alreadySentToCustomer: $invoice->emailed_at !== null,

            // Additional data required by Scrada
            additionalData: [
                'journal' => 'SALES', // Your sales journal code
                'creditInvoice' => $invoice->is_credit_note ?? false,

                // Customer details for Scrada
                'customer' => [
                    'code' => $invoice->customer->code,
                    'name' => $invoice->customer->name,
                    'email' => $invoice->customer->email,
                    'address' => [
                        'street' => $invoice->customer->street,
                        'streetNumber' => $invoice->customer->street_number,
                        'city' => $invoice->customer->city,
                        'zipCode' => $invoice->customer->zip_code,
                        'countryCode' => $invoice->customer->country_code ?? 'BE',
                    ],
                    'phone' => $invoice->customer->phone, // Optional
                ],
            ],
        );
    }
}
```

### Step 2: Register Your Transformer

In `app/Providers/AppServiceProvider.php`:

```php
use App\Services\MyInvoiceTransformer;
use Deinte\Peppol\Contracts\InvoiceTransformer;

public function register(): void
{
    // Bind your transformer implementation
    $this->app->bind(InvoiceTransformer::class, MyInvoiceTransformer::class);
}
```

### Important Field Notes

**Required in `additionalData`:**

1. **`customer`** - Full customer data
   - `code` - Customer code in your system
   - `name` - Customer name
   - `email` - Customer email
   - `vatNumber` - Already in main Invoice DTO, but Scrada needs it in customer too
   - `address` - Full address object

2. **`journal`** - Your sales journal code (e.g., "SALES", "VKP", etc.)

**Line Item Fields:**
- `vatTypeID` - VAT type identifier (usually 'S' for standard rate)
- If you don't have a `vatTypeID`, use 'S' as default

---

## Setting Up Scheduled Jobs

The package uses Laravel's task scheduler for two operations:
1. **Dispatching scheduled invoices** (default: 7 days after creation)
2. **Polling for status updates** (default: every hour)

### Step 1: Update Your Console Kernel

Edit `app/Console/Kernel.php`:

```php
<?php

namespace App\Console;

use Deinte\Peppol\Enums\PeppolStatus;
use Deinte\Peppol\Jobs\DispatchPeppolInvoice;
use Deinte\Peppol\Models\PeppolInvoice;
use Deinte\Peppol\Facades\Peppol;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // 1. Dispatch invoices that are ready to be sent
        $schedule->call(function () {
            PeppolInvoice::readyToDispatch()->each(function ($peppolInvoice) {
                DispatchPeppolInvoice::dispatch($peppolInvoice->id);

                Log::info("Scheduled PEPPOL invoice for dispatch", [
                    'peppol_invoice_id' => $peppolInvoice->id,
                    'invoiceable_type' => $peppolInvoice->invoiceable_type,
                    'invoiceable_id' => $peppolInvoice->invoiceable_id,
                ]);
            });
        })
        ->daily()
        ->at('08:00') // Run at 8 AM daily
        ->name('peppol-dispatch-invoices')
        ->withoutOverlapping();

        // 2. Poll for status updates on pending/delivered invoices
        $schedule->call(function () {
            $pollingEnabled = config('peppol.polling.enabled', true);

            if (!$pollingEnabled) {
                return;
            }

            $statuses = [
                PeppolStatus::PENDING,
                PeppolStatus::DELIVERED_WITHOUT_CONFIRMATION,
            ];

            PeppolInvoice::whereIn('status', $statuses)
                ->whereNotNull('connector_invoice_id')
                ->each(function ($peppolInvoice) {
                    try {
                        $status = Peppol::getInvoiceStatus($peppolInvoice);

                        Log::info("Polled PEPPOL invoice status", [
                            'peppol_invoice_id' => $peppolInvoice->id,
                            'old_status' => $peppolInvoice->status->value,
                            'new_status' => $status->status->value,
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to poll PEPPOL invoice status", [
                            'peppol_invoice_id' => $peppolInvoice->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
        })
        ->hourly()
        ->name('peppol-poll-status')
        ->withoutOverlapping();
    }
}
```

### Step 2: Ensure Cron is Running

Make sure Laravel's scheduler is running on your server:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Or use Laravel Forge/Vapor which handles this automatically.

### Step 3: Test the Scheduler Locally

```bash
# Run the scheduler once manually
php artisan schedule:run

# Or test individual scheduled tasks
php artisan schedule:test
```

---

## Understanding Status Polling

### Why Polling?

Scrada uses a **polling model** for status updates, not webhooks. This means:
- ✅ Your application checks for status updates periodically
- ✅ More reliable than webhooks (no missed updates if your server is down)
- ✅ You control when and how often to check

### How It Works

```
Your App                    Scrada API
   |                             |
   |  1. Send Invoice            |
   |--------------------------->|
   |  Returns: invoice_id       |
   |<---------------------------|
   |                             |
   |  [Wait 1 hour]              |
   |                             |
   |  2. Poll Status             |
   |--------------------------->|
   |  Returns: PENDING           |
   |<---------------------------|
   |                             |
   |  [Wait 1 hour]              |
   |                             |
   |  3. Poll Status             |
   |--------------------------->|
   |  Returns: DELIVERED         |
   |<---------------------------|
   |  Update local DB            |
   |  Fire InvoiceStatusChanged  |
   |                             |
```

### Status Lifecycle

```
PENDING
   ↓
DELIVERED_WITHOUT_CONFIRMATION (sent to PEPPOL network)
   ↓
ACCEPTED (recipient confirmed receipt)
   or
REJECTED (recipient rejected the invoice)
```

### Configuration Options

In `config/peppol.php`:

```php
'polling' => [
    // Enable/disable automatic polling
    'enabled' => env('PEPPOL_POLLING_ENABLED', true),

    // How often to poll (in minutes)
    'interval' => env('PEPPOL_POLLING_INTERVAL', 60),

    // Only poll invoices in these statuses
    'poll_statuses' => [
        'PENDING',
        'DELIVERED_WITHOUT_CONFIRMATION',
    ],
],
```

### When to Poll

**Poll frequently for:**
- `PENDING` - Waiting to be sent
- `DELIVERED_WITHOUT_CONFIRMATION` - Sent, waiting for recipient confirmation

**Stop polling when:**
- `ACCEPTED` - Final success state
- `REJECTED` - Final failure state
- `FAILED_DELIVERY` - Final failure state

The scheduled task only polls invoices in active statuses to avoid unnecessary API calls.

### Manual Polling

You can also poll manually:

```php
use Deinte\Peppol\Facades\Peppol;

$peppolInvoice = PeppolInvoice::find($id);
$status = Peppol::getInvoiceStatus($peppolInvoice);

echo "Current status: {$status->status->label()}";
```

---

## Usage Examples

### Example 1: Schedule Invoice Dispatch

```php
use App\Models\Invoice;
use Deinte\Peppol\Facades\Peppol;

// After creating your invoice...
$invoice = Invoice::create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-2025-001',
    // ... other fields
]);

// Generate PDF
$invoice->generatePdf();

// Check if customer is on PEPPOL
$company = Peppol::lookupCompany($customer->vat_number);

if ($company && $company->isOnPeppol()) {
    // Schedule for PEPPOL (will be sent in 7 days by default)
    $peppolInvoice = Peppol::scheduleInvoice(
        invoice: $invoice,
        recipientVatNumber: $customer->vat_number
    );

    Log::info("Invoice scheduled for PEPPOL", [
        'invoice_id' => $invoice->id,
        'peppol_invoice_id' => $peppolInvoice->id,
        'dispatch_at' => $peppolInvoice->scheduled_dispatch_at,
    ]);
} else {
    // Customer not on PEPPOL - send via email
    $invoice->sendViaEmail();
}
```

### Example 2: Immediate Dispatch

```php
use Deinte\Peppol\Jobs\DispatchPeppolInvoice;
use Deinte\Peppol\Facades\Peppol;

// Schedule invoice first
$peppolInvoice = Peppol::scheduleInvoice($invoice, $customer->vat_number);

// Dispatch immediately (bypasses 7-day delay)
DispatchPeppolInvoice::dispatch($peppolInvoice->id);
```

### Example 3: Check Invoice Status

```php
use Deinte\Peppol\Facades\Peppol;

$peppolInvoice = $invoice->peppolInvoice;

if ($peppolInvoice && $peppolInvoice->dispatched_at) {
    $status = Peppol::getInvoiceStatus($peppolInvoice);

    if ($status->status->isDelivered()) {
        echo "✅ Invoice delivered successfully!";
    } elseif ($status->status->isFailed()) {
        echo "❌ Invoice delivery failed: {$status->message}";
    } else {
        echo "⏳ Invoice pending: {$status->status->label()}";
    }
}
```

### Example 4: Download UBL File

```php
use Deinte\Peppol\Facades\Peppol;
use Illuminate\Support\Facades\Storage;

$peppolInvoice = $invoice->peppolInvoice;

if ($peppolInvoice && $peppolInvoice->dispatched_at) {
    $ubl = Peppol::getUblFile($peppolInvoice);

    // Store UBL file
    Storage::put(
        "invoices/ubl/{$invoice->invoice_number}.xml",
        $ubl
    );
}
```

### Example 5: Add PEPPOL Support to Your Invoice Model

```php
namespace App\Models;

use Deinte\Peppol\Models\PeppolInvoice;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /**
     * Get the PEPPOL invoice for this invoice.
     */
    public function peppolInvoice()
    {
        return $this->morphOne(PeppolInvoice::class, 'invoiceable');
    }

    /**
     * Check if this invoice has been sent via PEPPOL.
     */
    public function isSentViaPeppol(): bool
    {
        return $this->peppolInvoice?->dispatched_at !== null;
    }

    /**
     * Get PEPPOL status label.
     */
    public function getPeppolStatusAttribute(): ?string
    {
        return $this->peppolInvoice?->status->label();
    }

    /**
     * Schedule this invoice for PEPPOL dispatch.
     */
    public function sendViaPeppol(string $recipientVatNumber)
    {
        return \Deinte\Peppol\Facades\Peppol::scheduleInvoice(
            invoice: $this,
            recipientVatNumber: $recipientVatNumber
        );
    }
}
```

---

## Testing

### Testing with Scrada Test Environment

**Step 1:** Use test credentials in `.env`:

```env
SCRADA_BASE_URL=https://apitest.scrada.be
SCRADA_API_KEY=your-test-key
SCRADA_API_SECRET=your-test-secret
SCRADA_COMPANY_ID=your-test-company-id
```

**Step 2:** Register a test company on PEPPOL:

Test registrations appear on: https://test-directory.peppol.eu/public
(Can take a few hours to show up)

**Step 3:** Create a test invoice:

```php
// In tinker or a test controller
$invoice = Invoice::factory()->create([
    'customer_id' => $testCustomer->id,
]);

$peppolInvoice = Peppol::scheduleInvoice($invoice, 'BE0123456789');

// Dispatch immediately for testing
DispatchPeppolInvoice::dispatch($peppolInvoice->id);

// Check status
$status = Peppol::getInvoiceStatus($peppolInvoice);
dd($status);
```

### Unit Testing Your Transformer

```php
namespace Tests\Unit;

use App\Models\Invoice;
use App\Services\MyInvoiceTransformer;
use Tests\TestCase;

class InvoiceTransformerTest extends TestCase
{
    public function test_transforms_invoice_correctly()
    {
        $invoice = Invoice::factory()->create();
        $transformer = new MyInvoiceTransformer();

        $peppolInvoice = $transformer->toPeppolInvoice($invoice);

        $this->assertEquals($invoice->invoice_number, $peppolInvoice->invoiceNumber);
        $this->assertEquals($invoice->total_incl_vat, $peppolInvoice->totalAmount);
        $this->assertNotEmpty($peppolInvoice->lineItems);
        $this->assertArrayHasKey('customer', $peppolInvoice->additionalData);
    }
}
```

---

## Production Deployment

### Pre-Launch Checklist

- [ ] Scrada production credentials configured
- [ ] InvoiceTransformer implemented and tested
- [ ] Cron jobs set up and running
- [ ] Queue workers running (`php artisan queue:work`)
- [ ] Event listeners configured (if needed)
- [ ] Test invoice sent successfully in test environment
- [ ] Monitoring/logging configured

### Switch to Production

1. Update `.env` to use production Scrada:

```env
SCRADA_BASE_URL=https://api.scrada.be
SCRADA_API_KEY=your-production-key
SCRADA_API_SECRET=your-production-secret
SCRADA_COMPANY_ID=your-production-company-id
```

2. Clear config cache:

```bash
php artisan config:clear
php artisan config:cache
```

3. Test with a real invoice to a known PEPPOL participant

### Monitoring

Set up event listeners to monitor PEPPOL activity:

```php
// In EventServiceProvider
use Deinte\Peppol\Events\InvoiceDispatched;
use Deinte\Peppol\Events\InvoiceFailed;
use Deinte\Peppol\Events\InvoiceStatusChanged;

protected $listen = [
    InvoiceDispatched::class => [
        NotifyAccountingDepartment::class,
    ],

    InvoiceFailed::class => [
        AlertAdministrator::class,
        LogFailedInvoice::class,
    ],

    InvoiceStatusChanged::class => [
        UpdateInvoiceStatus::class,
    ],
];
```

### Logging

The package logs important events. Monitor:

```bash
tail -f storage/logs/laravel.log | grep PEPPOL
```

---

## Troubleshooting

### Invoice Not Dispatching

1. Check scheduled task is running:
   ```bash
   php artisan schedule:list
   ```

2. Manually trigger dispatch:
   ```bash
   php artisan tinker
   >>> DispatchPeppolInvoice::dispatch($peppolInvoiceId);
   ```

3. Check queue is processing:
   ```bash
   php artisan queue:work
   ```

### Status Not Updating

1. Verify polling is enabled:
   ```bash
   php artisan tinker
   >>> config('peppol.polling.enabled')
   ```

2. Check invoice has `connector_invoice_id`:
   ```php
   $peppolInvoice->connector_invoice_id // Should not be null
   ```

3. Manually poll:
   ```bash
   php artisan tinker
   >>> Peppol::getInvoiceStatus($peppolInvoice);
   ```

### API Errors

Check Scrada credentials:
```bash
php artisan tinker
>>> config('peppol.connectors.scrada')
```

Enable Scrada SDK debugging by setting log level to debug.

---

## Next Steps

1. Read the [README](README.md) for detailed API documentation
2. Check [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) for feature status
3. Review the example transformer above and adapt to your models
4. Test in Scrada's test environment first
5. Set up monitoring and alerts

**Need help?** Open an issue on GitHub or check the Scrada API documentation at https://www.scrada.be/api-documentation/
