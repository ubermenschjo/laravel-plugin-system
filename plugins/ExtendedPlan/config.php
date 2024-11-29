<?php
return [
    'namespace' => 'ExtendedPlan',
    'psr4' => [
        'ExtendedPlan\\' => __DIR__ . '/app'  // PSR-4 오토로딩 설정
    ],
    'basePath' => __DIR__,
    'migrations' => __DIR__ . '/migrations',
];