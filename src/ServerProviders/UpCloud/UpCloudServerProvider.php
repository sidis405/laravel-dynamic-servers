<?php

namespace Spatie\DynamicServers\ServerProviders\UpCloud;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spatie\DynamicServers\ServerProviders\ServerProvider;
use Spatie\DynamicServers\ServerProviders\UpCloud\Exceptions\CannotGetUpCloudServerDetails;
use Spatie\DynamicServers\ServerProviders\UpCloud\Exceptions\CannotRebootServer;

class UpCloudServerProvider extends ServerProvider
{
    public function createServer(): void
    {
        $response = $this->request()->post('/server', $this->server->configuration);

        if (! $response->successful()) {
            throw new Exception($response->json('error.error_message'));
        }

        $upCloudServer = UpCloudServer::fromApiPayload($response->json('server'));

        $this->server->addMeta('server_properties', $upCloudServer->toArray());
    }

    public function updateServerMeta(): void
    {
        $upCloudServer = $this->getServer();

        $this->server->addMeta('server_properties', $upCloudServer->toArray());
    }

    public function hasStarted(): bool
    {
        $upCloudServer = $this->getServer();

        return $upCloudServer->status === UpCloudServerStatus::Started;
    }

    public function stopServer(): void
    {
        $serverUuid = $this->server->meta('server_properties.uuid');

        $response = $this->request()->post("/server/{$serverUuid}/stop", [
            'stop_server' => [
                'stop_type' => 'soft',
                'timeout' => 60,
            ],
        ]);

        if (! $response->successful()) {
            throw new Exception($response->json('error.error_message'));
        }
    }

    public function hasStopped(): bool
    {
        $upCloudServer = $this->getServer();

        return $upCloudServer->status === UpCloudServerStatus::Stopped;
    }

    public function deleteServer(): void
    {
        $serverUuid = $this->server->meta('server_properties.uuid');

        $response = $this->request()
            ->delete("/server/{$serverUuid}?storages=1&backups=delete");

        if (! $response->successful()) {
            throw new Exception($response->json('error.error_message', 'Could not delete server'));
        }
    }

    public function hasBeenDeleted(): bool
    {
        $serverUuid = $this->server->meta('server_properties.uuid');

        $response = $this->request()->get("/server/{$serverUuid}");

        return $response->failed();
    }

    public function getServer(): UpCloudServer
    {
        $serverUuid = $this->server->meta('server_properties.uuid');

        $response = $this->request()->get("/server/{$serverUuid}");

        if (! $response->successful()) {
            throw CannotGetUpCloudServerDetails::make($this->server, $response);
        }

        return UpCloudServer::fromApiPayload($response->json('server'));
    }

    public function rebootServer(): void
    {
        $serverUuid = $this->server->meta('server_properties.uuid');

        $response = $this->request()->post("/server/{$serverUuid}/restart", [
            'stop_type' => 'soft',
            'timeout' => 60,
            'timeout_action' => 'destroy', // Hard stop and start again after timeout
        ]);

        if (! $response->successful()) {
            throw CannotRebootServer::make($this->server, $response);
        }
    }

    public function currentServerCount(): int
    {
        $response = $this->request()->get('server');

        if (! $response->successful()) {
            throw CannotGetUpCloudServerDetails::make($this->server, $response);
        }

        return count($response->json('servers.server'));
    }

    protected function request(): PendingRequest
    {
        return Http::withBasicAuth(
            $this->server->option('username'),
            $this->server->option('password')
        )->baseUrl('https://api.upcloud.com/1.3');
    }
}
