<?php

declare(strict_types=1);

namespace Deinte\Peppol\Enums;

use Deinte\Peppol\Enums\Concerns\HasCountryMapping;

/**
 * Electronic Address Scheme (EAS) codes used in PEPPOL network.
 *
 * These standardized identifiers specify the type of business identifier being used.
 * Full list: https://docs.peppol.eu/poacc/billing/3.0/codelist/eas/
 *
 * @see HasCountryMapping for country-based lookups
 */
enum EasCode: string
{
    use HasCountryMapping;

    // ========================================
    // European Business Identifiers
    // ========================================

    /**
     * System Information et Repertoire des Entreprise et des Etablissements (SIRENE).
     * Used primarily in France.
     */
    case SIRENE = '0002';

    /**
     * Swedish Organisation Number.
     */
    case SE_ORGNR = '0007';

    /**
     * French SIRET code (Establishment identifier).
     */
    case FR_SIRET = '0009';

    /**
     * Finnish Organisation Number (LY-tunnus).
     */
    case FI_OVT = '0037';

    /**
     * DUNS Number (Dun & Bradstreet).
     * International business identifier.
     */
    case DUNS = '0060';

    /**
     * EAN Location Code (Global Location Number).
     * International supply chain identifier.
     */
    case GLN = '0088';

    /**
     * Danish CVR Number (Centrale Virksomhedsregister).
     */
    case DK_CVR = '0096';

    /**
     * Dutch Chamber of Commerce Number (KvK nummer).
     * Kamer van Koophandel registration number.
     */
    case NL_KVK = '0106';

    /**
     * Directorates of the European Commission.
     */
    case EU_DIRECTORATE = '0130';

    /**
     * Danish CPR Number (Personal identification).
     */
    case DK_CPR = '0184';

    /**
     * Dutch OIN (Organisatie Identificatie Nummer).
     * Government organization identifier.
     */
    case NL_OIN = '0190';

    /**
     * Dutch OINO (OIN for individuals/professionals).
     */
    case NL_OINO = '0191';

    /**
     * Norwegian Organisation Number.
     */
    case NO_ORGNR = '0192';

    /**
     * Norwegian PEPPOL identifier.
     */
    case NO_PEPPOL = '0193';

    /**
     * Singapore Unique Entity Number (UEN).
     */
    case SG_UEN = '0195';

    /**
     * Icelandic identifier (Íslensk kennitala).
     */
    case IS_KENNITALA = '0196';

    /**
     * Danish CVR (alternative code).
     */
    case DK_CVR_ALT = '0198';

    /**
     * Legal Entity Identifier (LEI).
     * Global financial identifier (ISO 17442).
     */
    case LEI = '0199';

    /**
     * Lithuanian Legal Entity Code.
     */
    case LT_LEGAL_ENTITY = '0200';

    /**
     * Italian Tax Code (Codice Fiscale) for individuals.
     */
    case IT_CF = '0201';

    /**
     * Italian VAT Number (Partita IVA).
     */
    case IT_IVA = '0202';

    /**
     * German Leitweg-ID.
     * Public sector routing identifier.
     */
    case DE_LEITWEG = '0204';

    /**
     * Belgian Enterprise Number (CBE/KBO).
     * Crossroad Bank for Enterprises number.
     */
    case BE_CBE = '0208';

    /**
     * German Company Registration Number.
     */
    case DE_COMPANY_NUMBER = '0209';

    /**
     * Italian Codice Fiscale (alternative).
     */
    case IT_CF_ALT = '0210';

    /**
     * Italian Partita IVA (alternative).
     */
    case IT_IVA_ALT = '0211';

    /**
     * Finnish OVT identifier.
     * Virtual operator identifier for e-invoicing.
     */
    case FI_OVT_CODE = '0212';

    /**
     * Danish Business Authority Code.
     */
    case DK_P_NUMBER = '0213';

    // ========================================
    // VAT Number Schemes (9901-9958)
    // ========================================

    /**
     * French VAT Number (FR:VAT).
     */
    case VAT_FR = '9957';

    /**
     * German VAT Number (DE:VAT).
     */
    case VAT_DE = '9930';

    /**
     * Irish VAT Number (IE:VAT).
     */
    case VAT_IE = '9943';

    /**
     * Italian VAT Number (IT:VAT).
     */
    case VAT_IT = '9945';

    /**
     * Luxembourg VAT Number (LU:VAT).
     */
    case VAT_LU = '9947';

    /**
     * Dutch VAT Number (NL:VAT).
     */
    case VAT_NL = '9944';

    /**
     * Belgian VAT Number (BE:VAT).
     */
    case VAT_BE = '9925';

    /**
     * Austrian VAT Number (AT:VAT).
     */
    case VAT_AT = '9914';

    /**
     * Danish VAT Number (DK:VAT).
     */
    case VAT_DK = '9924';

