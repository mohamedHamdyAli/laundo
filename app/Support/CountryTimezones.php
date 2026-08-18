<?php

namespace App\Support;

class CountryTimezones
{
    /**
     * ISO 3166-1 alpha-2 country code => primary IANA timezone.
     * Covers common/well-known countries; admins can override per-country in the Country form.
     *
     * @var array<string, string>
     */
    protected const MAP = [
        'KW' => 'Asia/Kuwait',
        'SA' => 'Asia/Riyadh',
        'AE' => 'Asia/Dubai',
        'QA' => 'Asia/Qatar',
        'BH' => 'Asia/Bahrain',
        'OM' => 'Asia/Muscat',
        'EG' => 'Africa/Cairo',
        'JO' => 'Asia/Amman',
        'LB' => 'Asia/Beirut',
        'IQ' => 'Asia/Baghdad',
        'SY' => 'Asia/Damascus',
        'YE' => 'Asia/Aden',
        'PS' => 'Asia/Gaza',
        'MA' => 'Africa/Casablanca',
        'DZ' => 'Africa/Algiers',
        'TN' => 'Africa/Tunis',
        'LY' => 'Africa/Tripoli',
        'SD' => 'Africa/Khartoum',
        'TR' => 'Europe/Istanbul',
        'IR' => 'Asia/Tehran',
        'PK' => 'Asia/Karachi',
        'IN' => 'Asia/Kolkata',
        'BD' => 'Asia/Dhaka',
        'PH' => 'Asia/Manila',
        'ID' => 'Asia/Jakarta',
        'MY' => 'Asia/Kuala_Lumpur',
        'SG' => 'Asia/Singapore',
        'CN' => 'Asia/Shanghai',
        'JP' => 'Asia/Tokyo',
        'KR' => 'Asia/Seoul',
        'US' => 'America/New_York',
        'CA' => 'America/Toronto',
        'GB' => 'Europe/London',
        'FR' => 'Europe/Paris',
        'DE' => 'Europe/Berlin',
        'IT' => 'Europe/Rome',
        'ES' => 'Europe/Madrid',
        'RU' => 'Europe/Moscow',
        'AU' => 'Australia/Sydney',
        'BR' => 'America/Sao_Paulo',
        'ZA' => 'Africa/Johannesburg',
        'NG' => 'Africa/Lagos',
        'ET' => 'Africa/Addis_Ababa',
    ];

    public static function resolve(?string $countryCode): ?string
    {
        if (!$countryCode) {
            return null;
        }

        return self::MAP[strtoupper($countryCode)] ?? null;
    }
}
