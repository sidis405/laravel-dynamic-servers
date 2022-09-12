<?php

namespace Spatie\DynamicServers\Jobs;

use Exception;
use Spatie\DynamicServers\Enums\ServerStatus;
use Spatie\DynamicServers\Events\ServerRunningEvent;

class UpdateServerMetaJob extends DynamicServerJob
{
    public function handle()
    {
        try {
            if ($this->server->isProbablyHanging()) {
                $this->server->markAsHanging();

                return;
            }

            $this->server->serverProvider()->updateServerMeta();

        } catch (Exception $exception) {
            $this->server->markAsErrored($exception);

            report($exception);
        }
    }
}
