<?php

declare(strict_types=1);

namespace Davix\Customs\Tariff;

/**
 * The series a measure type belongs to.
 *
 * The single most useful classifier in the payload, and a far better signal
 * than inspecting conditions. Series A is prohibition, B is controls and
 * licences, C is applicable duty. Observed live: 103 Third country duty (C),
 * 277 Import prohibition (A), 351 HSE Import Licence (B), 109 Supplementary
 * unit (O), 305 Value added tax (P), 695 Additional duties (J).
 *
 * The set is open — HMRC may introduce series this does not name — so unknown
 * values are preserved rather than rejected.
 */
final class MeasureTypeSeries
{
    public const PROHIBITION = 'A';
    public const CONTROL = 'B';
    public const DUTY = 'C';
    public const ADDITIONAL_DUTY = 'J';
    public const SUPPLEMENTARY_UNIT = 'O';
    public const VAT = 'P';

    private function __construct()
    {
    }
}
