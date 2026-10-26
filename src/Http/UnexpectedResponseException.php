<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Http;

/**
 * A successful response whose body couldn't be read as expected, such as invalid JSON.
 */
final class UnexpectedResponseException extends \RuntimeException {}
