<?php

use Illuminate\Support\Env;

return [

    'nested_default' => env('A', env('B', 'b')),

    'envget' => Env::get('ENVGET_KEY'),

];
