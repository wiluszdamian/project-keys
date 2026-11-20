<?php

namespace App\Services\Rcn;

/**
 * Współrzędne PUWG 1992 (EPSG:2180) na WGS84. Odwrotne odwzorowanie Gaussa-Krügera na elipsoidzie GRS80.
 * Usługa RCN zwraca punkty w EPSG:2180 nawet przy prośbie o EPSG:4326.
 */
class Puwg92
{
    private const A = 6378137.0;

    private const F = 1 / 298.257222101;

    private const K0 = 0.9993;

    private const LON0 = 19.0;

    private const FALSE_EASTING = 500000.0;

    private const FALSE_NORTHING = -5300000.0;

    /**
     * @param  float  $x  północ (pierwsza liczba w gml:pos)
     * @param  float  $y  wschód (druga liczba w gml:pos)
     * @return array{0: float, 1: float} [lat, lng]
     */
    public static function toWgs84(float $x, float $y): array
    {
        $e2 = self::F * (2 - self::F);
        $ep2 = $e2 / (1 - $e2);

        $m = ($x - self::FALSE_NORTHING) / self::K0;
        $mu = $m / (self::A * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));

        $phi1 = $mu
            + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu)
            + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu)
            + (1097 * $e1 ** 4 / 512) * sin(8 * $mu);

        $sin = sin($phi1);
        $c1 = $ep2 * cos($phi1) ** 2;
        $t1 = tan($phi1) ** 2;
        $n1 = self::A / sqrt(1 - $e2 * $sin ** 2);
        $r1 = self::A * (1 - $e2) / (1 - $e2 * $sin ** 2) ** 1.5;
        $d = ($y - self::FALSE_EASTING) / ($n1 * self::K0);

        $lat = $phi1 - ($n1 * tan($phi1) / $r1) * (
            $d ** 2 / 2
            - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ep2 - 3 * $c1 ** 2) * $d ** 6 / 720
        );

        $lng = deg2rad(self::LON0) + (
            $d
            - (1 + 2 * $t1 + $c1) * $d ** 3 / 6
            + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ep2 + 24 * $t1 ** 2) * $d ** 5 / 120
        ) / cos($phi1);

        return [rad2deg($lat), rad2deg($lng)];
    }
}