    /**
     * Spanish VAT Number (ES:VAT).
     */
    case VAT_ES = '9920';

    /**
     * Finnish VAT Number (FI:VAT).
     */
    case VAT_FI = '9931';

    /**
     * Greek VAT Number (GR:VAT).
     */
    case VAT_GR = '9932';

    /**
     * Portuguese VAT Number (PT:VAT).
     */
    case VAT_PT = '9946';

    /**
     * Swedish VAT Number (SE:VAT).
     */
    case VAT_SE = '9919';

    /**
     * United Kingdom VAT Number (GB:VAT).
     */
    case VAT_GB = '9933';

    /**
     * Norwegian VAT Number (NO:VAT).
     */
    case VAT_NO = '9908';

    /**
     * Swiss VAT Number (CH:VAT).
     */
    case VAT_CH = '9923';

    // ========================================
    // Instance Methods
    // ========================================

    /**
     * Get a human-readable label for the EAS code.
     */
    public function label(): string
    {
        return match ($this) {
            self::SIRENE => 'SIRENE Number',
            self::SE_ORGNR => 'Swedish Organisation Number',
            self::FR_SIRET => 'French SIRET',
            self::FI_OVT => 'Finnish LY-tunnus',
            self::DUNS => 'DUNS Number',
            self::GLN => 'Global Location Number (GLN)',
            self::DK_CVR, self::DK_CVR_ALT => 'Danish CVR',
            self::NL_KVK => 'Dutch KvK Number',
            self::EU_DIRECTORATE => 'EU Commission Directorate',
            self::DK_CPR => 'Danish CPR Number',
            self::NL_OIN => 'Dutch OIN',
            self::NL_OINO => 'Dutch OINO',
            self::NO_ORGNR => 'Norwegian Organisation Number',
            self::NO_PEPPOL => 'Norwegian PEPPOL ID',
            self::SG_UEN => 'Singapore UEN',
            self::IS_KENNITALA => 'Icelandic Kennitala',
            self::LEI => 'Legal Entity Identifier (LEI)',
            self::LT_LEGAL_ENTITY => 'Lithuanian Legal Entity Code',
            self::IT_CF, self::IT_CF_ALT => 'Italian Codice Fiscale',
            self::IT_IVA, self::IT_IVA_ALT => 'Italian Partita IVA',
            self::DE_LEITWEG => 'German Leitweg-ID',
            self::BE_CBE => 'Belgian Enterprise Number (CBE)',
            self::DE_COMPANY_NUMBER => 'German Company Number',
            self::FI_OVT_CODE => 'Finnish OVT Code',
            self::DK_P_NUMBER => 'Danish P-Number',
            self::VAT_FR => 'French VAT',
            self::VAT_DE => 'German VAT',
            self::VAT_IE => 'Irish VAT',
            self::VAT_IT => 'Italian VAT',
            self::VAT_LU => 'Luxembourg VAT',
            self::VAT_NL => 'Dutch VAT',
            self::VAT_BE => 'Belgian VAT',
            self::VAT_AT => 'Austrian VAT',
            self::VAT_DK => 'Danish VAT',
            self::VAT_ES => 'Spanish VAT',
            self::VAT_FI => 'Finnish VAT',
            self::VAT_GR => 'Greek VAT',
            self::VAT_PT => 'Portuguese VAT',
            self::VAT_SE => 'Swedish VAT',
            self::VAT_GB => 'UK VAT',
            self::VAT_NO => 'Norwegian VAT',
            self::VAT_CH => 'Swiss VAT',
        };
    }

