<?php

use Illuminate\Support\Env;

Route::get('/', fn () => Env::getOrFail('ROUTE_SECRET'));
