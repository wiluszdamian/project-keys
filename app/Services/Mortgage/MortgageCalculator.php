<?php

namespace App\Services\Mortgage;

/**
 * Rachunek kredytu z ratą równą: harmonogram ze zmianami oprocentowania i jednorazowymi spłatami.
 * Kwoty w złotych, oprocentowanie w procentach rocznie, raty miesięczne.
 */
class MortgageCalculator
{
    /**
     * Rata równa (annuitetowa).
     */
    public static function annuity(float $principal, float $ratePercent, int $months): float
    {
        if ($principal <= 0 || $months <= 0) {
            return 0.0;
        }

        $r = $ratePercent / 100 / 12;

        if ($r == 0.0) {
            return $principal / $months;
        }

        return $principal * $r / (1 - (1 + $r) ** -$months);
    }

    /**
     * Harmonogram raty równej. Po zmianie oprocentowania i po jednorazowej spłacie rata jest liczona
     * od nowa na pozostały okres (koniec kredytu bez zmian, niższa albo wyższa rata).
     *
     * @param  array<int, float>  $rates  [od którego miesiąca => oprocentowanie], musi zawierać miesiąc 1
     * @param  array<int, float>  $prepayments  [w którym miesiącu, po racie => kwota]
     * @return array{
     *     interest: float,
     *     months: int,
     *     periods: list<array{from: int, rate: float, installment: float}>,
     *     years: list<array{year: int, paid: float, capital: float, interest: float, balance: float}>
     * }
     */
    public static function schedule(float $principal, int $months, array $rates, array $prepayments = []): array
    {
        ksort($rates);
        $balance = $principal;
        $rate = $rates[1] ?? reset($rates);
        $installment = self::annuity($balance, $rate, $months);
        $periods = [['from' => 1, 'rate' => $rate, 'installment' => $installment]];
        $years = [];
        $totalInterest = 0.0;
        $paidMonths = 0;

        for ($month = 1; $month <= $months && $balance > 0.005; $month++) {
            if ($month > 1 && isset($rates[$month]) && $rates[$month] != $rate) {
                $rate = $rates[$month];
                $installment = self::annuity($balance, $rate, $months - $month + 1);
                $periods[] = ['from' => $month, 'rate' => $rate, 'installment' => $installment];
            }

            $interest = $balance * $rate / 100 / 12;
            $capital = min($balance, $installment - $interest);
            $balance -= $capital;
            $totalInterest += $interest;
            $paidMonths = $month;

            $year = intdiv($month - 1, 12) + 1;
            $years[$year] ??= ['year' => $year, 'paid' => 0.0, 'capital' => 0.0, 'interest' => 0.0, 'balance' => 0.0];
            $years[$year]['paid'] += $capital + $interest;
            $years[$year]['capital'] += $capital;
            $years[$year]['interest'] += $interest;

            if (isset($prepayments[$month]) && $balance > 0.005 && $month < $months) {
                $paid = min($balance, $prepayments[$month]);
                $balance -= $paid;
                $years[$year]['capital'] += $paid;
                $installment = self::annuity($balance, $rate, $months - $month);
                $periods[] = ['from' => $month + 1, 'rate' => $rate, 'installment' => $installment];
            }

            $years[$year]['balance'] = max(0.0, $balance);
        }

        return [
            'interest' => $totalInterest,
            'months' => $paidMonths,
            'periods' => $periods,
            'years' => array_values($years),
        ];
    }
}
