<?php

$p = 'X';
$x = 'Y';

return [
    'interpolated' => env("{$p}_KEY"),
    'concat' => env('MAIL_'.$x),
    'variable' => env($x),
];
