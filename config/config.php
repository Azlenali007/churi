<?php
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'otp_test_db',
        'username' => 'otp_test_user',
        'password' => 'OtpTestPass123!',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'url'           => 'http://127.0.0.1:18992',
        'secret_key'    => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'env'           => 'testing',
        'debug'         => false,
        'session_name'  => 'SMMPRO_SESSID',
        'admin_session' => 'SMMPRO_ADMIN_SESSID',
    ],
];
