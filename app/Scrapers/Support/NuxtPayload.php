<?php

namespace App\Scrapers\Support;

/**
 * Dekoduje __NUXT_DATA__ (Nuxt 3, format "devalue"): płaska tablica, w której wartości
 * to indeksy innych elementów, a typy specjalne to listy typu ["Reactive", indeks].
 */
class NuxtPayload
{
    private const WRAPPERS = ['ShallowReactive', 'Reactive', 'Ref', 'ShallowRef', 'EmptyRef', 'EmptyShallowRef', 'NuxtError'];

    /** @var array<int, mixed> */
    private array $memo = [];

    /**
     * @param  list<mixed>  $raw
     */
    private function __construct(private array $raw) {}

    /**
     * Zdekodowane dane strony albo null, gdy strona nie ma __NUXT_DATA__.
     */
    public static function fromHtml(string $html): ?array
    {
        if (! preg_match('#<script[^>]*id="__NUXT_DATA__"[^>]*>(.*?)</script>#s', $html, $match)) {
            return null;
        }

        $raw = json_decode($match[1], true);

        return is_array($raw) && $raw !== [] ? (new self($raw))->resolve(0) : null;
    }

    private function resolve(mixed $index): mixed
    {
        if (! is_int($index)) {
            return $index;
        }

        if ($index < 0 || ! array_key_exists($index, $this->raw)) {
            return null;
        }

        if (array_key_exists($index, $this->memo)) {
            return $this->memo[$index];
        }

        $value = $this->raw[$index];

        if (! is_array($value)) {
            return $this->memo[$index] = $value;
        }

        if (array_is_list($value) && isset($value[0]) && is_string($value[0])) {
            $type = $value[0];

            if (in_array($type, self::WRAPPERS, true)) {
                return $this->memo[$index] = isset($value[1]) ? $this->resolve($value[1]) : null;
            }

            switch ($type) {
                case 'Date':
                    return $this->memo[$index] = $value[1] ?? null;
                case 'Set':
                    return $this->memo[$index] = array_map($this->resolve(...), array_slice($value, 1));
                case 'Map':
                    $map = [];
                    for ($i = 1; $i + 1 < count($value); $i += 2) {
                        $map[(string) $this->resolve($value[$i])] = $this->resolve($value[$i + 1]);
                    }

                    return $this->memo[$index] = $map;
                case 'null':
                case 'undefined':
                    return $this->memo[$index] = null;
            }
        }

        // Zwykła lista albo obiekt: każda wartość to indeks.
        $this->memo[$index] = [];
        $resolved = [];

        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolve($item);
        }

        return $this->memo[$index] = $resolved;
    }
}
