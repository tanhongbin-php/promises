<?php
return [
    'enable' => true,
    'name' => 'asyncHttp',
    'count' => DIRECTORY_SEPARATOR === '/' ? cpu_count() * 2 : 8,
    'port' => 8600,
    'api' => '/promise',
    'secret' => 'abc123'
];