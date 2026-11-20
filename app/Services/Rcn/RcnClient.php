<?php

namespace App\Services\Rcn;

use App\Models\RcnTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * Pobiera sprzedaże mieszkań z usługi WFS Rejestru Cen Nieruchomości (warstwa ms:lokale).
 */
class RcnClient
{
    private const MS = 'http://mapserver.gis.umn.edu/mapserver';

    private const GML = 'http://www.opengis.net/gml/3.2';

    /**
     * Wszystkie strony wyników dla powiatu. Zwraca rekordy gotowe do zapisu, po kolei ze stron.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function pages(string $teryt, CarbonInterface $since): \Generator
    {
        $size = (int) config('rcn.page_size');

        for ($start = 0; $start < 200_000; $start += $size) {
            $body = Http::timeout((int) config('rcn.timeout'))
                ->retry(3, 5000)
                ->get(config('rcn.wfs_url'), [
                    'SERVICE' => 'WFS',
                    'VERSION' => '2.0.0',
                    'REQUEST' => 'GetFeature',
                    'TYPENAMES' => 'ms:lokale',
                    'COUNT' => $size,
                    'STARTINDEX' => $start,
                    'SORTBY' => 'gid',
                    'FILTER' => $this->filter($teryt, $since),
                ])
                ->throw()
                ->body();

            $records = $this->parse($body);

            if ($records === []) {
                return;
            }

            yield $records;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $gml): array
    {
        if (str_contains($gml, 'ExceptionReport')) {
            throw new RuntimeException('RCN: usługa zwróciła błąd: '.mb_substr(strip_tags($gml), 0, 300));
        }

        $reader = new XMLReader;
        $reader->XML($gml, null, LIBXML_NONET | LIBXML_COMPACT);
        $records = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'lokale' || $reader->namespaceURI !== self::MS) {
                continue;
            }

            $node = simplexml_import_dom($reader->expand(new \DOMDocument));
            $record = $node !== null ? $this->record($node) : null;

            if ($record !== null) {
                $records[] = $record;
            }

            $reader->next();
        }

        $reader->close();

        return $records;
    }

    /**
     * Rekord do zapisu albo null, gdy to nie zwykła sprzedaż mieszkania z sensowną ceną i metrażem.
     */
    private function record(SimpleXMLElement $node): ?array
    {
        $ms = $node->children(self::MS);
        $text = fn (string $field) => trim((string) $ms->{$field}) ?: null;
        $time = fn (string $field) => trim((string) $ms->{$field}?->children(self::GML)->timePosition) ?: null;

        if ($text('LOK_FUNKCJA') !== 'mieszkalna' || $text('TRAN_RODZAJ_TRANS') !== 'wolnyRynek') {
            return null;
        }

        $area = (float) $text('LOK_POW_UZYT');
        $price = (float) ($text('LOK_CENA_BRUTTO') ?? $text('NIER_CENA_BRUTTO') ?? $text('TRAN_CENA_BRUTTO'));
        $date = $time('DOK_DATA');
        $position = preg_split('/\s+/', trim((string) $ms->GEOMETRIA?->children(self::GML)->Point?->pos));

        // Data z przyszłości to błąd w rejestrze.
        if ($area < 10 || $price <= 0 || $date === null || substr($date, 0, 10) > now()->toDateString() || count($position) !== 2) {
            return null;
        }

        $pricePerM2 = (int) round($price / $area);

        if ($pricePerM2 < config('rcn.min_price_per_m2') || $pricePerM2 > config('rcn.max_price_per_m2')) {
            return null;
        }

        [$lat, $lng] = Puwg92::toWgs84((float) $position[0], (float) $position[1]);

        return [
            'rcn_id' => $text('TRAN_LOKALNY_ID_IIP').'/'.$text('LOK_ID_LOKALU'),
            'teryt' => (string) $text('TERYT'),
            'transaction_ref' => $text('TRAN_OZNACZENIE_TRANS'),
            'date' => substr($date, 0, 10),
            'market' => match ($text('TRAN_RODZAJ_RYNKU')) {
                'rynekPierwotny' => RcnTransaction::PRIMARY,
                'rynekWtorny' => RcnTransaction::SECONDARY,
                default => null,
            },
            'price' => (int) round($price),
            'area' => round($area, 2),
            'price_per_m2' => $pricePerM2,
            'rooms' => $text('LOK_LICZBA_IZB') !== null ? (int) $text('LOK_LICZBA_IZB') : null,
            'floor' => $text('LOK_NR_KOND') !== null ? (int) $text('LOK_NR_KOND') : null,
            'address' => $text('LOK_ADRES'),
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
        ];
    }

    private function filter(string $teryt, CarbonInterface $since): string
    {
        $equal = fn (string $field, string $value) => '<fes:PropertyIsEqualTo><fes:ValueReference>'.$field.'</fes:ValueReference><fes:Literal>'.htmlspecialchars($value, ENT_XML1).'</fes:Literal></fes:PropertyIsEqualTo>';

        return '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:And>'
            .$equal('TERYT', $teryt)
            .$equal('LOK_FUNKCJA', 'mieszkalna')
            .'<fes:PropertyIsGreaterThanOrEqualTo><fes:ValueReference>DOK_DATA</fes:ValueReference><fes:Literal>'.$since->format('Y-m-d').'</fes:Literal></fes:PropertyIsGreaterThanOrEqualTo>'
            .'</fes:And></fes:Filter>';
    }
}
