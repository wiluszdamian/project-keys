<?php

namespace App\Support;

use App\Models\Listing;
use App\Services\ViewingQuestions;
use Illuminate\Support\Str;

/**
 * Plik .ics z terminem oglądania (do kalendarza w telefonie). Godziny w UTC, więc strefa się zgadza.
 */
class ViewingCalendar
{
    public const MINUTES = 45;

    public static function ics(Listing $listing): string
    {
        $start = $listing->viewing_at->copy()->utc();
        $end = $start->copy()->addMinutes(self::MINUTES);
        $address = collect([$listing->street, $listing->district, $listing->city])->filter()->unique()->implode(', ');
        $questions = array_slice([...array_column(ViewingQuestions::fromAi($listing), 'question'), ...ViewingQuestions::forListing($listing)], 0, 10);

        $description = implode("\n", array_filter([
            route('filament.admin.resources.listings.view', $listing),
            $listing->url,
            $questions !== [] ? "\nPytania:\n- ".implode("\n- ", $questions) : null,
        ]));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//project-keys//ogladanie//PL',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:viewing-'.$listing->id.'-'.$listing->viewing_at->timestamp.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'project-keys'),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.self::escape('Oglądanie: '.Str::limit($listing->title, 80)),
            $address !== '' ? 'LOCATION:'.self::escape($address) : null,
            'DESCRIPTION:'.self::escape($description),
            'URL:'.self::escape(route('filament.admin.resources.listings.view', $listing)),
            'BEGIN:VALARM',
            'TRIGGER:-PT1H',
            'ACTION:DISPLAY',
            'DESCRIPTION:'.self::escape('Oglądanie za godzinę'),
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), array_filter($lines)))."\r\n";
    }

    public static function filename(Listing $listing): string
    {
        return 'ogladanie-'.$listing->viewing_at->format('Y-m-d-Hi').'-'.$listing->id.'.ics';
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /**
     * Linie dłuższe niż 75 bajtów dzielimy (RFC 5545), nie rozcinając znaków UTF-8.
     */
    private static function fold(string $line): string
    {
        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > 74) {
                $out .= $current."\r\n ";
                $current = '';
            }

            $current .= $char;
        }

        return $out.$current;
    }
}
