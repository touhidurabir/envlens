<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Mutation;

use Touhidurabir\EnvLens\Env\EnvFile;

final readonly class GeneratedEnv
{
    /**
     * @param  int  $keys  Number of distinct keys written.
     * @param  int  $droppedLines  Unparseable lines left out (they may contain secrets).
     * @param  list<Change>  $changes  One per key written, with the value and why it got it.
     * @param  list<int>  $droppedAt  Line numbers of the dropped lines.
     * @param  int  $blankedComments  Commented-out assignments whose value was dropped.
     */
    public function __construct(
        public EnvFile $file,
        public int $keys,
        public int $droppedLines,
        public array $changes = [],
        public array $droppedAt = [],
        public int $blankedComments = 0,
    ) {}
}
