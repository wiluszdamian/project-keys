<?php

namespace App\Ai\Tools;

use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Jedyna zmiana, jaką może zrobić asystent: status oferty i notatka, na wyraźną prośbę właściciela.
 */
class UpdateListingTool implements Tool
{
    public function name(): string
    {
        return 'update_listing';
    }

    public function description(): Stringable|string
    {
        return 'Ustawia status oferty (favorite = ulubiona, contacted = kontakt, viewing = oglądanie, rejected = odrzucona, none = bez statusu) '
            .'i dopisuje notatkę. Używaj tylko, gdy właściciel wprost o to prosi. Powód (np. dlaczego odrzuca) wpisz w reason, trafi do notatki i do nauki oceny AI.';
    }

    public function handle(Request $request): Stringable|string
    {
        $listing = Listing::find((int) ($request['listing_id'] ?? 0));

        if (! $listing) {
            return 'Nie ma oferty o takim id.';
        }

        $statusValue = $request['status'] ?? null;
        $reason = trim((string) ($request['reason'] ?? ''));
        $note = trim((string) ($request['note'] ?? ''));

        if ($statusValue !== null) {
            $status = $statusValue === 'none' ? null : ListingStatus::tryFrom((string) $statusValue);

            if ($statusValue !== 'none' && $status === null) {
                return 'Nieznany status.';
            }

            $listing->applyStatus($status, $reason !== '' ? $reason : null);
        }

        if ($note !== '') {
            $listing->update(['note' => trim(trim((string) $listing->note)."\n".$note)]);
        }

        $listing->refresh();

        return json_encode([
            'id' => $listing->id,
            'title' => $listing->title,
            'status' => $listing->status?->getLabel() ?? 'bez statusu',
            'note' => $listing->note,
            'panel_url' => route('filament.admin.resources.listings.view', $listing),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_id' => $schema->integer()->required(),
            'status' => $schema->string()->enum(['favorite', 'contacted', 'viewing', 'rejected', 'none']),
            'reason' => $schema->string()->description('Powód statusu, np. "za daleko od pracy". Trafia do notatki.'),
            'note' => $schema->string()->description('Tekst dopisany do notatki oferty.'),
        ];
    }
}
