<?php

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'spicycrust_game_api',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    // Cambiar esto en producción.
    'admin_key' => getenv('SPICYCRUST_ADMIN_KEY') ?: 'change-me-before-production',

    // Para pruebas locales se permite cualquier origen.
    // En producción conviene usar el dominio real del admin/juegos.
    'cors_origin' => getenv('CORS_ORIGIN') ?: '*',
];
