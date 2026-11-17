<?php

namespace App\Scrapers;

use App\Scrapers\Contracts\PortalScraper;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class ScraperRegistry
{
    public function __construct(private Container $container) {}

    public function get(string $portal): PortalScraper
    {
        $class = config("scrapers.classes.{$portal}");

        if ($class === null) {
            throw new InvalidArgumentException("Brak scrapera dla portalu {$portal}.");
        }

        return $this->container->make($class);
    }

    /**
     * @return list<string>
     */
    public function portals(): array
    {
        return array_keys(config('scrapers.classes'));
    }
}
