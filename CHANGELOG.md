# Changelog

All notable changes to `laravel-peppol` will be documented in this file.

## [0.0.20] - 2026-01-29

### Fixed
- Detect "recipient not on PEPPOL" from error message patterns, not just status codes
- Invoices with errors like "The receiver does not support any document type" now correctly get `STORED` state instead of `FAILED`
- The invoice is successfully stored in Scrada, just not deliverable via PEPPOL

## [0.0.5] - 2025-12-22

### Removed
- Removed redundant migrations that duplicated columns already in create migrations:
  - `add_tax_number_fields_to_peppol_companies_table` (already in create_peppol_companies_table)
  - `add_skip_peppol_delivery_to_peppol_invoices_table` (already in create_peppol_invoices_table)
  - `add_connector_tracking_to_peppol_invoices_table` (already in create_peppol_invoices_table)

## [0.0.4] - 2025-12-22

### Fixed
- Made all migrations idempotent - they now check if columns/indexes exist before adding them
- Migrations can now run multiple times safely without "duplicate column" errors
- Fixed `down()` methods to check before dropping columns/indexes

## [0.0.3] - 2025-12-22

### Added
- Distributed locking on `peppol:dispatch-invoices` command (10 min lock)
- Distributed locking on `peppol:poll-status` command (30 min lock)
- `--no-lock` flag to override locking when needed
- Job timeout (120s) on `DispatchPeppolInvoice` to prevent hung workers
- Database transaction with row locking in `scheduleInvoice()` to prevent race conditions
- Environment variable validation - throws `RuntimeException` if `SCRADA_API_KEY`, `SCRADA_API_SECRET`, or `SCRADA_COMPANY_ID` are missing
- Unique constraint on `(invoiceable_type, invoiceable_id)` to prevent duplicate invoices
- Index on `connector_invoice_id` for faster lookups
- Index on `(state, skip_delivery, poll_attempts, next_retry_at)` for optimized poll queries

### Changed
- Config key `dispatch.max_retries` renamed to `dispatch.max_attempts`
- Config key `dispatch.retry_delay_minutes` replaced with `dispatch.retry_delays` array for progressive backoff

### Fixed
- Race condition when scheduling same invoice concurrently

## [0.0.2] - 2025-12-18

### Changed
- **Breaking**: `skip_delivery` flag is now only set when explicitly requested
  - No longer inferred from recipient's PEPPOL registration status
  - Let Scrada handle delivery routing automatically
- **Breaking**: Config key `poll.retry_delays` (hours) renamed to `poll.retry_delays_minutes` (minutes)
- `PeppolState` enum cases now use SCREAMING_SNAKE_CASE (e.g., `SCHEDULED`, `SEND_FAILED`)
- Renamed `canReplan()` to `canReschedule()` for consistency
- Updated SDK dependency to `deinte/scrada-php-sdk:^0.0.3`
- `ScradaConnector` now uses `TaxNumberType` enum from SDK instead of internal constants
- Default `poll.max_attempts` increased from 5 to 50

### Added
- State transitions: `SENT` → `STORED` and `POLLING` → `STORED` for non-PEPPOL recipients
- `STORED` added to allowed delivery states in `updateDeliveryStatus()`
- Validation in `scheduleInvoice()` to prevent rescheduling invoices that are already in progress or completed
- `recipientNotOnPeppol` flag in `InvoiceStatus` DTO for explicit "not on PEPPOL" detection
- Static helper methods on `PeppolState` enum for DRY query building:
  - `needsPollingValues()`, `awaitingDeliveryValues()`, `successValues()`
  - `failureValues()`, `pendingDispatchValues()`, `inProgressValues()`, `finalValues()`
- `--force` flag on `peppol:poll-status` command to ignore `next_retry_at` schedule
- Activity log now records every poll attempt, including `POLLING → POLLING` transitions
- Meaningful log message when Scrada is still processing: "Scrada is still processing - awaiting delivery confirmation"
- Progressive poll delays in minutes: 1min, 5min, 10min, 30min, 1hr, 6hr, 24hr, 7 days

