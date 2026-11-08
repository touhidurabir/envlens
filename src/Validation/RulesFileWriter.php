<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Validation;

/** Renders inferred rules as an `env.rules.php`, keeping the assumption behind each one beside it. */
final class RulesFileWriter
{
    public function render(Inference $inference): string
    {
        $rules = $inference->rules;
        usort($rules, static fn (InferredRule $a, InferredRule $b): int => $a->key <=> $b->key);

        $entries = array_map(self::entry(...), $rules);
        $body = $entries === [] ? '' : implode("\n\n", $entries)."\n";

        return <<<PHP
        <?php

        // Rules for php artisan env:validate, generated from your config. Review them: each comment says
        // what was assumed. A failure here fails the command, so change or remove what is wrong for you.
        // Any Laravel validation rule works. Values are what env() returns, so remember: min, max and
        // between measure a value's length unless the key also has integer or numeric.

        return [
            'rules' => [
        {$body}    ],

            // Rules added for one environment, on top of the rules above:
            // 'environments' => [
            //     'production' => [
            //         'APP_DEBUG' => ['declined'],
            //         'APP_URL' => ['starts_with:https://'],
            //     ],
            // ],

            // Custom messages. Never shown for sensitive keys, where only the rule's name is printed:
            // 'messages' => [
            //     'APP_URL.starts_with' => 'Production must use https.',
            // ],

            // Keep checking keys not listed above, from how config reads them. These only warn:
            'infer' => true,
        ];

        PHP;
    }

    /** The assumption, then the key with one rule per line. A rule a person must decide on is commented out. */
    private static function entry(InferredRule $rule): string
    {
        $location = $rule->file !== null ? " ({$rule->file}:{$rule->line})" : '';
        $lines = [
            var_export($rule->key, true).' => [',
            ...array_map(static fn (string $part): string => '    '.var_export($part, true).',', explode('|', $rule->rule)),
            '],',
        ];

        if (! $rule->active) {
            $lines = array_map(static fn (string $line): string => '// '.self::comment($line), $lines);
        }

        return implode("\n", array_map(static fn (string $line): string => '        '.$line, ['// '.self::comment($rule->assumption.$location), ...$lines]));
    }

    /** A config default is printed in a comment, so it must not be able to end that comment. */
    private static function comment(string $text): string
    {
        return str_replace(["\r", "\n", '?>'], [' ', ' ', '? >'], $text);
    }
}
