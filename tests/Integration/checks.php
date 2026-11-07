<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use App\Enums\ServerState;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\ActivityLog;
use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Dotenv\Dotenv;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PelicanProvisioner\Domain\ProvisioningException;
use PelicanProvisioner\Domain\QuotaResource;
use PelicanProvisioner\Domain\QuotaViolationException;
use PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas\Pages\ManageResourceQuotas;
use PelicanProvisioner\Filament\Admin\Resources\ResourceQuotas\ResourceQuotaResource;
use PelicanProvisioner\Filament\Admin\Resources\Users\RelationManagers\ResourceQuotaRelationManager;
use PelicanProvisioner\Filament\App\Pages\CreateServerPage;
use PelicanProvisioner\Filament\Server\Pages\ServerResourcePage;
use PelicanProvisioner\Models\ResourceQuota;
use PelicanProvisioner\PelicanProvisionerPlugin;
use PelicanProvisioner\Services\UsageQuery;
use PelicanProvisioner\Services\UserLock;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

$eggs = Egg::query()->pluck('id', 'name');
$allowedEgg = (int) $eggs['Audit Allowed'];
$optionalEgg = (int) $eggs['Audit Optional'];
$bigNumberEgg = (int) $eggs['Audit Big Number'];
$forbiddenEgg = (int) $eggs['Audit Forbidden'];
$usableEggs = implode(',', [$allowedEgg, $optionalEgg, $bigNumberEgg]);

$alice = User::query()->where('username', 'alice')->firstOrFail();
$bob = User::query()->where('username', 'bob')->firstOrFail();
$admin = User::query()->where('username', 'admin')->firstOrFail();

section('Creating: settings');

reset_state();
check('no egg is available until one is allowed', outcome(fn () => create_server('a', $allowedEgg)), 'egg_not_allowed');

reset_state(['allowed_eggs' => (string) $allowedEgg]);
$server = Server::query()->findOrFail(create_server('b', $allowedEgg)->id);
check('an empty port list still gives the server an allocation', $server->allocation_id !== null, true);
check('the allocation is on the chosen node', $server->allocation?->node_id, $server->node_id);
check('the allocation is assigned to the server', $server->allocation?->server_id, $server->id);
check('only a public node with the tag is used', $server->node->name, 'Public Tagged');
check('feature limits default to none', [$server->database_limit, $server->allocation_limit, $server->backup_limit], [0, 0, 0]);
check('locked and hidden variables keep their defaults', stored_environment($server), [
    'BUILD_NUM' => '1',
    'HIDDEN_TOKEN' => 'hidden-default-secret',
    'LOCKED_PATH' => '/defaults',
    'SERVER_JAR' => 'server.jar',
]);
check('an egg outside the list is refused', outcome(fn () => create_server('c', $forbiddenEgg)), 'egg_not_allowed');

reset_state(['allow_all_eggs' => 'true']);
check('allowing every egg is an explicit switch', outcome(fn () => create_server('d', $forbiddenEgg)), 'allowed');

reset_state(['allowed_eggs' => (string) $allowedEgg, 'deployment_ports' => '25570']);
check('the port list limits the allocation', Server::query()->findOrFail(create_server('e', $allowedEgg)->id)->allocation?->port, 25570);

reset_state(['allowed_eggs' => (string) $allowedEgg, 'deployment_ports' => '30000-30010']);
check('no free port in the list refuses the server', outcome(fn () => create_server('f', $allowedEgg)), 'no_viable_allocation_found');
check('and leaves nothing behind', Server::query()->count(), 0);

foreach (['abc', null, ',,'] as $corrupt) {
    reset_state(['allowed_eggs' => $corrupt]);
    check('a corrupt egg list turns creation off: '.var_export($corrupt, true), outcome(fn () => create_server('g', $allowedEgg)), 'invalid_settings');
}

section('Creating: values the form submits');

reset_state(['allowed_eggs' => $usableEggs]);
$server = create_server('h', $allowedEgg, rows: [['env_variable' => 'BUILD_NUM', 'variable_value' => 5.0]]);
check('a float from a number field is stored as written', stored_environment($server)['BUILD_NUM'], '5');

