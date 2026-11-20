{{--
    Prosta tabela w podglądzie oferty. Na telefonie przewija się w poziomie zamiast rozpadać na karty.
    $columns: [klucz => nagłówek]. Wiersz to tablica z tymi kluczami, opcjonalnie "{klucz}_url" dla linku.
--}}
@php($rows = $getState() ?? [])

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
<div class="pk-scroll-x">
    <table class="pk-rows">
        <thead>
            <tr>
                @foreach ($columns as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($columns as $key => $label)
                        <td>
                            @if (! empty($row[$key.'_url']))
                                <a href="{{ $row[$key.'_url'] }}" target="_blank" rel="noopener" class="pk-rows__link">{{ $row[$key] }}</a>
                            @else
                                {{ $row[$key] ?? '-' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
</x-dynamic-component>
