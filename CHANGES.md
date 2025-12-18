# Package Changes Summary

This document outlines all changes made to the `laravel-peppol` and `scrada-php-sdk` packages. Use this to update your other projects.

---

## laravel-peppol

### Breaking Changes

#### 1. PDF Handling - `pdfUrl` Removed

**Before:**
```php
new PeppolInvoice(
    // ...
    pdfPath: '/path/to/invoice.pdf',
    pdfUrl: 'https://example.com/invoice.pdf',  // REMOVED
);
```

**After:**
```php
new PeppolInvoice(
    // ...
    pdfPath: '/local/path/invoice.pdf',        // Option 1: Local file path
    pdfContent: base64_encode($pdfData),       // Option 2: Base64 content (preferred)
    pdfFilename: 'invoice-123.pdf',            // Optional: Custom filename
);
```

**Migration Required:**
Update your `InvoiceTransformer` to read PDFs from storage and provide base64 content:

```php
public function toPeppolInvoice(Model $invoice): PeppolInvoice
{
    $pdfContent = null;
    $pdfFilename = null;

    if ($invoice->pdf_path && Storage::disk('invoices')->exists($invoice->pdf_path)) {
        $pdfContent = base64_encode(Storage::disk('invoices')->get($invoice->pdf_path));
        $pdfFilename = basename($invoice->pdf_path);
    }

    return new PeppolInvoice(
        // ... other fields
        pdfContent: $pdfContent,
        pdfFilename: $pdfFilename,
    );
}
```

### Exception Changes

#### 2. Deleted Unused Exceptions

The following exception classes were removed (never used):
- `Deinte\Peppol\Exceptions\RegistrationException`
- `Deinte\Peppol\Exceptions\InvalidWebhookException`

**Migration:** If you were catching these specifically, remove those catch blocks.

#### 3. Simplified Exception Hierarchy

Current hierarchy:
```
PeppolException (base)
├── ConnectorException      # API/network errors
├── InvalidInvoiceException # Invalid invoice data
└── InvoiceNotFoundException # Invoice not found
```

### New Features

#### 4. Invoice Already Exists Handling

When Scrada returns error 110365 ("Invoice already exists"), it's now treated as success:
- `connector_invoice_id` is set to `"existing:{invoiceNumber}"`
- `connector_status` is `SUCCESS`
- No exception thrown

#### 5. Structured Error Data

`ConnectorException` now stores structured error data:

```php
try {
    $service->dispatchInvoice($invoice, $data);
} catch (ConnectorException $e) {
    $context = $e->getContext();
    // [
    //     'status_code' => 500,
    //     'response_data' => ['errorCode' => 100008, 'innerErrors' => [...]]
    // ]
}
```

Access stored errors from `PeppolInvoice`:
```php
$errorData = $peppolInvoice->getConnectorErrorData();
// Returns decoded JSON with 'message' and 'context' keys
```

### Removed Methods

#### 6. Simplified PeppolInvoice Accessors

Removed redundant accessor methods:
- `getConnectorErrorMessage()` - Use `getConnectorErrorData()['message']`
- `getConnectorErrorResponse()` - Use `getConnectorErrorData()['context']['response_data']`
- `getConnectorErrorStatusCode()` - Use `getConnectorErrorData()['context']['status_code']`

Only `getConnectorErrorData()` remains.

### Other Changes

#### 7. Webhook Methods Now Throw

```php
// These now throw RuntimeException instead of returning fake data:
$connector->validateWebhookSignature($payload, $signature);
$connector->parseWebhookPayload($payload);
```

---

## scrada-php-sdk

### Breaking Changes

#### 1. Removed Deprecated `vatPercentageToType()` Method

**Before:**
```php
// Old deprecated method - REMOVED
InvoiceLine::vatPercentageToType(21.0);        // Returned 1
InvoiceLine::vatPercentageToType(0.0, true);   // Returned 3 (domestic)
InvoiceLine::vatPercentageToType(0.0, false);  // Returned 4 (cross-border)
```

**After:**
```php
// Use explicit methods instead
InvoiceLine::vatPercentageToTypeDomestic(21.0);       // Returns 1 (STANDARD)
InvoiceLine::vatPercentageToTypeDomestic(0.0);        // Returns 3 (EXEMPT)

InvoiceLine::vatPercentageToTypeCrossBorder(0.0, true);   // Returns 4 (ICD_SERVICES_B2B)
InvoiceLine::vatPercentageToTypeCrossBorder(0.0, false);  // Returns 5 (ICD_GOODS)
```

**Or use the VatType enum directly:**
```php
use Deinte\ScradaSdk\Enums\VatType;

VatType::fromPercentageDomestic(21.0)->value;           // 1
VatType::fromPercentageCrossBorderB2B(0.0, true)->value; // 4
```

### Bug Fixes

#### 2. Removed Debug Code

Removed `ray()` calls from `PeppolResource.php`.

#### 3. Fixed URL Validation

**Before:** Accepted invalid URLs like `httpgarbage://`
**After:** Only accepts URLs starting with `https://` or `http://`

#### 4. Removed Dead Code

Removed redundant empty string check in `Scrada.php` constructor.

#### 5. Fixed Type Safety in `InvoiceLine::fromArray()`

Improved type checking to properly handle mixed types from array input.

