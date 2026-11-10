<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/settings', 'verb' => 'PUT'],
        ['name' => 'mount#index', 'url' => '/mounts', 'verb' => 'GET'],
        ['name' => 'mount#create', 'url' => '/mounts', 'verb' => 'POST'],
        ['name' => 'mount#destroy', 'url' => '/mounts/{id}', 'verb' => 'DELETE'],
        ['name' => 'mount#update', 'url' => '/mounts/{id}', 'verb' => 'PATCH'],
        ['name' => 'archive#inspect', 'url' => '/archives/{sourceId}/inspect', 'verb' => 'POST'],
        ['name' => 'archive#download', 'url' => '/archives/{sourceId}/download', 'verb' => 'POST'],
        ['name' => 'archive#extract', 'url' => '/archives/{sourceId}/extract', 'verb' => 'POST'],
        ['name' => 'job#index', 'url' => '/jobs', 'verb' => 'GET'],
        ['name' => 'job#show', 'url' => '/jobs/{id}', 'verb' => 'GET'],
        ['name' => 'job#cancel', 'url' => '/jobs/{id}', 'verb' => 'DELETE'],
    ],
];
