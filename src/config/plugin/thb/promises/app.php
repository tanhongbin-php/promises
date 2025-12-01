<?php
return [
    'enable' => true,
    'name' => 'asyncHttp',
    'count' => cpu_count() * 2,
    'port' => 8600,
    'api' => '/promise',
    'secret' => 'abc123'
];