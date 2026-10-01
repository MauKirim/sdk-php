<?php

declare(strict_types=1);

namespace MauKirim\Service;

use MauKirim\Client;

/**
 * Shared plumbing for the resource services.
 */
abstract class AbstractService
{
    public function __construct(protected readonly Client $client)
    {
    }
}
