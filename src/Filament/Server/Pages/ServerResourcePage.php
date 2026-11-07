<?php

declare(strict_types=1);

namespace PelicanProvisioner\Filament\Server\Pages;

use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\QuotaPolicy;
use PelicanProvisioner\Domain\ResourceAmounts;
use PelicanProvisioner\Filament\App\Widgets\QuotaOverview;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\Services\QuotaAccess;
use PelicanProvisioner\Services\ServerProvisioner;
use PelicanProvisioner\Services\SettingsRepository;
use PelicanProvisioner\Services\UsageQuery;

/**
 * Only the owner can open this page, and only while resizing or deleting is turned on. Deleting lives here too, so the page stays open with the fields locked when only deleting is on. A Wings HTTP error after the row is saved is shown as a warning, because a missing server on the node used to become an unhandled 500. The form is filled with the three fields it edits, because the parent would send the whole server row to the browser.
 */
class ServerResourcePage extends ServerFormPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-adjustments';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return trans('pelican-provisioner::strings.resource_page');
    }

    public function getTitle(): string
    {
        return trans('pelican-provisioner::strings.resource_page');
    }

    public static function canAccess(): bool
    {
        $actor = user();
        $server = Filament::getTenant();

        if (!$actor instanceof User || !$server instanceof Server || (int) $actor->id !== (int) $server->owner_id) {
            return false;
        }

        $settings = app(SettingsRepository::class)->current();

        if (!$settings->usersCanUpdate && !$settings->usersCanDelete) {
            return false;
        }

        if (!app(QuotaAccess::class)->canOpen((int) $server->owner_id)) {
            return false;
        }

        return parent::canAccess();
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                $this->resourceField('cpu', '%'),
                $this->resourceField('memory', $this->memorySuffix()),
                $this->resourceField('disk', $this->memorySuffix()),
            ]);
    }

    protected function fillForm(): void
    {
        $server = $this->getRecord();

        $this->form->fill([
            'cpu' => $server->cpu,
            'memory' => $server->memory,
            'disk' => $server->disk,
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QuotaOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->visible(fn (): bool => self::canResize())
                ->label(trans('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->action('save')
                ->keyBindings(['mod+s'])
                ->icon('tabler-device-floppy'),
            Action::make('delete_server')
                ->visible(fn (): bool => app(SettingsRepository::class)->current()->usersCanDelete)
                ->authorize(function (): bool {
                    $actor = user();
                    $server = $this->getRecord();

                    return $actor instanceof User && (int) $actor->id === (int) $server->owner_id;
                })
                ->color('danger')
                ->icon('tabler-trash')
                ->label(trans('pelican-provisioner::strings.modals.delete_server'))
                ->requiresConfirmation()
                ->modalHeading(trans('pelican-provisioner::strings.modals.delete_server_confirm'))
                ->modalDescription(trans('pelican-provisioner::strings.modals.delete_server_warning'))
                ->modalSubmitActionLabel(trans('pelican-provisioner::strings.modals.delete_server'))
                ->action(function (ServerProvisioner $provisioner): mixed {
                    $server = $this->getRecord();
                    $serverList = ListServers::getUrl(panel: 'app');

                    try {
                        $provisioner->delete($server);
                    } catch (ProvisioningException) {
                        Notification::make()
                            ->title(trans('pelican-provisioner::strings.notifications.server_delete_error'))
                            ->body(trans('pelican-provisioner::strings.notifications.delete_failed'))
                            ->danger()
                            ->send();

                        return null;
                    }

                    Notification::make()
                        ->title(trans('pelican-provisioner::strings.notifications.server_deleted'))
                        ->body(trans('pelican-provisioner::strings.notifications.server_deleted_success'))
                        ->success()
                        ->send();

                    // This page's tenant is the server. Rendering it after the row is gone
                    // resolves that tenant again and replaces the redirect with a 404.
                    $this->skipRender();
                    $this->redirect($serverList, navigate: false);

                    return redirect()->to($serverList);
                }),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        try {
            app(ServerProvisioner::class)->updateResources(
                $this->getRecord(),
                $data['cpu'] ?? null,
                $data['memory'] ?? null,
                $data['disk'] ?? null,
            );

            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_resources_updated'))
                ->body(trans('pelican-provisioner::strings.notifications.might_need_restart'))
                ->success()
                ->send();
        } catch (ProvisioningException $exception) {
            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_resources_not_updated'))
                ->body(trans('pelican-provisioner::strings.notifications.'.$exception->failure->value))
                ->danger()
                ->send();

            return;
        } catch (ConnectionException|RequestException) {
            Notification::make()
                ->title(trans('pelican-provisioner::strings.notifications.server_resources_updated'))
                ->body(trans('pelican-provisioner::strings.notifications.manual_restart_needed'))
                ->warning()
                ->persistent()
                ->send();
        }

        $url = self::getUrl();
        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }

    private function resourceField(string $name, string $suffix): TextInput
    {
        return TextInput::make($name)
            ->label(trans('pelican-provisioner::strings.'.$name))
            ->required()
            ->integer()
            ->disabled(fn (): bool => !self::canResize())
            ->live(onBlur: true)
            ->minValue(fn (): int => $this->bounds($name)['min'])
            ->maxValue(fn (): int => $this->bounds($name)['max'])
            ->suffix($suffix)
            ->hint(function ($state) use ($name, $suffix): string {
                $bounds = $this->bounds($name);

                if ($bounds['min'] === 0 && $bounds['max'] === ResourceAmounts::ABSOLUTE_MAX) {
                    return trans('pelican-provisioner::strings.unlimited');
                }

                $typed = is_numeric($state) ? (int) $state : 0;

                return trans('pelican-provisioner::strings.widget.remaining', [
                    'amount' => max(0, $bounds['max'] - $typed).($suffix === '%' ? '%' : ' '.$suffix),
                ]);
            });
    }

    /** @return array{min: int, max: int} */
    private function bounds(string $resource): array
    {
        $server = $this->getRecord();
        $quota = app(QuotaAccess::class)->quotaFor((int) $server->owner_id);

        if (!$quota instanceof ResourceQuota) {
            return ['min' => 1, 'max' => 0];
        }

        $usage = app(UsageQuery::class)->forOwner((int) $server->owner_id, true)->without(
            new ResourceAmounts((int) $server->cpu, (int) $server->memory, (int) $server->disk),
        );
        $limit = match ($resource) {
            'cpu' => (int) $quota->cpu,
            'memory' => (int) $quota->memory,
            default => (int) $quota->disk,
        };
        $used = match ($resource) {
            'cpu' => $usage->cpu,
            'memory' => $usage->memory,
            default => $usage->disk,
        };
        $unlimited = match ($resource) {
            'cpu' => $usage->unlimitedCpuServers,
            'memory' => $usage->unlimitedMemoryServers,
            default => $usage->unlimitedDiskServers,
        };
        $maximum = app(QuotaPolicy::class)->maximum($limit, $used, $unlimited);

        if ($maximum === null) {
            return ['min' => 0, 'max' => ResourceAmounts::ABSOLUTE_MAX];
        }

        $current = match ($resource) {
            'cpu' => (int) $server->cpu,
            'memory' => (int) $server->memory,
            default => (int) $server->disk,
        };

        // Keeping or reducing the current size stays possible when the quota is already spent.
        return ['min' => 1, 'max' => max($maximum, $current)];
    }

    private static function canResize(): bool
    {
        return app(SettingsRepository::class)->current()->usersCanUpdate;
    }

    private function memorySuffix(): string
    {
        return config('panel.use_binary_prefix') ? 'MiB' : 'MB';
    }
}