#### 6. Added PHPStan Type Hints to `ScradaException`

Added `@var array<string, mixed>|null` and `@return array<string, mixed>|null` annotations.

#### 7. Updated PHPStan Baseline

Removed obsolete baseline entry for dead code that was removed.

---

## File Changes Summary

### laravel-peppol

| File | Change |
|------|--------|
| `src/Data/Invoice.php` | Replaced `pdfUrl` with `pdfContent` and `pdfFilename` |
| `src/Connectors/ScradaConnector.php` | Updated `buildAttachments()`, added `isInvoiceAlreadyExistsError()` |
| `src/Exceptions/RegistrationException.php` | **DELETED** |
| `src/Exceptions/InvalidWebhookException.php` | **DELETED** |
| `src/Contracts/PeppolConnector.php` | Updated PHPDoc, removed deleted exception references |
| `src/Models/PeppolInvoice.php` | Removed 3 error accessor methods |
| `README.md` | Updated transformer example, added error handling docs |

### scrada-php-sdk

| File | Change |
|------|--------|
| `src/Resources/PeppolResource.php` | Removed `ray()` debug calls |
| `src/ScradaConnector.php` | Fixed URL validation to require `://` suffix |
| `src/Scrada.php` | Removed dead code (empty string check) |
| `src/Data/InvoiceLine.php` | Removed deprecated `vatPercentageToType()`, fixed type safety |
| `src/Exceptions/ScradaException.php` | Added PHPStan type hints |
| `phpstan-baseline.neon` | Removed obsolete baseline entry |
| `tests/Unit/Data/InvoiceLineTest.php` | Updated tests for new VAT methods |

---

## Quick Migration Checklist

### laravel-peppol
- [ ] Update `InvoiceTransformer` to use `pdfContent` instead of `pdfUrl`
- [ ] Read PDFs from storage in transformer, not in connector
- [ ] Remove any catches for `RegistrationException` or `InvalidWebhookException`
- [ ] Update error data access to use `getConnectorErrorData()` only
- [ ] Test invoice dispatch with existing invoice to verify "already exists" handling

### scrada-php-sdk
- [ ] Replace `InvoiceLine::vatPercentageToType()` with `vatPercentageToTypeDomestic()` or `vatPercentageToTypeCrossBorder()`
- [ ] Or use `VatType::fromPercentageDomestic()` / `VatType::fromPercentageCrossBorderB2B()` directly

---

## Additional Fixes (Latest)

### laravel-peppol

| File | Change |
|------|--------|
| `src/PeppolServiceProvider.php` | Added `createScradaConnector()` with proper null checks and error messages |
| `src/Connectors/ScradaConnector.php` | Updated to use new `vatPercentageToTypeDomestic()`/`vatPercentageToTypeCrossBorder()` |
| `src/Models/PeppolInvoice.php` | Added missing PHPDoc properties for connector fields |
| `phpstan.neon.dist` | Added bootstrap file for PHPStan |
| `phpstan-bootstrap.php` | **NEW** - Provides dummy config for static analysis |

### scrada-php-sdk

| File | Change |
|------|--------|
| `phpstan-baseline.neon` | Added entries for type inference edge cases |

---

## Payment Methods Support (Latest)

### New Feature: Payment Methods for Sales Invoices

Added optional `paymentMethods` field to mark invoices as paid when sending to Scrada.

#### Invoice DTO

```php
new Invoice(
    // ... other fields
    paymentMethods: [
        [
            'paymentType' => 1,  // 1=Undefined, 2=Wire transfer, 7=Online payment provider, etc.
            'name' => 'Betaald',
            'totalPaid' => 100.00,
            'totalToPay' => 0.0,
        ],
    ],
);
```

**Payment Types:**
- 1: Undefined (Unknown)
- 2: Wire transfer (Overschrijving)
- 3: Bank card (iDeal)
- 4: Direct debit (Domiciliëring)
- 5: Cash
- 6: Credit card
- 7: Online payment provider (Mollie, Stripe)
- 8: Cheque
- 9: Debit card (Bancontact)

#### Example Usage in Transformer

```php
private function getPaymentMethods(Order $order): array
{
    if ($order->paid_at === null) {
        return [];
    }

    return [
        [
            'paymentType' => 1,
            'name' => 'Betaald',
            'totalPaid' => $order->total_amount,
            'totalToPay' => 0.0,
        ],
    ];
}
```

### Dispatch Logic Improvements

#### Prevent Re-sending Already Dispatched Invoices

`PeppolService::dispatchInvoice()` now checks for existing `connector_invoice_id`:

1. **Has `existing:...` prefix** → Returns current status (invoice already existed in Scrada)
2. **Has real connector ID** → Polls for status instead of re-sending
3. **No connector ID** → Sends the invoice

This prevents "invoice already exists" errors when retrying dispatches.

### File Changes

| Package | File | Change |
|---------|------|--------|
| laravel-peppol | `src/Data/Invoice.php` | Added optional `paymentMethods` array |
| laravel-peppol | `src/Connectors/ScradaConnector.php` | Pass payment methods to Scrada |
| laravel-peppol | `src/PeppolService.php` | Check for existing connector ID before dispatching |
| scrada-php-sdk | `src/Data/CreateSalesInvoiceData.php` | Added optional `paymentMethods` array |
