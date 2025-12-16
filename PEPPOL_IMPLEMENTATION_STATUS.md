# PEPPOL Implementation Status

Last updated: 2025-12-02

## Overview

This document tracks the implementation status of PEPPOL e-invoicing integration using the Scrada API connector.

## Packages

### scrada-php-sdk (v0.0.1)
**Status**: ✅ Ready for release
**Location**: `/Users/danteschrauwen/Documents/Projecten/Salino/scrada-php-sdk`

A PHP SDK for the Scrada API built on Saloon. Handles low-level API communication.

**Recent Changes (v0.0.1)**:
- `PeppolLookupResult` now uses exact Scrada API field names:
  - `registered` - Whether the party is registered on PEPPOL
  - `supportInvoice` - Can receive invoices
  - `supportCreditInvoice` - Can receive credit invoices
  - `supportSelfBillingInvoice` - Can receive self-billing invoices
  - `supportSelfBillingCreditInvoice` - Can receive self-billing credit invoices
- Added `canReceiveInvoices()` method (checks `registered && supportInvoice`)
- Added `canReceiveCreditInvoices()` method (checks `registered && supportCreditInvoice`)
- Fixed PEPPOL lookup not recognizing registered companies due to incorrect field mapping

---

### laravel-peppol
**Status**: 🚧 In development
**Location**: `/Users/danteschrauwen/Documents/Projecten/Salino/laravel-peppol`

Laravel package that provides PEPPOL functionality using the Scrada connector.

**Features Implemented**:

#### Company Lookup
- ✅ VAT number normalization (removes spaces, dots, dashes)
- ✅ Belgian enterprise number (CBE) lookup using EAS code `0208`
- ✅ Fallback to VAT number lookup with EAS code `9925` if CBE lookup fails
- ✅ Proper PEPPOL ID format: `{EAS_CODE}:{IDENTIFIER}` (e.g., `0208:0833557226`)
- ✅ Derived tax number stored in `peppol_companies.tax_number`
- ✅ Tax number scheme stored in `peppol_companies.tax_number_scheme`

#### Invoice Scheduling
- ✅ `scheduleInvoice()` with `skipPeppolDelivery` parameter
- ✅ Automatic scheduling via `InvoiceObserver` (7 days delay)
- ✅ Duplicate prevention - updates existing pending records instead of creating new ones
- ✅ `skip_peppol_delivery` flag to send to Scrada without PEPPOL forwarding

#### Models
- ✅ `PeppolCompany` - Caches company PEPPOL registration status
- ✅ `PeppolInvoice` - Tracks invoice dispatch status
- ✅ `PeppolInvoiceStatus` - Status history for invoices

#### Migrations
- ✅ `create_peppol_companies_table`
- ✅ `create_peppol_invoices_table`
- ✅ `create_peppol_invoice_statuses_table`
- ✅ `add_tax_number_fields_to_peppol_companies_table`
- ✅ `add_skip_peppol_delivery_to_peppol_invoices_table`

---

## Database Schema

### peppol_companies
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| vat_number | string | Normalized VAT number (unique) |
| peppol_id | string | PEPPOL participant ID (e.g., `0208:0833557226`) |
| name | string | Company name |
| country | string(2) | ISO country code |
| email | string | Contact email |
| tax_number | string | Enterprise/tax number (e.g., `0833557226` for BE) |
| tax_number_scheme | string(4) | EAS code (e.g., `0208` for BE_CBE) |
| is_active | boolean | Whether company is active on PEPPOL |
| metadata | json | Additional API response data |
| last_lookup_at | timestamp | Last API lookup time |

### peppol_invoices
| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| invoiceable_type | string | Polymorphic type |
| invoiceable_id | bigint | Polymorphic ID |
| recipient_peppol_company_id | bigint | FK to peppol_companies |
| connector_invoice_id | string | Scrada invoice ID |
| status | string | Current status (PENDING, DISPATCHED, etc.) |
| skip_peppol_delivery | boolean | If true, sent to Scrada but not forwarded to PEPPOL |
| status_message | text | Status details |
| scheduled_dispatch_at | timestamp | When to dispatch |
| dispatched_at | timestamp | When dispatched |
| delivered_at | timestamp | When delivered |
| metadata | json | Additional data |

---

## EAS Codes Used

| Code | Name | Description |
|------|------|-------------|
| 0208 | BE_CBE | Belgian Enterprise Number (Ondernemingsnummer) |
| 9925 | VAT_BE | Belgian VAT Number |
| 0106 | NL_KVK | Dutch Chamber of Commerce Number |
| 9944 | VAT_NL | Dutch VAT Number |
| 0009 | FR_SIRET | French SIRET Number |
| 9957 | VAT_FR | French VAT Number |

---

## Lookup Flow for Belgian Companies

1. **Input**: VAT number `BE 0833.557.226`
2. **Normalize**: `BE0833557226`
3. **First Lookup** (Enterprise Number):
   - Identifier: `0833557226` (BE prefix stripped)
   - Scheme: `0208` (BE_CBE)
   - Payload: `{ taxNumberType: 1, taxNumber: "0833557226", peppolId: "0208:0833557226" }`
4. **If not found, Fallback** (VAT Number):
   - Identifier: `BE0833557226`
   - Scheme: `9925` (VAT_BE)
   - Payload: `{ vatNumber: "BE0833557226", peppolId: "9925:BE0833557226" }`
5. **Result**: Company found/not found on PEPPOL

---

## Configuration

```php
// config/peppol.php
return [
    'default_connector' => 'scrada',

    'connectors' => [
        'scrada' => [
            'api_key' => env('SCRADA_API_KEY'),
            'api_secret' => env('SCRADA_API_SECRET'),
            'company_id' => env('SCRADA_COMPANY_ID'),
            'base_url' => env('SCRADA_BASE_URL', 'https://api.scrada.be'), // Use https://apitest.scrada.be for testing
        ],
    ],

    'dispatch' => [
        'delay_days' => 7, // Auto-dispatch after 7 days
    ],
];
```

---

## TODO

### High Priority
- [ ] Test invoice sending to Scrada API
- [ ] Implement `dispatchInvoice()` method
- [ ] Add webhook handling for status updates from Scrada
- [ ] Switch to production Scrada API for real testing

### Medium Priority
- [ ] Add retry logic for failed dispatches
- [ ] Implement batch company sync command
- [ ] Add queue job for scheduled dispatches
- [ ] Credit invoice support

### Low Priority
- [ ] Add support for other countries (NL, FR, DE)
- [ ] Dashboard/reporting for PEPPOL invoices
- [ ] Email notifications for delivery status

---

## Known Issues

1. **Test API vs Production**: Currently using `https://apitest.scrada.be` - some companies may not be registered in test environment
2. **Tax number not always stored**: Ensure `cacheCompany()` saves the derived tax number

---

## Testing

```bash
# Run scrada-php-sdk tests
cd scrada-php-sdk && ./vendor/bin/pest

# Manual lookup test via tinker
php artisan tinker
>>> Peppol::lookupCompany('BE0833557226', forceRefresh: true)
```

---

## Logs

PEPPOL operations are logged to `storage/logs/services/peppol-*.log` with detailed request/response information.
