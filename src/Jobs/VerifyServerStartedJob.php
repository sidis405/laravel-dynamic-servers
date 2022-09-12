<?php

namespace Spatie\DynamicServers\Jobs;

use Exception;
use Spatie\DynamicServers\Enums\ServerStatus;
use Spatie\DynamicServers\Events\ServerRunningEvent;
use Spatie\DynamicServers\Support\Config;

class VerifyServerStartedJob extends DynamicServerJob
{
    public function handle()
    {
        try {
            if ($this->server->isProbablyHanging()) {
                $this->server->markAsHanging();

                return;
            }

            if ($this->server->serverProvider()->hasStarted()) {
                $previousStatus = $this->server->status;

                $this->server->markAs(ServerStatus::Running);

                event(new ServerRunningEvent($this->server, $previousStatus));

                /** @var class-string<UpdateServerMetaJob> $updateServerMetaJob */
                $updateServerMetaJob = Config::dynamicServerJobClass('update_server_meta');

                dispatch(new $updateServerMetaJob($this->server));

                if ($this->server->rebootRequested()) {
                    $this->server->reboot();

                    return;
                }


                return;
            }

            $this->release(20);
        } catch (Exception $exception) {
            $this->server->markAsErrored($exception);

            report($exception);
        }
    }

    public function retryUntil()
    {
        return now()->addMinutes(10);
    }
}
