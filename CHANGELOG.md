# Changelog

All notable changes to `laravel-peppol` will be documented in this file.

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
