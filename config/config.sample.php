<?php
/**
 * Sample Configuration File for cPanel Deployment
 * Copy to config.php or run /install/ to generate automatically.
 */

return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'your_cpanel_db_name',
        'username' => 'your_cpanel_db_user',
        'password' => 'your_cpanel_db_password',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'url'           => 'https://yourdomain.com',
        'secret_key'    => 'CHANGE_THIS_TO_A_64_CHAR_RANDOM_HEX_SECRET',
        'env'           => 'production',
        'debug'         => false,
        'session_name'  => 'SMMPRO_SESSID',
        'admin_session' => 'SMMPRO_ADMIN_SESSID',
    ],
];
