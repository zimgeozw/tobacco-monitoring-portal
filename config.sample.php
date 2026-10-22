<?php
// Copy to config.php (or run install.php, which creates it). Never commit config.php.
return [
    'app_name' => 'Tobacco Crop Monitoring Portal',
    'base_url' => 'https://timb.zimgeo.co.zw',
    'debug' => false,
    'db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'zimgeoco_timb', 'user' => 'zimgeoco_timb', 'pass' => ''],
    'mail' => [
        'host' => 'mail.zimgeo.co.zw',  // empty = use PHP mail()
        'port' => 465,
        'secure' => 'ssl',              // 'ssl' (465) or 'tls' (587)
        'user' => 'noreply@zimgeo.co.zw',
        'pass' => '',
        'verify_ssl' => false,
        'from_email' => 'noreply@zimgeo.co.zw',
        'from_name' => 'TIMB Tobacco Monitoring Portal',
        'reply_to' => 'info@zimgeo.co.zw',
    ],
];
