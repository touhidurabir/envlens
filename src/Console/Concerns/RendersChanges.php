<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Console\Concerns;

use Touhidurabir\EnvLens\Mutation\Change;
use Touhidurabir\EnvLens\Mutation\ChangeReason;

/** The "what gets written and why" table and counts shared by env:sync and env:generate. */
trait RendersChanges
{
    /**
     * One row per key: the value written and why. Secrets show only (blank).
     *
     * @param  list<Change>  $changes
     */
    protected function changesTable(array $changes): void
    {
        $this->table(['Key', 'Will be written as', 'Why'], array_map(fn (Change $change): array => [
            $change->key,
            ($change->value ?? '') === '' ? '<fg=gray>(blank)</>' : $this->shortenValue((string) $change->value),
            match ($change->reason) {
                ChangeReason::Secret => '<fg=yellow>'.$change->reason->label().'</>',
                ChangeReason::Copied => '<fg=cyan>'.$change->reason->label().'</>',
                null => '',
                default => $change->reason->label(),
            },
        ], $changes));
    }

    /**
     * The "value copied / config default / blank" and secrets summary lines.
     *
     * @param  list<Change>  $changes
     */
    protected function valueCounts(array $changes): void
    {
        $count = static fn (ChangeReason $reason): int => count(array_filter($changes, static fn (Change $c): bool => $c->reason === $reason));
        $blank = count(array_filter($changes, static fn (Change $c): bool => ($c->value ?? '') === ''));

        $this->components->twoColumnDetail('Value copied / config default / blank', $count(ChangeReason::Copied).' / '.$count(ChangeReason::ConfigDefault).' / '.$blank);
        $this->components->twoColumnDetail('Secrets (always blank)', (string) $count(ChangeReason::Secret));
    }

    /** Values longer than 40 characters are cut with an ellipsis. */
    protected function shortenValue(string $value): string
    {
        return mb_strlen($value) > 40 ? mb_substr($value, 0, 39).'…' : $value;
    }
}
