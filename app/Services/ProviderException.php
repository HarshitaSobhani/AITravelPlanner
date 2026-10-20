<?php

namespace App\Services;

use RuntimeException;

/** Thrown by external adapters when a provider fails or returns data that fails validation. */
class ProviderException extends RuntimeException {}