reset_state(['allowed_eggs' => $usableEggs]);
$server = create_server('i', $optionalEgg, rows: [['env_variable' => 'OPTIONAL_FLAG', 'variable_value' => null]]);
check('a blank optional field is stored empty', stored_environment($server)['OPTIONAL_FLAG'], '');
check('a blank required field is refused', outcome(fn () => create_server('j', $allowedEgg, rows: [['env_variable' => 'SERVER_JAR', 'variable_value' => null]])), 'invalid_variables');

section('Creating: quota and rate limit');

reset_state(['allowed_eggs' => $usableEggs]);
check('above the quota', outcome(fn () => create_server('k', $allowedEgg, cpu: 201)), 'quota_exceeded');
check('unlimited cpu under a finite quota', outcome(fn () => create_server('l', $allowedEgg, cpu: 0)), 'quota_exceeded');
check('unlimited memory under a finite quota', outcome(fn () => create_server('m', $allowedEgg, memory: 0)), 'quota_exceeded');
check('unlimited disk under a finite quota', outcome(fn () => create_server('n', $allowedEgg, disk: 0)), 'quota_exceeded');
clear_rate_limits();
foreach ([1, 2, 3] as $number) {
    create_server('o'.$number, $allowedEgg);
}
check('over the server limit', outcome(fn () => create_server('p', $allowedEgg)), 'quota_exceeded');

reset_state(['allowed_eggs' => $usableEggs]);
$last = '';
for ($attempt = 0; $attempt < 11; $attempt++) {
    $last = outcome(fn () => create_server('q', $allowedEgg, cpu: 999));
}
check('the eleventh attempt is rate limited', $last, 'rate_limited');

section('Who can change a server');

reset_state(['allowed_eggs' => $usableEggs, 'can_users_delete_servers' => 'true']);
$server = mark_installed(create_server('r', $allowedEgg));
Subuser::query()->create(['user_id' => $bob->id, 'server_id' => $server->id, 'permissions' => ['control.console']]);
Filament::setCurrentPanel(Filament::getPanel('server'));
Filament::setTenant($server);

foreach (['bob' => 'a subuser', 'admin' => 'a root admin who is not the owner', 'qadmin' => 'an unrelated user'] as $username => $who) {
    as_user($username);
    check("$who cannot open the resize page", ServerResourcePage::canAccess(), false);
    check("$who cannot resize", outcome(fn () => resize($server, 20, 64, 64)), 'http 403');
    check("$who cannot delete", outcome(fn () => provisioner()->delete(Server::query()->findOrFail($server->id))), 'http 403');
}

as_user('alice');
check('the owner can open the resize page', ServerResourcePage::canAccess(), true);
check('the owner can resize', outcome(fn () => resize($server, 20, 64, 64)), 'allowed');
check('a user with a quota can open the create page', CreateServerPage::canAccess(), true);

as_user('bob');
check('a user without a quota cannot open the create page', CreateServerPage::canAccess(), false);
check('a user without a quota cannot create', outcome(fn () => create_server('s', $allowedEgg)), 'forbidden');

ResourceQuota::query()->where('user_id', $alice->id)->delete();
as_user('alice');
check('a revoked quota closes the resize page', ServerResourcePage::canAccess(), false);
check('a revoked quota stops resizing', outcome(fn () => resize($server, 30, 64, 64)), 'http 403');
check('a revoked quota stops deleting', outcome(fn () => provisioner()->delete(Server::query()->findOrFail($server->id))), 'http 403');
check('a revoked quota stops creating', outcome(fn () => create_server('t', $allowedEgg)), 'forbidden');
ResourceQuota::query()->create(['user_id' => $alice->id, 'cpu' => 200, 'memory' => 2048, 'disk' => 10240, 'server_limit' => 3]);
clear_rate_limits();

