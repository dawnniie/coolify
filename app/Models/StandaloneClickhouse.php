<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandaloneClickhouse extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];
    protected $casts = [
        'clickhouse_password' => 'encrypted',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            LocalPersistentVolume::create([
                'name' => 'clickhouse-data-' . $database->uuid,
                'mount_path' => '/var/lib/clickhouse/',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true
            ]);
            LocalPersistentVolume::create([
                'name' => 'clickhouse-logs-' . $database->uuid,
                'mount_path' => '/var/log/clickhouse-server/',
                'host_path' => null,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
                'is_readonly' => true
            ]);
//             LocalFileVolume::create(
//                 [
//                     'mount_path' => '/etc/clickhouse-server/config.d/docker_related_config.xml',
//                     'resource_id' => $database->id,
//                     'resource_type' => $database->getMorphClass(),
//                     'chown' => '101:101',
//                     'chmod' => '644',
//                     'fs_path' => database_configuration_dir() . '/' . $database->uuid . '/config.d/docker_related_config.xml',
//                     'content' => '<clickhouse>
//      <!-- Listen wildcard address to allow accepting connections from other containers and host network. -->
//     <listen_host>::</listen_host>
//     <listen_host>0.0.0.0</listen_host>
//     <listen_try>1</listen_try>

//     <!--
//     <logger>
//         <console>1</console>
//     </logger>
//     -->
// </clickhouse>',
//                     'is_directory' => 'false',
//                 ]
//             );
            // LocalPersistentVolume::create([
            //     'name' => 'clickhouse-config-' . $database->uuid,
            //     'mount_path' => '/etc/clickhouse-server/config.d',
            //     'host_path' => database_configuration_dir() . '/' . $database->uuid . '/config.d',
            //     'resource_id' => $database->id,
            //     'resource_type' => $database->getMorphClass(),
            //     'is_readonly' => true
            // ]);
            // LocalPersistentVolume::create([
            //     'name' => 'clickhouse-config-users-' . $database->uuid,
            //     'mount_path' => '/etc/clickhouse-server/users.d',
            //     'host_path' => database_configuration_dir() . '/' . $database->uuid . '/users.d',
            //     'resource_id' => $database->id,
            //     'resource_type' => $database->getMorphClass(),
            //     'is_readonly' => true
            // ]);
        });
        static::deleting(function ($database) {
            $storages = $database->persistentStorages()->get();
            $server = data_get($database, 'destination.server');
            if ($server) {
                foreach ($storages as $storage) {
                    instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
                }
            }
            $database->scheduledBackups()->delete();
            $database->persistentStorages()->delete();
            $database->environment_variables()->delete();
            $database->tags()->detach();
        });
    }
    public function workdir()
    {
        return database_configuration_dir() . '/' . $this->uuid;
    }
    public function realStatus()
    {
        return $this->getRawOriginal('status');
    }
    public function status(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } else if (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }
                return "$status:$health";
            },
            get: function ($value) {
                if (str($value)->contains('(')) {
                    $status = str($value)->before('(')->trim()->value();
                    $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                } else if (str($value)->contains(':')) {
                    $status = str($value)->before(':')->trim()->value();
                    $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                } else {
                    $status = $value;
                    $health = 'unhealthy';
                }
                return "$status:$health";
            },
        );
    }
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
    public function project()
    {
        return data_get($this, 'environment.project');
    }
    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.database.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
                'database_uuid' => data_get($this, 'uuid')
            ]);
        }
        return null;
    }
    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === "" ? null : $value,
        );
    }

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }
    public function team()
    {
        return data_get($this, 'environment.project.team');
    }
    public function type(): string
    {
        return 'standalone-clickhouse';
    }
    public function get_db_url(bool $useInternal = false): string
    {
        if ($this->is_public && !$useInternal) {
            return "clickhouse://{$this->clickhouse_user}:{$this->clickhouse_password}@{$this->destination->server->getIp}:{$this->public_port}/{$this->clickhouse_db}";
        } else {
            return "clickhouse://{$this->clickhouse_user}:{$this->clickhouse_password}@{$this->uuid}:9000/{$this->clickhouse_db}";
        }
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function runtime_environment_variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }
}
