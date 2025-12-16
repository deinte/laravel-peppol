<?php

declare(strict_types=1);

use Deinte\Peppol\Data\Company;
use Deinte\Peppol\Enums\EasCode;

describe('Company DTO', function () {
    describe('getLookupIdentifier()', function () {
        it('returns explicit tax number when provided', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                country: 'BE',
                taxNumber: '0123456789',
            );

            expect($company->getLookupIdentifier())->toBe('0123456789');
        });

        it('derives enterprise number from Belgian VAT', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                country: 'BE',
            );

            expect($company->getLookupIdentifier())->toBe('0123456789');
        });

        it('handles lowercase BE prefix', function () {
            $company = new Company(
                vatNumber: 'be0123456789',
                country: 'BE',
            );

            expect($company->getLookupIdentifier())->toBe('0123456789');
        });

        it('falls back to VAT number for non-Belgian countries', function () {
            $company = new Company(
                vatNumber: 'NL123456789B01',
                country: 'NL',
            );

            expect($company->getLookupIdentifier())->toBe('NL123456789B01');
        });

        it('falls back to VAT number when no country', function () {
            $company = new Company(
                vatNumber: 'XX123456789',
            );

            expect($company->getLookupIdentifier())->toBe('XX123456789');
        });

        it('prioritizes explicit tax number over derived Belgian enterprise number', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                country: 'BE',
                taxNumber: '9876543210',  // Different number
            );

            expect($company->getLookupIdentifier())->toBe('9876543210');
        });
    });

    describe('getLookupScheme()', function () {
        it('returns explicit scheme when provided', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                taxNumberScheme: EasCode::VAT_BE,
            );

            expect($company->getLookupScheme())->toBe(EasCode::VAT_BE);
        });

        it('returns BE_CBE for Belgium with tax number', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                country: 'BE',
                taxNumber: '0123456789',
            );

            expect($company->getLookupScheme())->toBe(EasCode::BE_CBE);
        });

        it('returns BE_CBE for Belgium without explicit tax number (derived)', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                country: 'BE',
            );

            expect($company->getLookupScheme())->toBe(EasCode::BE_CBE);
        });

        it('returns NL_KVK for Netherlands with tax number', function () {
            $company = new Company(
                vatNumber: 'NL123456789B01',
                country: 'NL',
                taxNumber: '12345678',
            );

            expect($company->getLookupScheme())->toBe(EasCode::NL_KVK);
        });

        it('returns VAT scheme for Netherlands without tax number', function () {
            $company = new Company(
                vatNumber: 'NL123456789B01',
                country: 'NL',
            );

            expect($company->getLookupScheme())->toBe(EasCode::VAT_NL);
        });

        it('returns VAT scheme for France without tax number', function () {
            $company = new Company(
                vatNumber: 'FR12345678901',
                country: 'FR',
            );

            expect($company->getLookupScheme())->toBe(EasCode::VAT_FR);
        });

        it('returns VAT scheme for Germany without tax number', function () {
            $company = new Company(
                vatNumber: 'DE123456789',
                country: 'DE',
            );

            expect($company->getLookupScheme())->toBe(EasCode::VAT_DE);
        });

        it('uses VAT scheme when guessing country from prefix (conservative)', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
            );

            // Without explicit country, uses VAT scheme (more conservative)
            expect($company->getLookupScheme())->toBe(EasCode::VAT_BE);
        });

        it('uses BE_CBE when country is explicitly set to BE', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                country: 'BE',
            );

            // With explicit country=BE, derives enterprise number and uses BE_CBE
            expect($company->getLookupScheme())->toBe(EasCode::BE_CBE);
        });

        it('returns null for unsupported country', function () {
            $company = new Company(
                vatNumber: 'US123456789',
                country: 'US',
            );

            expect($company->getLookupScheme())->toBeNull();
        });
    });

    describe('isOnPeppol()', function () {
        it('returns true when peppolId is set', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                peppolId: '0208:0123456789',
            );

            expect($company->isOnPeppol())->toBeTrue();
        });

        it('returns false when peppolId is null', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
            );

            expect($company->isOnPeppol())->toBeFalse();
        });
    });

    describe('toArray()', function () {
        it('serializes all fields', function () {
            $company = new Company(
                vatNumber: 'BE0123456789',
                peppolId: '0208:0123456789',
                name: 'Test Company',
                country: 'BE',
                email: 'test@example.com',
                taxNumber: '0123456789',
                taxNumberScheme: EasCode::BE_CBE,
                metadata: ['key' => 'value'],
            );

            $array = $company->toArray();

            expect($array)->toBe([
                'vat_number' => 'BE0123456789',
                'peppol_id' => '0208:0123456789',
                'name' => 'Test Company',
                'country' => 'BE',
                'email' => 'test@example.com',
                'tax_number' => '0123456789',
                'tax_number_scheme' => '0208',
                'metadata' => ['key' => 'value'],
            ]);
        });
    });

    describe('fromArray()', function () {
        it('deserializes all fields', function () {
            $company = Company::fromArray([
                'vat_number' => 'BE0123456789',
                'peppol_id' => '0208:0123456789',
                'name' => 'Test Company',
                'country' => 'BE',
                'email' => 'test@example.com',
                'tax_number' => '0123456789',
                'tax_number_scheme' => '0208',
                'metadata' => ['key' => 'value'],
            ]);

            expect($company->vatNumber)->toBe('BE0123456789');
            expect($company->peppolId)->toBe('0208:0123456789');
            expect($company->name)->toBe('Test Company');
            expect($company->country)->toBe('BE');
            expect($company->email)->toBe('test@example.com');
            expect($company->taxNumber)->toBe('0123456789');
            expect($company->taxNumberScheme)->toBe(EasCode::BE_CBE);
            expect($company->metadata)->toBe(['key' => 'value']);
        });

        it('handles missing optional fields', function () {
            $company = Company::fromArray([
                'vat_number' => 'BE0123456789',
            ]);

            expect($company->vatNumber)->toBe('BE0123456789');
            expect($company->peppolId)->toBeNull();
            expect($company->taxNumber)->toBeNull();
            expect($company->taxNumberScheme)->toBeNull();
        });

        it('handles invalid tax_number_scheme gracefully', function () {
            $company = Company::fromArray([
                'vat_number' => 'BE0123456789',
                'tax_number_scheme' => 'invalid',
            ]);

            expect($company->taxNumberScheme)->toBeNull();
        });
    });
});