### Fixed
- `markAsSent()` no longer pre-decides based on cached PEPPOL status - lets Scrada handle routing and polling determines final state (fixes stale cache issue when recipient registers for PEPPOL)
- Invoices with `existing:` prefix (already existed in Scrada) now go directly to STORED instead of getting stuck in SENT
- Prevented TypeError when SDK's `lookupParty()` method signature changed
- Fixed enum case naming in commands (was using PascalCase instead of SCREAMING_SNAKE_CASE)

## [0.1.0] - 2024-12-18

### Changed
- **Breaking**: Simplified schema from dual status system to single state machine
  - Replaced `status` and `connector_status` columns with single `state` column using `PeppolState` enum
  - Renamed columns for clarity:
    - `dispatched_at` → `sent_at`
    - `scheduled_dispatch_at` → `scheduled_at`
    - `skip_peppol_delivery` → `skip_delivery`
    - `next_poll_at` → `next_retry_at`
  - Added `dispatch_attempts` column for retry tracking
  - Added `error_message` (string) and `error_details` (JSON) for better error handling
  - Removed deprecated columns: `metadata`, `request_payload`, `poll_response`, `status_message`, `connector_error`
- Replaced `PeppolStatus` enum with `PeppolState` enum featuring:
  - Complete state machine with transitions: `Scheduled` → `Sending` → `Sent` → `Polling` → `Delivered` → `Accepted`/`Rejected`
  - Helper methods: `isFinal()`, `isSuccess()`, `isFailure()`, `canTransitionTo()`
- Replaced `PeppolInvoiceStatus` model with `PeppolInvoiceLog` model for activity logging
- Updated `PeppolInvoice` model with state machine methods: `isReadyToDispatch()`, `canRetryDispatch()`, `needsPolling()`
- Updated `PeppolService` with new state transitions and logging
- Updated `DispatchPeppolInvoice` job to use new schema
- Updated `DispatchPeppolInvoicesCommand` and `PollPeppolStatusCommand` for new schema
- Updated `scheduleInvoice()` parameter: `skipPeppolDelivery` → `skipDelivery`

### Migration
Run the published migration `simplify_peppol_schema` to migrate existing data:
```bash
php artisan vendor:publish --tag=peppol-migrations
php artisan migrate
```

The migration automatically:
- Maps old `status` values to new `state` enum values
- Copies `dispatched_at` to `sent_at`, `scheduled_dispatch_at` to `scheduled_at`
- Migrates `PeppolInvoiceStatus` records to `PeppolInvoiceLog` table
- Removes deprecated columns

## [0.0.1] - 2024-12-02

### Added
- Initial release of Laravel PEPPOL package
- `PeppolService` - Main service for PEPPOL operations (company lookup, invoice scheduling/dispatch)
- `PeppolConnector` interface for implementing different PEPPOL connectors
- `ScradaConnector` - Scrada API implementation for PEPPOL electronic invoicing
- `EasCode` enum with comprehensive Electronic Address Scheme codes for PEPPOL network identification
- `HasCountryMapping` trait for country-based EAS code lookups
- `PeppolStatus` enum for standardized invoice status tracking
- Data Transfer Objects:
  - `Company` DTO with intelligent lookup logic for VAT and tax number resolution
  - `Invoice` DTO for invoice data representation
  - `InvoiceStatus` DTO for status tracking
- Eloquent Models:
  - `PeppolCompany` - Cached company lookups with PEPPOL registration status
  - `PeppolInvoice` - Invoice dispatch tracking with polymorphic relation
  - `PeppolInvoiceStatus` - Invoice status history
- `DispatchPeppolInvoice` queued job for async invoice dispatch
- Events: `InvoiceDispatched`, `InvoiceFailed`, `InvoiceStatusChanged`, `CompanyFoundOnPeppol`
- `Peppol` facade for convenient service access
- Configurable caching for company lookups
- Database migrations for all models
- Comprehensive test suite (88 tests, 236 assertions)
- PHPStan static analysis at level 5
- Laravel Pint code formatting
