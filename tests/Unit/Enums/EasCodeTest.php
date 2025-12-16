<?php

declare(strict_types=1);

use Deinte\Peppol\Enums\EasCode;

describe('EasCode enum', function () {
    it('can get label for a code', function () {
        expect(EasCode::NL_KVK->label())->toBe('Dutch KvK Number');
        expect(EasCode::BE_CBE->label())->toBe('Belgian Enterprise Number (CBE)');
        expect(EasCode::VAT_NL->label())->toBe('Dutch VAT');
    });

    it('can get description for a code', function () {
        expect(EasCode::NL_KVK->description())
            ->toContain('Chamber of Commerce');

        expect(EasCode::BE_CBE->description())
            ->toContain('Crossroad Bank');
    });

    it('can identify VAT schemes', function () {
        expect(EasCode::VAT_NL->isVatScheme())->toBeTrue();
        expect(EasCode::VAT_BE->isVatScheme())->toBeTrue();
        expect(EasCode::NL_KVK->isVatScheme())->toBeFalse();
    });

    it('can identify national register schemes', function () {
        expect(EasCode::NL_KVK->isNationalRegister())->toBeTrue();
        expect(EasCode::BE_CBE->isNationalRegister())->toBeTrue();
        expect(EasCode::VAT_NL->isNationalRegister())->toBeFalse();
    });

    it('can identify international schemes', function () {
        expect(EasCode::DUNS->isInternational())->toBeTrue();
        expect(EasCode::GLN->isInternational())->toBeTrue();
        expect(EasCode::LEI->isInternational())->toBeTrue();
        expect(EasCode::NL_KVK->isInternational())->toBeFalse();
    });

    it('returns correct country code', function () {
        expect(EasCode::NL_KVK->countryCode())->toBe('NL');
        expect(EasCode::BE_CBE->countryCode())->toBe('BE');
        expect(EasCode::DE_COMPANY_NUMBER->countryCode())->toBe('DE');
        expect(EasCode::DUNS->countryCode())->toBeNull();
    });

    it('returns validation pattern when available', function () {
        expect(EasCode::NL_KVK->pattern())->toBe('/^\d{8}$/');
        expect(EasCode::DK_CVR->pattern())->toBe('/^\d{8}$/');
        expect(EasCode::NO_ORGNR->pattern())->toBe('/^\d{9}$/');
    });
});

describe('EasCode country mapping', function () {
    it('can get all codes for a country', function () {
        $nlSchemes = EasCode::forCountry('NL');

        expect($nlSchemes)->toContain(EasCode::NL_KVK);
        expect($nlSchemes)->toContain(EasCode::NL_OIN);
        expect($nlSchemes)->toContain(EasCode::VAT_NL);
    });

    it('can get VAT scheme for a country', function () {
        expect(EasCode::vatSchemeForCountry('NL'))->toBe(EasCode::VAT_NL);
        expect(EasCode::vatSchemeForCountry('BE'))->toBe(EasCode::VAT_BE);
        expect(EasCode::vatSchemeForCountry('DE'))->toBe(EasCode::VAT_DE);
    });

    it('throws exception for country without VAT scheme', function () {
        EasCode::vatSchemeForCountry('US');
    })->throws(InvalidArgumentException::class);

    it('can get default business scheme for a country', function () {
        expect(EasCode::defaultSchemeForCountry('NL'))->toBe(EasCode::NL_KVK);
        expect(EasCode::defaultSchemeForCountry('BE'))->toBe(EasCode::BE_CBE);
        expect(EasCode::defaultSchemeForCountry('DE'))->toBe(EasCode::DE_COMPANY_NUMBER);
        expect(EasCode::defaultSchemeForCountry('NO'))->toBe(EasCode::NO_ORGNR);
    });

    it('can find scheme by country and type', function () {
        expect(EasCode::findSchemeForCountry('NL', 'vat'))->toBe(EasCode::VAT_NL);
        expect(EasCode::findSchemeForCountry('NL', 'business'))->toBe(EasCode::NL_KVK);
        expect(EasCode::findSchemeForCountry('NL', 'government'))->toBe(EasCode::NL_OIN);
    });

    it('returns null for invalid country or type', function () {
        expect(EasCode::findSchemeForCountry('US', 'vat'))->toBeNull();
        expect(EasCode::findSchemeForCountry('NL', 'invalid'))->toBeNull();
    });

    it('can get government scheme for supported countries', function () {
        expect(EasCode::findGovernmentSchemeForCountry('NL'))->toBe(EasCode::NL_OIN);
        expect(EasCode::findGovernmentSchemeForCountry('DE'))->toBe(EasCode::DE_LEITWEG);
    });

    it('can get all VAT schemes', function () {
        $vatSchemes = EasCode::allVatSchemes();

        expect($vatSchemes)->toContain(EasCode::VAT_NL);
        expect($vatSchemes)->toContain(EasCode::VAT_BE);
        expect($vatSchemes)->not->toContain(EasCode::NL_KVK);
    });

    it('can get all national register schemes', function () {
        $registers = EasCode::allNationalRegisterSchemes();

        expect($registers)->toContain(EasCode::NL_KVK);
        expect($registers)->toContain(EasCode::BE_CBE);
        expect($registers)->not->toContain(EasCode::VAT_NL);
    });

    it('can get all international schemes', function () {
        $international = EasCode::allInternationalSchemes();

        expect($international)->toContain(EasCode::DUNS);
        expect($international)->toContain(EasCode::GLN);
        expect($international)->toContain(EasCode::LEI);
        expect($international)->not->toContain(EasCode::NL_KVK);
    });

    it('can guess country from identifier', function () {
        expect(EasCode::guessCountryFromIdentifier('NL123456789B01'))->toBe('NL');
        expect(EasCode::guessCountryFromIdentifier('BE0123456789'))->toBe('BE');
    });

    it('can group schemes by region', function () {
        $grouped = EasCode::groupedByRegion();

        expect($grouped)->toHaveKeys([
            'Nordic',
            'Benelux',
            'DACH',
            'Southern Europe',
            'Western Europe',
            'International',
        ]);

        expect($grouped['Benelux'])->toContain(EasCode::NL_KVK);
        expect($grouped['Benelux'])->toContain(EasCode::BE_CBE);
    });
});

describe('EasCode practical usage', function () {
    it('can handle Dutch company registration', function () {
        $scheme = EasCode::defaultSchemeForCountry('NL');

        expect($scheme)->toBe(EasCode::NL_KVK);
        expect($scheme->value)->toBe('0106');
        expect($scheme->label())->toContain('KvK');
        expect($scheme->pattern())->toBe('/^\d{8}$/');
    });

    it('can handle Belgian VAT lookup', function () {
        $scheme = EasCode::vatSchemeForCountry('BE');

        expect($scheme)->toBe(EasCode::VAT_BE);
        expect($scheme->isVatScheme())->toBeTrue();
        expect($scheme->countryCode())->toBe('BE');
    });

    it('can validate identifier format', function () {
        $kvkPattern = EasCode::NL_KVK->pattern();

        expect(preg_match($kvkPattern, '12345678'))->toBe(1);
        expect(preg_match($kvkPattern, '123456789'))->toBe(0);
    });

    it('can find all available schemes for Belgium', function () {
        $schemes = EasCode::forCountry('BE');
        $schemeCodes = array_map(fn ($s) => $s->value, $schemes);

        expect($schemeCodes)->toContain('0208'); // BE_CBE
        expect($schemeCodes)->toContain('9925'); // VAT_BE
    });
});