as_user('alice');
config()->set('pelican-provisioner.can_users_update_servers', 'false');
check('with resizing off and deleting on, the page stays open for deleting', ServerResourcePage::canAccess(), true);
check('switching resizing off stops the service', outcome(fn () => resize($server, 30, 64, 64)), 'http 403');
config()->set('pelican-provisioner.can_users_delete_servers', 'false');
check('with both off, the page is closed', ServerResourcePage::canAccess(), false);
config()->set('pelican-provisioner.can_users_update_servers', 'true');
check('deleting is off by default', outcome(fn () => provisioner()->delete(Server::query()->findOrFail($server->id))), 'http 403');

Server::query()->whereKey($server->id)->update(['owner_id' => $bob->id]);
as_user('alice');
check('a former owner cannot resize', outcome(fn () => resize($server, 30, 64, 64)), 'http 403');
Server::query()->whereKey($server->id)->update(['owner_id' => $alice->id]);
Subuser::query()->where('server_id', $server->id)->delete();

section('Resizing');

reset_state(['allowed_eggs' => $usableEggs]);
ResourceQuota::query()->where('user_id', $alice->id)->update(['memory' => 100000]);
as_user('alice');
check('creating above node capacity is refused', outcome(fn () => create_server('u', $allowedEgg, memory: 50000)), 'no_viable_node_found');
$server = mark_installed(create_server('v', $allowedEgg, memory: 100));
check('growing past the node is refused', outcome(fn () => resize($server, 10, 50000, 64)), 'node_full');
check('and changes nothing', Server::query()->findOrFail($server->id)->memory, 100);
check('growing within the node works', outcome(fn () => resize($server, 10, 200, 64)), 'allowed');
check('and is saved', Server::query()->findOrFail($server->id)->memory, 200);
Server::query()->whereKey($server->id)->update(['memory' => 5000]);
check('shrinking on an overfull node stays possible', outcome(fn () => resize($server, 10, 4000, 64)), 'allowed');

reset_state(['allowed_eggs' => $usableEggs]);
ResourceQuota::query()->where('user_id', $alice->id)->update(['cpu' => 400]);
as_user('alice');
$first = mark_installed(create_server('w1', $allowedEgg, cpu: 90));
$second = mark_installed(create_server('w2', $allowedEgg, cpu: 90));
mark_installed(create_server('w3', $allowedEgg, cpu: 90));
ResourceQuota::query()->where('user_id', $alice->id)->update(['cpu' => 100]);
as_user('alice');
check('an owner over quota can shrink', outcome(fn () => resize($first, 10, 64, 64)), 'allowed');
check('an owner over quota cannot grow', outcome(fn () => resize($second, 91, 64, 64)), 'quota_exceeded');

section('A suspended server');

reset_state(['allowed_eggs' => $usableEggs, 'can_users_delete_servers' => 'true']);
$server = mark_installed(create_server('x', $allowedEgg));
Server::query()->whereKey($server->id)->update(['status' => ServerState::Suspended->value]);
Filament::setTenant(Server::query()->findOrFail($server->id));
check('the page is closed', ServerResourcePage::canAccess(), false);
check('the service refuses a resize', outcome(fn () => resize($server, 20, 64, 64)), 'http 403');
check('the service refuses a delete', outcome(fn () => provisioner()->delete(Server::query()->findOrFail($server->id))), 'http 403');
check('the server is still there', Server::query()->whereKey($server->id)->exists(), true);

section('When the node answers with an error');

reset_state(['allowed_eggs' => $usableEggs, 'can_users_delete_servers' => 'true']);
node_answers(500);
check('a refused create reports a failure', outcome(fn () => create_server('y', $allowedEgg)), 'unknown_server_creation_error');
check('and leaves no row behind', Server::query()->count(), 0);
check('and does not spend quota', app(UsageQuery::class)->fresh($alice->id)->servers, 0);
check('and releases the allocation', Allocation::query()->whereNotNull('server_id')->count(), 0);
node_answers(204);
$server = mark_installed(create_server('z', $allowedEgg));
node_answers(500);
check('a refused delete reports a failure', outcome(fn () => provisioner()->delete(Server::query()->findOrFail($server->id))), 'unknown_server_creation_error');
check('and keeps the server', Server::query()->whereKey($server->id)->exists(), true);
check('a refused sync still saves the new size', str_starts_with(outcome(fn () => resize($server, 20, 64, 64)), 'error RequestException'), true);
check('and that size is stored', Server::query()->findOrFail($server->id)->cpu, 20);
node_answers(404);
check('a 404 on delete means the node has nothing left', outcome(fn () => provisioner()->delete(Server::query()->findOrFail($server->id))), 'allowed');
check('and removes the panel row', Server::query()->whereKey($server->id)->exists(), false);

