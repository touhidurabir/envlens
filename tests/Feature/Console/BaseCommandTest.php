<?php

declare(strict_types=1);

it('exits 2 when --path does not exist', function () {
    $this->artisan('env:keys', ['--path' => '/definitely/not/here'])
        ->expectsOutputToContain('Path not found: /definitely/not/here')
        ->assertExitCode(2);
});
