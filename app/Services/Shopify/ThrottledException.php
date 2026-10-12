<?php

namespace App\Services\Shopify;

use RuntimeException;

/** Shopify's GraphQL cost limit was reached; the caller should wait and try again. */
class ThrottledException extends RuntimeException {}
