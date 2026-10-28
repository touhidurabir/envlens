<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Mutation;

use Touhidurabir\EnvLens\Env\EnvEntryType;
use Touhidurabir\EnvLens\Env\EnvFile;
use Touhidurabir\EnvLens\Env\EnvWriter;
use Touhidurabir\EnvLens\Scanning\UsageIndex;
use Touhidurabir\EnvLens\Security\SensitivityDetector;
use Touhidurabir\EnvLens\Support\Glob;

final class EnvGenerator
{
    /**
     * @param  list<string>  $skipDefaults  keys never given a config-default placeholder (generate.skip_defaults)
     */
    public function __construct(
        private readonly SensitivityDetector $sensitivity,
        private readonly array $skipDefaults = [],
    ) {}

    /**
     * Builds an example file from $source. Comments (except the values of commented-out assignments) and blank lines are kept,
     * unparseable lines are dropped (and counted) because they may contain secrets, and every
     * key is rewritten with a safe value. A duplicate key keeps only its last definition.
     */
    public function generate(EnvFile $source, UsageIndex $index, bool $withValues, bool $useDefaults): GeneratedEnv
    {
        $eol = $source->eol();
        $last = [];

        foreach ($source->lines() as $i => $line) {
            if ($line->type === EnvEntryType::Key && $line->key !== null) {
                $last[$line->key] = $i;
            }
        }

        $out = '';
        $droppedAt = [];
        $blanked = 0;
        $changes = [];

        foreach ($source->lines() as $i => $line) {
            if ($line->type === EnvEntryType::Invalid) {
                $droppedAt[] = $line->line;

                continue;
            }

            if ($line->type === EnvEntryType::Comment) {
                $comment = $this->blankCommentedAssignment($line->raw);
                $blanked += $comment !== $line->raw ? 1 : 0;
                $out .= $comment;

                continue;
            }

            if ($line->type !== EnvEntryType::Key || $line->key === null) {
                $out .= $line->raw;

                continue;
            }

            if ($last[$line->key] !== $i) {
                continue;
            }

            $default = $index->literalDefault($line->key);
            [$value, $reason] = match (true) {
                $this->sensitivity->isSensitive($line->key, $line->value) => ['', ChangeReason::Secret],
                $withValues => [$line->value ?? '', ChangeReason::Copied],
                ! $useDefaults => ['', ChangeReason::DefaultsOff],
                Glob::matchesAny($this->skipDefaults, $line->key) => ['', ChangeReason::SkipDefault],
                $default !== null && $default !== '' => [$default, ChangeReason::ConfigDefault],
                default => ['', ChangeReason::NoDefault],
            };

            $changes[] = new Change(ChangeType::Add, $line->key, $value, $reason === ChangeReason::Copied, $reason);
            $out .= ($line->exported ? 'export ' : '').$line->key.'='.EnvWriter::formatValue($value).$eol;
        }

        return new GeneratedEnv(EnvFile::fromString($out), count($last), count($droppedAt), $changes, $droppedAt, $blanked);
    }

    /**
     * A commented-out assignment (`#KEY=value`) is usually an alternate real value, so its value
     * is dropped: `#KEY=` is kept. Other comments are returned unchanged.
     */
    private function blankCommentedAssignment(string $raw): string
    {
        if (preg_match('/^(\s*#\s*(?:export\s+)?)([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $raw, $m) !== 1) {
            return $raw;
        }

        $eol = str_ends_with($raw, "\r\n") ? "\r\n" : (str_ends_with($raw, "\n") ? "\n" : '');

        return $m[1].$m[2].'='.$eol;
    }
}
