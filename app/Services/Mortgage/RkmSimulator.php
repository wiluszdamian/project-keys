<?php

namespace App\Services\Mortgage;

use App\Models\Listing;
use App\Models\RcnTransaction;
use Illuminate\Support\Str;

/**
 * Symulacja Rodzinnego Kredytu Mieszkaniowego: gwarancja BGK zamiast wkładu własnego,
 * limit ceny za m², rata (stała przez 5 lat albo zmienna), spłata rodzinna z BGK.
 */
class RkmSimulator
{
    public const FIXED = 'fixed';

    public const VARIABLE = 'variable';

    /**
     * Domyślne dane do formularza, opcjonalnie z oferty.
     *
     * @return array<string, mixed>
     */
    public static function defaults(?Listing $listing = null): array
    {
        $price = $listing?->price ?? 500_000;

        return [
            'price' => $price,
            'area' => $listing?->area !== null ? (float) $listing->area : null,
            'zone' => $listing ? self::zone($listing->city) : 'rzeszow',
            'market' => $listing?->market() ?? RcnTransaction::SECONDARY,
            'own' => self::requiredOwn($price),
            'years' => 30,
            'rate_type' => self::FIXED,
            'fixed_rate' => config('mortgage.fixed_rate'),
            'index_rate' => config('mortgage.index_rate'),
            'margin' => config('mortgage.margin'),
            'bridge' => true,
            'commission' => 0.0,
            'second_child_year' => null,
            'third_child_year' => null,
        ];
    }

    /**
     * Strefa limitu ceny BGK po nazwie miejscowości.
     */
    public static function zone(?string $city): string
    {
        $city = Str::lower(trim((string) $city));

        return match (true) {
            $city === 'rzeszów' || $city === 'rzeszow' => 'rzeszow',
            in_array($city, config('mortgage.rkm.neighbour_places'), true) => 'neighbour',
            default => 'other',
        };
    }

    /**
     * Najmniejszy wkład własny: gwarancja BGK pokrywa 20% ceny, ale najwyżej 100 000 zł.
     */
    public static function requiredOwn(float $price): float
    {
        $rkm = config('mortgage.rkm');

        return max(0.0, round($price * $rkm['guarantee_share'] - $rkm['guarantee_max'], 2));
    }

