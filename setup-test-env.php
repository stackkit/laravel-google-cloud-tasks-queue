<?php

if (!file_exists('./tests/laravel')) {
    setup();
}

function setup() {
    exec('composer create-project laravel/laravel tests/laravel');


    $composerJson = file_get_contents('./tests/laravel/composer.json');

    $toArray = json_decode($composerJson, true);

    if (!isset($toArray['repositories'])) {
        $toArray['repositories'] = [
            [
                'type' => 'path',
                'url' => '../../',
            ],
        ];
    }

    $encoded = json_encode($toArray, JSON_PRETTY_PRINT);

    file_put_contents('./tests/laravel/composer.json', $encoded);

    $envs = [
        'APP_ENV=local' => 'APP_ENV=testing',
        'DB_DATABASE=laravel' => 'DB_DATABASE=cloudtasks',
        "DB_PASSWORD=\n" => "DB_PASSWORD=my-secret-pw\n",
        'QUEUE_CONNECTION=sync' => 'QUEUE_CONNECTION=cloudtasks',
    ];

    file_put_contents(
        './tests/laravel/.env',
        str_replace(
            array_keys($envs),
            array_values($envs),
            file_get_contents('./tests/laravel/.env')
        )
    );

    // Prepare the config/queue.php file.
    function env() {
        //
    }
    $queue = include('./tests/laravel/config/queue.php');

    if (!isset($queue['connections']['cloudtasks'])) {
        $queue['default'] = 'cloudtasks';
        $queue['connections']['cloudtasks'] = [
            'driver' => 'cloudtasks',
            'project' => 'my-test-project',
            'queue' => 'barbequeue',
            'location' => 'europe-west6',
            'handler' => 'http://docker.for.mac.localhost:8080/handle-task',
            'service_account_email' => 'info@stackkit.io',
        ];
        file_put_contents('./tests/laravel/config/queue.php', '<?php return ' . var_export($queue, true) . ';');
    }

    exec('
        cd ./tests/laravel &&
        mkdir -p tests/Support &&
        cp ../../tests/support/SimpleJob.php tests/support/SimpleJob.php &&
        composer require stackkit/laravel-google-cloud-tasks-queue &&
        php artisan migrate
    ');
}

echo "Started dev server on port 8080!\n";
exec('cd ./tests/laravel && php artisan serve --port=8080 --no-ansi');