section('Admin permissions');

reset_state();
$aliceQuota = ResourceQuota::query()->where('user_id', $alice->id)->firstOrFail();
$rootQuota = ResourceQuota::query()->create(['user_id' => $admin->id, 'cpu' => 1, 'memory' => 1, 'disk' => 1, 'server_limit' => 1]);
$abilities = fn (User $user): array => [
    'viewAny' => $user->can('viewAny', ResourceQuota::class),
    'create' => $user->can('create', ResourceQuota::class),
    'update' => $user->can('update', $aliceQuota),
    'delete' => $user->can('delete', $aliceQuota),
];
$qadmin = User::query()->where('username', 'qadmin')->firstOrFail();
check('a view-only role can only list', $abilities($qadmin), ['viewAny' => true, 'create' => false, 'update' => false, 'delete' => false]);
check('no role can do nothing', $abilities($bob), ['viewAny' => false, 'create' => false, 'update' => false, 'delete' => false]);
check('a root admin can do everything', $abilities($admin), ['viewAny' => true, 'create' => true, 'update' => true, 'delete' => true]);

$editor = Role::query()->create(['name' => 'QuotaEditor', 'guard_name' => 'web']);
$editor->givePermissionTo(
    Permission::findOrCreate('create provisionerQuota', 'web'),
    Permission::findOrCreate('update provisionerQuota', 'web'),
    Permission::findOrCreate('delete provisionerQuota', 'web'),
);
$qadmin->syncRoles([$editor]);
app(PermissionRegistrar::class)->forgetCachedPermissions();
$qadmin = User::query()->findOrFail($qadmin->id);
check('an editor can change a normal quota', $qadmin->can('update', $aliceQuota), true);
check('an editor cannot change a root admin quota', $qadmin->can('update', $rootQuota), false);
check('an editor cannot delete a root admin quota', $qadmin->can('delete', $rootQuota), false);
as_user('qadmin');
check('an editor cannot pick a root admin', ResourceQuotaResource::selectableUsers(User::query())->pluck('username')->contains('admin'), false);
check('an editor can pick other users', ResourceQuotaResource::selectableUsers(User::query())->pluck('username')->contains('alice'), true);
check('an editor who cannot list users sees no emails', ResourceQuotaResource::canSeeEmails(), false);
as_user('admin');
check('a root admin can pick anyone', ResourceQuotaResource::selectableUsers(User::query())->pluck('username')->contains('admin'), true);
check('a root admin sees emails', ResourceQuotaResource::canSeeEmails(), true);
$rootQuota->delete();
Filament::setCurrentPanel(Filament::getPanel('admin'));
$quotaSection = fn (User $actor, User $owner): ResourceQuotaRelationManager => Livewire::actingAs($actor)
    ->test(ResourceQuotaRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class])
    ->instance();
as_user('qadmin');
check('an editor can add a quota from a user page', $quotaSection($qadmin, $bob)->canCreateQuota(), true);
check('an editor cannot add one to a root admin', $quotaSection($qadmin, $admin)->canCreateQuota(), false);
check('a user page offers no second quota', $quotaSection($qadmin, $alice)->canCreateQuota(), false);
$qadmin->syncRoles([Role::query()->where('name', 'QuotaViewer')->firstOrFail()]);
$editor->delete();
app(PermissionRegistrar::class)->forgetCachedPermissions();
$qadmin = User::query()->findOrFail($qadmin->id);
as_user('qadmin');
check('a view-only role cannot add one from a user page', $quotaSection($qadmin, $bob)->canCreateQuota(), false);

section('Saving settings');

