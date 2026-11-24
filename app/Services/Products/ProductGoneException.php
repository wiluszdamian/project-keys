<?php

namespace App\Services\Products;

use RuntimeException;

/**
 * Strona produktu zwraca 404 albo 410.
 */
class ProductGoneException extends RuntimeException {}
