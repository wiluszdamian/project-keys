<?php

namespace App\Scrapers\Exceptions;

use RuntimeException;

/**
 * Portal odpowiedział 403 lub 429 albo jest wstrzymany po wcześniejszej blokadzie.
 */
class PortalBlockedException extends RuntimeException {}