    /**
     * @param  array<string, mixed>  $input  pola jak w defaults()
     * @return array<string, mixed>
     */
    public function simulate(array $input): array
    {
        $rkm = config('mortgage.rkm');
        $price = max(0.0, (float) ($input['price'] ?? 0));
        $years = max($rkm['min_years'], min($rkm['max_years'], (int) ($input['years'] ?? 30)));
        $months = $years * 12;
        $fixed = ($input['rate_type'] ?? self::FIXED) === self::FIXED;
        $requiredOwn = self::requiredOwn($price);
        $own = max(0.0, (float) ($input['own'] ?? 0));
        $warnings = [];

        if ($own < $requiredOwn) {
            $warnings[] = 'Gwarancja BGK pokrywa najwyżej '.self::money($rkm['guarantee_max']).', więc przy tej cenie trzeba mieć co najmniej '.self::money($requiredOwn).' wkładu. Liczę z tą kwotą.';
            $own = $requiredOwn;
        }

        $ownShareMax = $fixed ? 0.30 : 0.20;
        if ($price > 0 && $own > $price * $ownShareMax) {
            $warnings[] = 'W RKM wkład własny może wynosić najwyżej '.($ownShareMax * 100).'% ceny przy oprocentowaniu '.($fixed ? 'stałym' : 'zmiennym').'. Z takim wkładem to już zwykły kredyt.';
        }

        $guarantee = max(0.0, min($price * $rkm['guarantee_share'] - $own, $rkm['guarantee_max']));

        if ($own + $guarantee > $rkm['own_plus_guarantee_max']) {
            $warnings[] = 'Wkład własny razem z gwarancją przekracza '.self::money($rkm['own_plus_guarantee_max']).', więc cena jest za wysoka na RKM.';
        }

        $loan = max(0.0, $price - $own);
        $limit = $this->priceLimit($input, $price);

        $variableRate = round((float) $input['index_rate'] + (float) $input['margin'], 2);
        $rates = $this->rates($fixed, (float) ($input['fixed_rate'] ?? 0), $variableRate, (bool) ($input['bridge'] ?? false), $months);
        $prepayments = $this->familyRepayments($input, $months);

        $schedule = MortgageCalculator::schedule($loan, $months, $rates);
        $withChildren = $prepayments ? MortgageCalculator::schedule($loan, $months, $rates, $prepayments) : null;
        $stressRates = $this->rates($fixed, (float) ($input['fixed_rate'] ?? 0), $variableRate + config('mortgage.stress_extra'), false, $months);
        $stress = MortgageCalculator::schedule($loan, $months, $stressRates);

        $fees = [
            'guarantee_fee' => round($guarantee * $rkm['guarantee_fee'], 2),
            'commission' => round($loan * (float) ($input['commission'] ?? 0) / 100, 2),
            'valuation' => (float) config('mortgage.valuation_fee'),
        ];

        return [
            'price' => $price,
            'own' => $own,
            'required_own' => $requiredOwn,
            'guarantee' => $guarantee,
            'loan' => $loan,
            'months' => $months,
            'years' => $years,
            'fixed' => $fixed,
            'variable_rate' => $variableRate,
            'limit' => $limit,
            'fees' => $fees,
            'fees_total' => array_sum($fees),
            'schedule' => $schedule,
            // Rata po pomostowym (albo pierwsza, gdy go nie ma).
            'installment' => $this->installmentAfterBridge($schedule['periods']),
            'installment_bridge' => ($input['bridge'] ?? false) ? $schedule['periods'][0]['installment'] : null,
            'installment_after_fixed' => $fixed ? $this->installmentFrom($schedule['periods'], config('mortgage.fixed_years') * 12 + 1) : null,
            'stress_installment' => $fixed ? $this->installmentFrom($stress['periods'], config('mortgage.fixed_years') * 12 + 1) : $stress['periods'][0]['installment'],
            'with_children' => $withChildren,
            'family_repayments' => array_sum($prepayments),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{price_per_m2: float, limit: float, zone: string, ok: bool, quarter: string}|null
     */
    private function priceLimit(array $input, float $price): ?array
    {
        $area = (float) ($input['area'] ?? 0);
        $zone = config('mortgage.rkm.limits.'.($input['zone'] ?? 'other')) ?? config('mortgage.rkm.limits.other');

        if ($area <= 0 || $price <= 0) {
            return null;
        }

        $limit = ($input['market'] ?? null) === RcnTransaction::PRIMARY ? $zone['primary'] : $zone['secondary'];
        $perM2 = round($price / $area, 2);

        return [
            'price_per_m2' => $perM2,
            'limit' => $limit,
            'zone' => $zone['label'],
            'ok' => $perM2 <= $limit,
            'quarter' => config('mortgage.rkm.limits_quarter'),
            'min_area' => round($price / $limit, 1),
        ];
    }

    /**
     * @return array<int, float>
     */
    private function rates(bool $fixed, float $fixedRate, float $variableRate, bool $bridge, int $months): array
    {
        $start = $fixed ? $fixedRate : $variableRate;
        $rates = [1 => $start];

        if ($bridge) {
            $rates[1] = $start + config('mortgage.bridge_extra');
            $rates[config('mortgage.bridge_months') + 1] = $start;
        }

        $fixedMonths = config('mortgage.fixed_years') * 12;

        if ($fixed && $months > $fixedMonths) {
            $rates[$fixedMonths + 1] = $variableRate;
        }

        return $rates;
    }

    /**
     * Spłata rodzinna z BGK: 20 000 zł przy drugim dziecku, 60 000 zł przy trzecim (w roku kredytu z formularza).
     *
     * @return array<int, float>
     */
    private function familyRepayments(array $input, int $months): array
    {
        $prepayments = [];

        foreach (['second_child_year' => 'family_repayment_second', 'third_child_year' => 'family_repayment_third'] as $field => $amount) {
            $year = (int) ($input[$field] ?? 0);

            if ($year >= 1 && $year * 12 < $months) {
                $month = $year * 12;
                $prepayments[$month] = ($prepayments[$month] ?? 0) + config("mortgage.rkm.{$amount}");
            }
        }

        return $prepayments;
    }

    /**
     * @param  list<array{from: int, rate: float, installment: float}>  $periods
     */
    private function installmentFrom(array $periods, int $month): float
    {
        $installment = $periods[0]['installment'];

        foreach ($periods as $period) {
            if ($period['from'] <= $month) {
                $installment = $period['installment'];
            }
        }

        return $installment;
    }

    /**
     * @param  list<array{from: int, rate: float, installment: float}>  $periods
     */
    private function installmentAfterBridge(array $periods): float
    {
        return $this->installmentFrom($periods, config('mortgage.bridge_months') + 1);
    }

    public static function money(float|int|null $value): string
    {
        return number_format((float) $value, 0, ',', ' ').' zł';
    }
}
