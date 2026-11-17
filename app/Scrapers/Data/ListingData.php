<?php

namespace App\Scrapers\Data;

use App\Enums\DealType;

/**
 * Jedno ogłoszenie odczytane z listy wyników portalu.
 */
final readonly class ListingData
{
    public function __construct(
        public string $portal,
        public string $externalId,
        public string $url,
        public string $title,
        public DealType $dealType,
        public ?int $price = null,
        public ?int $rentExtra = null,
        public ?float $area = null,
        public ?int $rooms = null,
        public ?string $floor = null,
        public ?string $city = null,
        public ?string $district = null,
        public ?string $street = null,
        public ?bool $isPrivateOwner = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public array $raw = [],
    ) {}

    public function pricePerM2(): ?int
    {
        if ($this->price === null || ! $this->area) {
            return null;
        }

        return (int) round($this->price / $this->area);
    }

    /**
     * Hash treści do wykrywania zmian (tytuł, cena, metraż, opis).
     */
    public function contentHash(): string
    {
        return self::hashOf($this->title, $this->price, $this->area, $this->description);
    }

    public static function hashOf(?string $title, ?int $price, float|string|null $area, ?string $description): string
    {
        $area = $area === null ? null : (float) $area;

        return sha1(implode('|', [$title, $price, $area, $description]));
    }

    /**
     * Kolumny tabeli listings.
     */
    public function toAttributes(): array
    {
        return [
            'portal' => $this->portal,
            'external_id' => $this->externalId,
            'url' => $this->url,
            'title' => mb_substr($this->title, 0, 255),
            'deal_type' => $this->dealType,
            'price' => $this->price,
            'rent_extra' => $this->rentExtra,
            'area' => $this->area,
            'price_per_m2' => $this->pricePerM2(),
            'rooms' => $this->rooms,
            'floor' => $this->floor,
            'city' => $this->city,
            'district' => $this->district,
            'street' => $this->street,
            'is_private_owner' => $this->isPrivateOwner,
            'description' => $this->description,
            'image_url' => $this->imageUrl,
            'raw' => $this->raw,
            'content_hash' => $this->contentHash(),
        ];
    }
}