reset_state();
$environmentFile = app()->environmentFilePath();
$plugin = new PelicanProvisionerPlugin();
$form = [
    'database_limit' => '0',
    'allocation_limit' => '0',
    'backup_limit' => '0',
    'can_users_update_servers' => true,
    'can_users_delete_servers' => false,
    'allow_all_eggs' => false,
    'deployment_tags' => ['user_creatable_servers'],
    'deployment_ports' => [],
    'allowed_eggs' => [$allowedEgg],
];
$before = file_get_contents($environmentFile);
check('a tag with $ does not throw', outcome(fn () => $plugin->saveSettings(['deployment_tags' => ['${APP_KEY}']] + $form)), 'allowed');
check('and writes nothing', file_get_contents($environmentFile), $before);
check('valid settings save', outcome(fn () => $plugin->saveSettings($form)), 'allowed');
$written = Dotenv::parse((string) file_get_contents($environmentFile));
check('and reach the environment file', [$written['PROVISIONER_ALLOWED_EGGS'] ?? null, $written['PROVISIONER_ALLOW_ALL_EGGS'] ?? null], [(string) $allowedEgg, 'false']);
$original = app()->environmentFile();
app()->loadEnvironmentFrom('.env.does-not-exist');
check('a failed write is reported instead of thrown', outcome(fn () => $plugin->saveSettings($form)), 'allowed');
app()->loadEnvironmentFrom($original);

section('Create form, used normally');

$openCreateForm = function (int $eggId, string $name) use ($alice) {
    as_user('alice');
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return Livewire::actingAs($alice)->test(CreateServerPage::class)
        ->set('data.name', $name)
        ->set('data.egg_id', $eggId)
        ->set('data.cpu', 10)
        ->set('data.memory', 64)
        ->set('data.disk', 64);
};
$rowKey = function ($page, string $environmentName): int|string {
    foreach ((array) $page->get('data.variables') as $key => $row) {
        if (($row['env_variable'] ?? null) === $environmentName) {
            return $key;
        }
    }

    return 'missing';
};

reset_state(['allowed_eggs' => $usableEggs]);
$page = $openCreateForm($allowedEgg, 'form-defaults');
$rows = array_values((array) $page->get('data.variables'));
$rowFields = array_keys($rows[0] ?? []);
sort($rowFields);
check('a row holds only its name and value', $rowFields, ['env_variable', 'variable_value']);
check('the hidden variable is not sent', array_column($rows, 'env_variable'), ['SERVER_JAR', 'BUILD_NUM', 'LOCKED_PATH']);
$page->call('save');
$server = Server::query()->where('name', 'form-defaults')->first();
check('an egg with a number field can be created with its defaults', $server?->name, 'form-defaults');
check('with the number stored as written', $server ? stored_environment($server)['BUILD_NUM'] : null, '1');
check('and the locked field on its default', $server ? stored_environment($server)['LOCKED_PATH'] : null, '/defaults');

reset_state(['allowed_eggs' => $usableEggs]);
$page = $openCreateForm($allowedEgg, 'form-invalid');
$key = $rowKey($page, 'BUILD_NUM');
$page->set("data.variables.$key.variable_value", 'abc')->call('save');
check('the rules from the database refuse a bad value', array_key_exists("data.variables.$key.variable_value", $page->errors()->toArray()), true);
check('and nothing is created', Server::query()->where('name', 'form-invalid')->exists(), false);

reset_state(['allowed_eggs' => $usableEggs]);
$page = $openCreateForm($optionalEgg, 'form-blank');
$page->call('save');
$server = Server::query()->where('name', 'form-blank')->first();
check('an optional field can stay blank', $server ? stored_environment($server)['OPTIONAL_FLAG'] : null, '');

reset_state(['allowed_eggs' => $usableEggs]);
$page = $openCreateForm($bigNumberEgg, 'form-big');
$key = $rowKey($page, 'STEAM_ID');
$page->set("data.variables.$key.variable_value", '76561198000000001')->call('save');
$server = Server::query()->where('name', 'form-big')->first();
check('a long number keeps every digit', $server ? stored_environment($server)['STEAM_ID'] : null, '76561198000000001');

reset_state(['allowed_eggs' => $usableEggs]);
$page = $openCreateForm($forbiddenEgg, 'form-forbidden');
check('an egg outside the list shows no variables', array_values((array) $page->get('data.variables')), []);

