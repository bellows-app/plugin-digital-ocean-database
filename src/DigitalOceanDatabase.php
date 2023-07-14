<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Database;
use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\HttpClient;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\AddApiCredentialsPrompt;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Deployment;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Bellows\PluginSdk\PluginResults\InteractsWithDatabases;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DigitalOceanDatabase extends Plugin implements Deployable, Installable, Database
{
    use CanBeDeployed, CanBeInstalled, InteractsWithDatabases;

    protected string $password;

    protected string $databaseName;

    protected string $databaseUser;

    public function __construct(
        protected HttpClient $http,
    ) {
    }

    public function getName(): string
    {
        return 'DigitalOcean Database';
    }

    public function install(): ?InstallationResult
    {
        return InstallationResult::create()->updateConfig(
            'database.allow_disabled_pk',
            "env('DATABASE_ALLOW_DISABLED_PK', false)",
        );
    }

    public function deploy(): ?DeploymentResult
    {
        $this->http->createJsonClient(
            'https://api.digitalocean.com/v2/',
            fn (PendingRequest $request, array $credentials) => $request->withToken($credentials['token']),
            new AddApiCredentialsPrompt(
                url: 'https://cloud.digitalocean.com/account/api/tokens',
                credentials: ['token'],
                displayName: 'DigitalOcean',
            ),
            fn (PendingRequest $request) => $request->get('databases', ['per_page' => 1]),
        );

        $this->databaseName = Console::ask('Database', Project::isolatedUser());
        $this->databaseUser = Console::ask('Database User', $this->databaseName);

        $response = $this->http->client()->get('databases');

        $dbs = collect(
            $response->json()['databases']
        );

        $db = $dbs->count() === 1
            ? $dbs->first()
            : Console::choiceFromCollection(
                'Choose a database',
                $dbs,
                'name'
            );

        $dbType = collect(['pg' => 'pgsql'])->get($db['engine'], $db['engine']);

        if (!$this->setUser($db) || !$this->setDatabase($db)) {
            // Something went awry, try again
            return $this->deploy();
        }

        return DeploymentResult::create()->environmentVariables([
            'DB_CONNECTION'        => $dbType,
            'DB_DATABASE'          => $this->databaseName,
            'DB_USERNAME'          => $this->databaseUser,
            'DB_HOST'              => $db['private_connection']['host'],
            'DB_PORT'              => $db['private_connection']['port'],
            'DB_PASSWORD'          => $this->password,
            'DB_ALLOW_DISABLED_PK' => true,
        ]);
    }

    public function shouldDeploy(): bool
    {
        return !Str::contains(Deployment::site()->env()->get('DB_HOST'), 'db.ondigitalocean.com');
    }

    public function confirmDeploy(): bool
    {
        $current = Deployment::site()->env()->get('DB_HOST');

        if (!$current) {
            return true;
        }

        return Console::confirm(
            "Your current database connection is pointed to {$current}, continue?",
            true
        );
    }

    protected function getDefaultNewAccountName(string $token): ?string
    {
        $result = Http::baseUrl('https://api.digitalocean.com/v2/')
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->get('account')
            ->json();

        $teamName = Arr::get($result, 'account.team.name');

        return $teamName === 'My Team' ? null : Str::slug($teamName);
    }

    protected function setUser($db)
    {
        $existingUser = collect($db['users'])->firstWhere('name', $this->databaseUser);

        if ($existingUser) {
            if (!Console::confirm('User already exists, do you want to continue?', true)) {
                return false;
            }

            $this->password = $existingUser['password'];

            return true;
        }

        Console::miniTask('Creating user', $this->databaseUser);

        $newDbUser = $this->http->client()->post(
            "databases/{$db['id']}/users",
            [
                'name' => $this->databaseUser,
            ],
        )->json()['user'];

        $this->password = $newDbUser['password'];

        return true;
    }

    protected function setDatabase($db)
    {
        $existingDatabase = collect($db['db_names'])->contains($this->databaseName);

        if ($existingDatabase) {
            return Console::confirm('Database already exists, do you want to continue?', true);
        }

        Console::miniTask('Creating database', $this->databaseName);

        $this->http->client()->post(
            "databases/{$db['id']}/dbs",
            [
                'name' => $this->databaseName,
            ],
        );

        return true;
    }
}
