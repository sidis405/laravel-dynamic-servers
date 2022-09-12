<?php

namespace Spatie\DynamicServers\Jobs;

use Exception;

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