section('Resize form, used normally');

reset_state(['allowed_eggs' => $usableEggs]);
$server = mark_installed(create_server('rz', $allowedEgg));
$openResizeForm = function () use ($server, $alice) {
    as_user('alice');
    Filament::setCurrentPanel(Filament::getPanel('server'));
    Filament::setTenant(Server::query()->findOrFail($server->id));

    return Livewire::actingAs($alice)->test(ServerResourcePage::class);
};
$page = $openResizeForm();
$fields = array_keys((array) $page->get('data'));
sort($fields);
check('the browser receives only the three fields', $fields, ['cpu', 'disk', 'memory']);
$page->set('data.memory', 128)->call('save');
check('a size inside the quota saves', [$page->errors()->toArray(), Server::query()->findOrFail($server->id)->memory], [[], 128]);
$page = $openResizeForm()->set('data.memory', 999999)->call('save');
check('a size over the quota is refused with the right limit', $page->errors()->toArray()['data.memory'][0] ?? null, 'The memory may not be greater than 2048.');
ResourceQuota::query()->where('user_id', $alice->id)->update(['memory' => 64]);
$page = $openResizeForm()->set('data.memory', 100)->call('save');
check('an owner over quota can shrink through the form', [$page->errors()->toArray(), Server::query()->findOrFail($server->id)->memory], [[], 100]);
config()->set('pelican-provisioner.can_users_update_servers', 'false');
config()->set('pelican-provisioner.can_users_delete_servers', 'true');
$page = $openResizeForm();
check('with resizing off, the fields are locked', outcome(fn () => $page->assertFormFieldDisabled('cpu')->assertFormFieldDisabled('memory')->assertFormFieldDisabled('disk')), 'allowed');
check('and there is no save button', outcome(fn () => $page->assertActionHidden('save')), 'allowed');
check('but the delete button is there', outcome(fn () => $page->assertActionVisible('delete_server')), 'allowed');
$page->callAction('delete_server');
check('and deletes the server', Server::query()->whereKey($server->id)->exists(), false);

section('Pages render for their intended users');

reset_state(['allowed_eggs' => $usableEggs]);
Filament::setCurrentPanel(Filament::getPanel('app'));
check('server list with the quota widget and create button', outcome(fn () => Livewire::actingAs($alice)->test(ListServers::class)), 'allowed');
as_user('admin');
Filament::setCurrentPanel(Filament::getPanel('admin'));
check('admin quota list', outcome(fn () => Livewire::actingAs($admin)->test(ManageResourceQuotas::class)->call('loadTable')), 'allowed');
check('quota section on the admin user page', outcome(fn () => Livewire::actingAs($admin)->test(ResourceQuotaRelationManager::class, ['ownerRecord' => $alice, 'pageClass' => EditUser::class])), 'allowed');
$bobSection = fn () => Livewire::actingAs($admin)->test(ResourceQuotaRelationManager::class, ['ownerRecord' => $bob, 'pageClass' => EditUser::class]);
check('a user without a quota gets a create button', outcome(fn () => $bobSection()->assertActionVisible(TestAction::make('create')->table())), 'allowed');
check('creating from the user page works', outcome(fn () => $bobSection()
    ->callAction(TestAction::make('create')->table(), data: ['cpu' => 50, 'memory' => 512, 'disk' => 1024, 'server_limit' => 2])
    ->assertHasNoErrors()), 'allowed');
check('and the quota belongs to that user', ResourceQuota::query()->where('user_id', $bob->id)->value('memory'), 512);
check('then the button is gone', outcome(fn () => $bobSection()->assertActionHidden(TestAction::make('create')->table())), 'allowed');
ResourceQuota::query()->where('user_id', $bob->id)->delete();

section('Audit trail');

