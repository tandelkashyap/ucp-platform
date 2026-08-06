<?php

namespace App\Services;

use App\Contracts\CommerceConnector;
use App\Models\StoreConnection;
use InvalidArgumentException;

class ConnectorManager
{
    /** @var array<string, class-string<CommerceConnector>> */
    private array $connectors;

    public function __construct()
    {
        $this->connectors = config('ucp.connectors', []);
    }

    /**
     * Resolve the connector for a given store connection. This is the only
     * place in the app that should know which concrete class backs which
     * platform string — everything else depends on CommerceConnector only.
     */
    public function for(StoreConnection $connection): CommerceConnector
    {
        $class = $this->connectors[$connection->platform] ?? null;

        if (! $class) {
            throw new InvalidArgumentException(
                "No connector registered for platform [{$connection->platform}]. ".
                'Add it to config/ucp.php.'
            );
        }

        return new $class($connection);
    }
}
