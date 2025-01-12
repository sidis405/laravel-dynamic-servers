<?php

namespace Spatie\DynamicServers\Actions;

use Spatie\DynamicServers\Enums\ServerStatus;
use Spatie\DynamicServers\Exceptions\CannotStopServer;
use Spatie\DynamicServers\Jobs\UpdateServerMetaJob;
use Spatie\DynamicServers\Models\Server;
use Spatie\DynamicServers\Support\Config;

class UpdateServerMetaAction
{
    public function execute(Server $server): void
    {
        if ($server->status !== ServerStatus::Running) {
            throw CannotStopServer::wrongStatus($server);
        }

        /** @var class-string<UpdateServerMetaJob> $updateServerMetaJobClass */
        $updateServerMetaJobClass = Config::dynamicServerJobClass('update_server_meta');

        dispatch(new $updateServerMetaJobClass($server));
    }
}
