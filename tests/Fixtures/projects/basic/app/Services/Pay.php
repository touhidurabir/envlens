<?php

namespace App\Services;

class Pay
{
    public function url(): string
    {
        return env('PAYMENT_URL', 'https://pay.test');
    }
}
