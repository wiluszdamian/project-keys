<?php

namespace App\Services\Products;

/**
 * To, co udało się odczytać ze strony produktu.
 */
final class ProductPage
{
    public function __construct(
        public ?string $name = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?bool $available = null,
        public ?float $regularPrice = null,
        public ?float $lowest30dPrice = null,
        public ?string $image = null,
        public ?string $brand = null,
        public ?string $gtin = null,
        public ?string $mpn = null,
        public ?string $method = null,
    ) {}

    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    /**
     * Uzupełnia puste pola wartościami z drugiego odczytu.
     */
    public function fillFrom(self $other): self
    {
        foreach (get_object_vars($other) as $field => $value) {
            if ($this->{$field} === null && $value !== null) {
                $this->{$field} = $value;
            }
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
