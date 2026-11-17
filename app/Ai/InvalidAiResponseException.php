<?php

namespace App\Ai;

use RuntimeException;

/**
 * Model odpowiedział, ale odpowiedź nie przeszła walidacji (także po ponownej próbie).
 */
class InvalidAiResponseException extends RuntimeException {}
