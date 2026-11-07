<?php

declare(strict_types=1);

namespace PelicanProvisioner\Providers;

use App\Enums\HeaderActionPosition;
use App\Enums\HeaderWidgetPosition;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\ServerDeletionService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use PelicanProvisioner\Filament\Admin\Resources\Users\RelationManagers\ResourceQuotaRelationManager;
use PelicanProvisioner\Filament\App\Widgets\QuotaOverview;
use PelicanProvisioner\Filament\Components\Actions\CreateServerAction;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Policies\ResourceQuotaPolicy;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\ServerProvisioner;
use PelicanProvisioner\Services\StrictDaemonServerRepository;
use PelicanProvisioner\Services\UsageQuery;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Usage and access are scoped so Octane does not keep them for the next request. The policy is registered here because the panel does not discover plugin policies on its own. Only this plugin's provisioner gets the strict node client, so deleting a server from the admin area behaves as it always did.
 *
 * The deletion service is built by the container with only its node client replaced, so a panel release that adds or reorders its other dependencies keeps working. If the service no longer takes a node client, resolving the provisioner fails instead of quietly deleting through the lenient one.
 */
class PelicanProvisionerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(UsageQuery::class);
        $this->app->scoped(QuotaAccess::class);
        $this->app->when(ServerProvisioner::class)
            ->needs(ServerDeletionService::class)
            ->give(fn (Container $app): ServerDeletionService => $app->make(ServerDeletionService::class, [
                self::daemonParameter() => $app->make(StrictDaemonServerRepository::class),
            ]));

        Gate::policy(ResourceQuota::class, ResourceQuotaPolicy::class);

        UserResource::registerCustomRelations(ResourceQuotaRelationManager::class);
        ListServers::registerCustomHeaderWidgets(HeaderWidgetPosition::Before, QuotaOverview::class);
        ListServers::registerCustomHeaderActions(HeaderActionPosition::Before, CreateServerAction::make());
        Role::registerCustomDefaultPermissions('provisionerQuota');
        Role::registerCustomModelIcon('provisionerQuota', 'tabler-cube-plus');
    }

    private static function daemonParameter(): string
    {
        foreach ((new ReflectionClass(ServerDeletionService::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === DaemonServerRepository::class) {
                return $parameter->getName();
            }
        }

        throw new RuntimeException('ServerDeletionService no longer takes a DaemonServerRepository, so the strict node client cannot be passed to it.');
    }

    public function boot(): void
    {
        User::resolveRelationUsing(
            'provisionerQuota',
            fn (User $user) => $user->hasOne(ResourceQuota::class, 'user_id'),
        );
    }
}
