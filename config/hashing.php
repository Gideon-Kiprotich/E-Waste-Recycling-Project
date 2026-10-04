<?php

return [
    // Argon2id preserves the whole accepted password, including Unicode and bytes beyond bcrypt's limit.
    'driver' => 'argon2id',
    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => 1,
        'time' => env('ARGON_TIME', 4),
        'verify' => true,
    ],
    'rehash_on_login' => true,
];