reset_state(['allowed_eggs' => $usableEggs, 'can_users_delete_servers' => 'true']);
$server = mark_installed(create_server('audit', $allowedEgg));
resize($server, 20, 128, 256);
$latest = fn (string $event) => ActivityLog::query()->where('event', $event)->latest('id')->first();
$resized = $latest('server:provisioner.resources');
check('a resize records the old and new sizes', $resized?->properties?->only(['old_cpu', 'old_memory', 'old_disk', 'new_cpu', 'new_memory', 'new_disk'])->all(), [
    'old_cpu' => 10, 'old_memory' => 64, 'old_disk' => 64, 'new_cpu' => 20, 'new_memory' => 128, 'new_disk' => 256,
]);
check('and who did it', $resized?->actor_id, $alice->id);
check('a create records the egg', $latest('server:provisioner.create')?->properties?->get('egg_id'), $allowedEgg);
provisioner()->delete(Server::query()->findOrFail($server->id));
check('a delete records the server', $latest('server:provisioner.delete')?->properties?->get('name'), 'audit');
reset_state(['allowed_eggs' => $usableEggs]);
$server = mark_installed(create_server('audit2', $allowedEgg));
as_user('bob');
outcome(fn () => resize($server, 20, 64, 64));
$logs = glob('/var/www/html/storage/logs/*.log') ?: [];
usort($logs, fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
$lastLog = (string) file_get_contents($logs[0] ?? '/dev/null');
check('a refused request reaches the panel log', str_contains($lastLog, 'pelican-provisioner.denied') && str_contains($lastLog, '"reason":"not_owner"'), true);

section('Cache lock on databases other than MySQL');

$lock = new UserLock(DB::connection('sqlite'), app('cache'));
$passedThrough = false;
try {
    $lock->run(987654, fn () => throw new QuotaViolationException(QuotaResource::Cpu));
} catch (QuotaViolationException) {
    $passedThrough = true;
}
check('a quota rejection keeps its meaning', $passedThrough, true);
$held = Cache::lock('pp_quota_987655', 30);
$held->get();
$contended = '';
try {
    $lock->run(987655, fn () => null);
} catch (ProvisioningException $exception) {
    $contended = $exception->failure->value;
}
$held->release();
check('a lock held by another request reports busy', $contended, 'busy');
check('a free lock runs the callback', $lock->run(987656, fn (): string => 'ran'), 'ran');

section('Migration guide');

reset_state();
preg_match('/```sql\s+(INSERT.*?)```/s', (string) file_get_contents(dirname(__DIR__, 2).'/MIGRATE.md'), $matches);
$copyQuotas = $matches[1] ?? '';
Schema::create('user_resource_limits', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('user_id')->unique();
    $table->unsignedInteger('cpu');
    $table->unsignedInteger('memory');
    $table->unsignedInteger('disk');
    $table->unsignedInteger('server_limit')->nullable();
    $table->timestamps();
});
DB::table('user_resource_limits')->insert([
    ['user_id' => $alice->id, 'cpu' => 1, 'memory' => 1, 'disk' => 1, 'server_limit' => null],
    ['user_id' => $bob->id, 'cpu' => 50, 'memory' => 512, 'disk' => 1024, 'server_limit' => 2],
]);
DB::statement($copyQuotas);
check('the statement copies a quota on MariaDB', ResourceQuota::query()->where('user_id', $bob->id)->value('memory'), 512);
check('and leaves an existing quota alone', ResourceQuota::query()->where('user_id', $alice->id)->value('cpu'), 200);
ResourceQuota::query()->where('user_id', $bob->id)->delete();
Schema::drop('user_resource_limits');

$sqlite = new PDO('sqlite::memory:');
$sqlite->exec('CREATE TABLE user_resource_limits (user_id INT, cpu INT, memory INT, disk INT, server_limit INT, created_at TEXT, updated_at TEXT)');
$sqlite->exec('CREATE TABLE provisioner_quotas (user_id INT UNIQUE, cpu INT, memory INT, disk INT, server_limit INT, created_at TEXT, updated_at TEXT)');
$sqlite->exec('INSERT INTO user_resource_limits VALUES (7, 1, 1, 1, NULL, NULL, NULL)');
$sqlite->exec($copyQuotas);
check('the statement works on SQLite', (int) $sqlite->query('SELECT COUNT(*) FROM provisioner_quotas')->fetchColumn(), 1);

reset_state();

finish();
