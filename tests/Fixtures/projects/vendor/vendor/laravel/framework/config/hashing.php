<?php

return [
    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
    ],
    'argon' => [
        'time' => env('ARGON_TIME', 4),
    ],
];
