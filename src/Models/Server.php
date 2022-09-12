<?php

namespace Spatie\DynamicServers\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\DynamicServers\Actions\GenerateServerNameAction;
use Spatie\DynamicServers\Actions\RebootServerAction;
use Spatie\DynamicServers\Actions\StartServerAction;
use Spatie\DynamicServers\Actions\StopServerAction;
use Spatie\DynamicServers\Enums\ServerStatus;
use Spatie\DynamicServers\Events\ServerErroredEvent;
use Spatie\DynamicServers\Events\ServerHangingEvent;
use Spatie\DynamicServers\Exceptions\InvalidProvider;
use Spatie\DynamicServers\Facades\DynamicServers;
use Spatie\DynamicServers\ServerProviders\ServerProvider;
use Spatie\DynamicServers\Support\Config;
use Spatie\DynamicServers\Support\ServerTypes\ServerType;

class Server extends Model
{
    use HasFactory;
    use MassPrunable;

    public $guarded = [];

    public $table = 'dynamic_servers';

    public $casts = [
        'configuration' => 'array',
        'status_updated_at' => 'datetime',
        'status' => ServerStatus::class,
        'meta' => AsArrayObject::class,
    ];

    public static function booted()
    {
        self::creating(function (self $server) {
            if (null === $server->status) {
                $server->status = ServerStatus::New;
                $server->status_updated_at = now();
            }

            if (empty($server->meta)) {
                $server->meta = new ArrayObject();
            }
        });

        self::created(function (self $server) {
            if ($server->name === 'pending-server-name') {
                $server->name = $server->generateName();
            }

            if (empty($configuration)) {
                $server->configuration = $server->serverType()->getConfiguration($server);
            }

            $server->saveQuietly();
        });
    }

    public function serverType(): ServerType
    {
        return DynamicServers::getServerType($this->type);
    }

    public static function prepareNew(string $type = 'default', string $name = null): Server
    {
        /** @var ServerType $serverType */
        $serverType = DynamicServers::getServerType($type);

        return Server::create([
            'name' => $name ?? 'pending-server-name',
            'type' => $type,
            'provider' => $serverType->providerName,
        ]);
    }

    public function start(): self
    {
        /** @var StartServerAction $action */
        $action = Config::action('start_server');

        $action->execute($this);

        return $this;
    }

    public function stop(): self
    {
        /** @var StopServerAction $action */
        $action = Config::action('stop_server');

        $action->execute($this);

        return $this;
    }

    public function reboot(): self
    {
        /** @var RebootServerAction $action */
        $action = Config::action('reboot_server');

        $action->execute($this);

        return $this;
    }

    public function updateMeta(): self
    {
        /** @var UpdateServerMetaAction $action */
        $action = Config::action('update_server_meta');

        $action->execute($this);

        return $this;
    }

    public function markAs(ServerStatus $status): self
    {
        $this->update([
            'status' => $status,
            'status_updated_at' => now(),
        ]);

        return $this;
    }

    public function serverProvider(): ServerProvider
    {
        /** @var class-string<ServerProvider> $providerClassName */
        $providerClassName = config("dynamic-servers.providers.{$this->provider}.class") ?? '';

        if (! is_a($providerClassName, ServerProvider::class, true)) {
            throw InvalidProvider::make($this);
        }

        /** @var ServerProvider $serverProvider */
        $serverProvider = app($providerClassName);

        $serverProvider->setServer($this);

        return $serverProvider;
    }

    public function markAsErrored(Exception $exception): self
    {
        $this->update([
            'status' => ServerStatus::Errored,
            'status_updated_at' => now(),
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_trace' => $exception->getTraceAsString(),
        ]);

        event(new ServerErroredEvent($this));

        return $this;
    }

    public function markAsHanging(): self
    {
        $previousStatus = $this->status;

        $this->markAs(ServerStatus::Hanging);

        event(new ServerHangingEvent($this, $previousStatus));

        return $this;
    }

    public function meta(string $key, mixed $default = null)
    {
        return Arr::get($this->meta, $key) ?? $default;
    }

    public function addMeta(string $name, string|array|int|bool $value): self
    {
        $this->meta[$name] = $value;

        $this->save();

        return $this;
    }

    public function option(string $key): mixed
    {
        return Config::providerOption($this->provider, $key);
    }

    public function scopeStatus(Builder $query, ServerStatus ...$statuses): void
    {
        $query->whereIn('status', $statuses);
    }

    public function scopeProvisioned(Builder $query): void
    {
        $this->scopeStatus($query, ...ServerStatus::provisionedStates());
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return  $query->where('type', $type);
    }

    protected function generateName(): string
    {
        /** @var GenerateServerNameAction $generateServerNameAction */
        $generateServerNameAction = Config::action('generate_server_name');

        return $generateServerNameAction->execute($this);
    }

    public function prunable(): Builder
    {
        $days = config('dynamic-servers.prune_stopped_servers_from_local_db_after_days');

        return static::query()
            ->status(ServerStatus::Stopped, ServerStatus::Errored)
            ->where('status_updated_at', '<=', now()->addDays($days));
    }

    public static function countPerStatus(): array
    {
        $allStatuses = collect(ServerStatus::cases())->map->value;

        $actualStatuses = DB::table((new self())->getTable())
            ->select('status', DB::raw('count(*) as count'))
            ->whereIn('status', $allStatuses->toArray())
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (object $result) => [$result->status => $result->count])
            ->toArray();

        return $allStatuses
            ->mapWithKeys(function (string $status) use ($actualStatuses) {
                return [$status => $actualStatuses[$status] ?? 0];
            })
            ->toArray();
    }

    public function isProbablyHanging(): bool
    {
        if (in_array($this->status, [
            ServerStatus::Running,
            ServerStatus::Stopped,
            ServerStatus::Errored,
        ])) {
            return false;
        }

        if (! in_array($this->status, [
            ServerStatus::New,
            ServerStatus::Starting,
            ServerStatus::Stopping,
        ])) {
            if (is_null($this->status_updated_at)) {
                return false;
            }
        }

        return $this->status_updated_at->diffInMinutes() >= config('dynamic-servers.mark_server_as_hanging_after_minutes');
    }

    public function rebootRequested(): bool
    {
        return ! is_null($this->reboot_requested_at);
    }
}
