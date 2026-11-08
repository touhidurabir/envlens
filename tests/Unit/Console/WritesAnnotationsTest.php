<?php

declare(strict_types=1);

use Touhidurabir\EnvLens\Console\Concerns\WritesAnnotations;

it('escapes values as GitHub requires', function () {
    $writer = new class
    {
        use WritesAnnotations;

        public function format(string $level, string $title, string $message, ?string $file, ?int $line): string
        {
            return $this->annotation($level, $title, $message, $file, $line);
        }
    };

    expect($writer->format('error', 'a:b,c', "50% done\r\nnext: yes, <b>", 'app\\x,y:z.php', 3))
        ->toBe('::error file=app/x%2Cy%3Az.php,line=3,title=a%3Ab%2Cc::50%25 done%0D%0Anext: yes, <b>');
});
