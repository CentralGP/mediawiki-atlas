<?php
declare(strict_types=1);

return [
    'checkmk_url' => 'https://checkmk.example.com/site/check_mk/api/1.0/domain-types/service/collections/all',
    'checkmk_user' => 'wiki_status',
    'checkmk_secret' => 'YOUR_AUTOMATION_SECRET',

    'cache_file' => '/var/cache/checkmk-status/services.json',
    'cache_ttl' => 30,

    'timeout' => 10,
];