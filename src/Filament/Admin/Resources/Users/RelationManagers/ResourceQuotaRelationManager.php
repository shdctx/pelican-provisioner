<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Admin\Resources\Users\RelationManagers;

use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas\ResourceQuotaResource;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\UsageQuery;

/**
 * A user has at most one quota, so the create button disappears once there is one. Creating follows the quota list: the Quota create permission, and a normal admin cannot give a root admin a quota.
 */
class ResourceQuotaRelationManager extends RelationManager
{
    protected static string $relationship = 'provisionerQuota';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (static::shouldSkipAuthorization()) {
            return true;
        }

        return ResourceQuotaResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        $suffix = config('panel.use_binary_prefix') ? ' MiB' : ' MB';

        return $table
            ->heading(trans_choice('pelican-provisioner::strings.user_resource_limits', 2))
            ->columns([
                TextColumn::make('cpu')
                    ->label(trans('pelican-provisioner::strings.cpu'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.'%' : trans('pelican-provisioner::strings.unlimited')),
                TextColumn::make('memory')
                    ->label(trans('pelican-provisioner::strings.memory'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.$suffix : trans('pelican-provisioner::strings.unlimited')),
                TextColumn::make('disk')
                    ->label(trans('pelican-provisioner::strings.disk'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.$suffix : trans('pelican-provisioner::strings.unlimited')),
                TextColumn::make('server_limit')
                    ->label(trans('pelican-provisioner::strings.server_limit'))
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null || $state === 0
                        ? trans('pelican-provisioner::strings.no_limit')
                        : (string) $state),
                TextColumn::make('servers_used')
                    ->label(trans('pelican-provisioner::strings.servers'))
                    ->state(function (ResourceQuota $record): int {
                        return app(UsageQuery::class)->forOwner((int) $record->user_id)->servers;
                    }),
            ])
            ->headerActions([
                CreateAction::make()
                    ->createAnother(false)
                    ->authorize(fn (): bool => $this->canCreateQuota()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->hidden(fn (ResourceQuota $record): bool => static::getEditAuthorizationResponse($record)->allowed()),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->paginated(false)
            ->emptyStateIcon('tabler-cube-plus')
            ->emptyStateDescription(trans('pelican-provisioner::strings.admin.empty_user'));
    }

    public function canCreateQuota(): bool
    {
        $owner = $this->getOwnerRecord();
        $actor = user();

        return !$this->isReadOnly()
            && $owner instanceof User
            && $actor instanceof User
            && $actor->can('create', ResourceQuota::class)
            && $actor->canTarget($owner)
            && !ResourceQuota::query()->where('user_id', $owner->id)->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('cpu')
                ->label(trans('pelican-provisioner::strings.cpu'))
                ->helperText(trans('pelican-provisioner::strings.hint_unlimited'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
                ->suffix('%'),
            TextInput::make('memory')
                ->label(trans('pelican-provisioner::strings.memory'))
                ->helperText(trans('pelican-provisioner::strings.hint_unlimited'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
                ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
            TextInput::make('disk')
                ->label(trans('pelican-provisioner::strings.disk'))
                ->helperText(trans('pelican-provisioner::strings.hint_unlimited'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX)
                ->suffix(config('panel.use_binary_prefix') ? 'MiB' : 'MB'),
            TextInput::make('server_limit')
                ->label(trans('pelican-provisioner::strings.server_limit'))
                ->helperText(trans('pelican-provisioner::strings.hint_server_limit'))
                ->required()
                ->integer()
                ->minValue(0)
                ->maxValue(ResourceAmounts::ABSOLUTE_MAX),
        ]);
    }
}
