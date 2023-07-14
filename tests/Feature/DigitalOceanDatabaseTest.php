<?php

use Bellows\Plugins\DigitalOceanDatabase;
use Illuminate\Support\Facades\Http;

it('can create a new user and database', function () {
    Http::fake([
        'databases' => Http::response([
            'databases' => [
                [
                    'id'     => 'do-test-id',
                    'name'   => 'bellows_cluster',
                    'engine' => 'mysql',
                    'users'  => [
                        [
                            'name'     => 'bellows_test_user',
                            'password' => 'btu_secretstuff',
                        ],
                    ],
                    'db_names' => [
                        'bellows_tester',
                    ],
                    'private_connection' => [
                        'host' => 'my-private-host.do.com',
                        'port' => 25060,
                    ],
                ],
            ],
        ]),
        'databases/do-test-id/users' => Http::response([
            'user' => [
                'password' => 'secretstuff',
            ],
        ]),
        'databases/do-test-id/dbs' => Http::response([
            'db' => [
                'name' => 'test_app',
            ],
        ], 200),
    ]);

    $result = $this->plugin(DigitalOceanDatabase::class)
        ->expectsQuestion('Database', 'test_app')
        ->expectsQuestion('Database User', 'test_user')
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'DB_CONNECTION'        => 'mysql',
        'DB_DATABASE'          => 'test_app',
        'DB_USERNAME'          => 'test_user',
        'DB_HOST'              => 'my-private-host.do.com',
        'DB_PORT'              => 25060,
        'DB_PASSWORD'          => 'secretstuff',
        'DB_ALLOW_DISABLED_PK' => true,
    ]);

    $this->assertRequestWasSent('POST', 'databases/do-test-id/users', [
        'name' => 'test_user',
    ]);

    $this->assertRequestWasSent('POST', 'databases/do-test-id/dbs', [
        'name' => 'test_app',
    ]);
});

it('can opt for an existing user and database', function () {
    Http::fake([
        'databases' => Http::response([
            'databases' => [
                [
                    'id'     => 'do-test-id',
                    'name'   => 'bellows_cluster',
                    'engine' => 'mysql',
                    'users'  => [
                        [
                            'name'     => 'bellows_test_user',
                            'password' => 'secretstuff',
                        ],
                    ],
                    'db_names' => [
                        'bellows_tester',
                    ],
                    'private_connection' => [
                        'host' => 'my-private-host.do.com',
                        'port' => 25060,
                    ],
                ],
            ],
        ]),
    ]);

    $result = $this->plugin(DigitalOceanDatabase::class)
        ->expectsQuestion('Database', 'bellows_tester')
        ->expectsQuestion('Database User', 'bellows_test_user')
        ->expectsConfirmation('User already exists, do you want to continue?', 'yes')
        ->expectsConfirmation('Database already exists, do you want to continue?', 'yes')
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'DB_CONNECTION'        => 'mysql',
        'DB_DATABASE'          => 'bellows_tester',
        'DB_USERNAME'          => 'bellows_test_user',
        'DB_HOST'              => 'my-private-host.do.com',
        'DB_PORT'              => 25060,
        'DB_PASSWORD'          => 'secretstuff',
        'DB_ALLOW_DISABLED_PK' => true,
    ]);
});
