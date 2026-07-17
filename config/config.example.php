<?php
return [
    'app_name' => 'Alerta Wi-Fi',
    'app_url' => 'https://seu-dominio.com.br',
    'timezone' => 'America/Sao_Paulo',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'alerta_wifi',
        'user' => 'usuario',
        'pass' => 'senha',
        'charset' => 'utf8mb4',
    ],
    'vapid' => [
        'subject' => 'mailto:contato@seu-dominio.com.br',
        'public_key' => '',
        'private_key_pem' => '',
    ],
    'cron_token' => '',
    'session_secret' => '',
];
