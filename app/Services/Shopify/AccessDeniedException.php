<?php

namespace App\Services\Shopify;

use RuntimeException;

/** A field the app isn't approved to read (e.g. protected customer data). */
class AccessDeniedException extends RuntimeException {}
