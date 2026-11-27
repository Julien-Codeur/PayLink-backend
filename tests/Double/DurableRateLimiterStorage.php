<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class DurableRateLimiterStorage extends InMemoryStorage
{
}