    /**
     * Get a detailed description of the EAS code.
     */
    public function description(): string
    {
        return match ($this) {
            self::SIRENE => 'French business registry system identifier',
            self::SE_ORGNR => 'Swedish national business registration number',
            self::FR_SIRET => 'French establishment identifier (14 digits)',
            self::FI_OVT => 'Finnish business identifier',
            self::DUNS => 'International D-U-N-S Number by Dun & Bradstreet',
            self::GLN => 'Global Location Number used in supply chain',
            self::DK_CVR, self::DK_CVR_ALT => 'Danish Central Business Register number',
            self::NL_KVK => 'Dutch Chamber of Commerce registration number',
            self::EU_DIRECTORATE => 'European Commission directorate identifier',
            self::DK_CPR => 'Danish personal identification number',
            self::NL_OIN => 'Dutch government organization identifier',
            self::NL_OINO => 'Dutch organization identifier for professionals',
            self::NO_ORGNR => 'Norwegian national business registration number',
            self::NO_PEPPOL => 'Norwegian PEPPOL network identifier',
            self::SG_UEN => 'Singapore Unique Entity Number',
            self::IS_KENNITALA => 'Icelandic national identification number',
            self::LEI => 'Global Legal Entity Identifier (ISO 17442)',
            self::LT_LEGAL_ENTITY => 'Lithuanian business registration code',
            self::IT_CF, self::IT_CF_ALT => 'Italian tax code for individuals',
            self::IT_IVA, self::IT_IVA_ALT => 'Italian VAT registration number',
            self::DE_LEITWEG => 'German public sector routing identifier',
            self::BE_CBE => 'Belgian Crossroad Bank for Enterprises number',
            self::DE_COMPANY_NUMBER => 'German company registration number',
            self::FI_OVT_CODE => 'Finnish electronic invoicing operator identifier',
            self::DK_P_NUMBER => 'Danish production unit number',
            self::VAT_FR => 'French VAT identification number',
            self::VAT_DE => 'German VAT identification number',
            self::VAT_IE => 'Irish VAT identification number',
            self::VAT_IT => 'Italian VAT identification number',
            self::VAT_LU => 'Luxembourg VAT identification number',
            self::VAT_NL => 'Dutch VAT identification number (BTW-nummer)',
            self::VAT_BE => 'Belgian VAT identification number',
            self::VAT_AT => 'Austrian VAT identification number',
            self::VAT_DK => 'Danish VAT identification number',
            self::VAT_ES => 'Spanish VAT identification number (NIF/CIF)',
            self::VAT_FI => 'Finnish VAT identification number',
            self::VAT_GR => 'Greek VAT identification number',
            self::VAT_PT => 'Portuguese VAT identification number',
            self::VAT_SE => 'Swedish VAT identification number',
            self::VAT_GB => 'United Kingdom VAT identification number',
            self::VAT_NO => 'Norwegian VAT identification number (MVA)',
            self::VAT_CH => 'Swiss VAT identification number (MWST/TVA/IVA)',
        };
    }

    /**
     * Check if this is a VAT scheme identifier.
     */
    public function isVatScheme(): bool
    {
        return str_starts_with($this->value, '99');
    }

    /**
     * Check if this is a national business register identifier.
     */
    public function isNationalRegister(): bool
    {
        return in_array($this, [
            self::SE_ORGNR,
            self::DK_CVR,
            self::DK_CVR_ALT,
            self::NL_KVK,
            self::NO_ORGNR,
            self::BE_CBE,
            self::DE_COMPANY_NUMBER,
            self::FI_OVT,
        ]);
    }

    /**
     * Check if this is an international identifier scheme.
     */
    public function isInternational(): bool
    {
        return in_array($this, [
            self::DUNS,
            self::GLN,
            self::LEI,
        ]);
    }

    /**
     * Get the ISO 3166-1 alpha-2 country code if applicable.
     * Returns null for international schemes.
     */
    public function countryCode(): ?string
    {
        return match ($this) {
            self::SIRENE, self::FR_SIRET, self::VAT_FR => 'FR',
            self::SE_ORGNR, self::VAT_SE => 'SE',
            self::FI_OVT, self::FI_OVT_CODE, self::VAT_FI => 'FI',
            self::DK_CVR, self::DK_CVR_ALT, self::DK_CPR, self::DK_P_NUMBER, self::VAT_DK => 'DK',
            self::NL_KVK, self::NL_OIN, self::NL_OINO, self::VAT_NL => 'NL',
            self::NO_ORGNR, self::NO_PEPPOL, self::VAT_NO => 'NO',
            self::BE_CBE, self::VAT_BE => 'BE',
            self::DE_LEITWEG, self::DE_COMPANY_NUMBER, self::VAT_DE => 'DE',
            self::IT_CF, self::IT_CF_ALT, self::IT_IVA, self::IT_IVA_ALT, self::VAT_IT => 'IT',
            self::VAT_AT => 'AT',
            self::VAT_ES => 'ES',
            self::VAT_GR => 'GR',
            self::VAT_PT => 'PT',
            self::VAT_GB => 'GB',
            self::VAT_CH => 'CH',
            self::VAT_IE => 'IE',
            self::VAT_LU => 'LU',
            self::LT_LEGAL_ENTITY => 'LT',
            self::SG_UEN => 'SG',
            self::IS_KENNITALA => 'IS',
            default => null,
        };
    }

    /**
     * Get formatting pattern/validation regex for the identifier.
     * Returns null if no specific pattern is defined.
     */
    public function pattern(): ?string
    {
        return match ($this) {
            self::FR_SIRET => '/^\d{14}$/',
            self::DK_CVR, self::DK_CVR_ALT => '/^\d{8}$/',
            self::NL_KVK => '/^\d{8}$/',
            self::NO_ORGNR => '/^\d{9}$/',
            self::BE_CBE => '/^[01]\d{9}$/',
            self::DK_CPR => '/^\d{10}$/',
            self::SE_ORGNR => '/^\d{6}-?\d{4}$/',
            self::FI_OVT => '/^\d{7}-\d$/',
            self::DUNS => '/^\d{9}$/',
            self::GLN => '/^\d{13}$/',
            default => null,
        };
    }
}
