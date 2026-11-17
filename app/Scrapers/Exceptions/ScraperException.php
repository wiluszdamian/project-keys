<?php

namespace App\Scrapers\Exceptions;

use RuntimeException;

/**
 * Błąd pobierania lub odczytu strony (5xx po ponowieniach, timeout, zmiana struktury strony).
 */
class ScraperException extends RuntimeException {}
