<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Env;

use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;

/**
 * Lossless lexer: every byte of the input ends up in exactly one EnvLine::$raw.
 *
 * @internal
 */
final class EnvLexer
{
    /**
     * @return array{0: list<EnvLine>, 1: list<SyntaxError>}
     */
    public function lex(string $contents): array
    {
        $physical = $contents === '' ? [] : preg_split('/(?<=\n)/', $contents, -1, PREG_SPLIT_NO_EMPTY);
        $physical = $physical === false ? [$contents] : $physical;

        $lines = [];
        $errors = [];
        $count = count($physical);
        $i = 0;

        while ($i < $count) {
            $start = $i + 1;
            $raw = $physical[$i];
            $trimmed = trim($raw);

            if ($trimmed === '') {
                $lines[] = new EnvLine(EnvEntryType::Blank, $raw, $start);
                $i++;

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $lines[] = new EnvLine(EnvEntryType::Comment, $raw, $start);
                $i++;

                continue;
            }

            if (preg_match('/^\s*(export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $raw, $m, PREG_OFFSET_CAPTURE) !== 1) {
                $lines[] = new EnvLine(EnvEntryType::Invalid, $raw, $start);
                $errors[] = new SyntaxError($start, 'Line is not a valid KEY=value assignment.');
                $i++;

                continue;
            }

            $exported = $m[1][0] !== '';
            $key = $m[2][0];
            $valueOffset = strlen($m[0][0]);
            $rest = ltrim(substr($raw, $valueOffset), " \t");
            $quote = $rest[0] ?? '';
            $end = $i;

            if ($quote === '"' || $quote === "'") {
                $closed = $this->findClosingQuote($physical, $i, $valueOffset + (strlen(substr($raw, $valueOffset)) - strlen($rest)) + 1, $quote);

                if ($closed === null && $quote === "'") {
                    $closed = $i; // single line; parser reports the failure
                }

                if ($closed === null) {
                    $remaining = implode('', array_slice($physical, $i));
                    $lines[] = new EnvLine(EnvEntryType::Invalid, $remaining, $start, $key);
                    $errors[] = new SyntaxError($start, 'Unterminated quoted value.');
                    $i = $count;

                    continue;
                }

                $end = $closed;
            }

            $raw = implode('', array_slice($physical, $i, $end - $i + 1));
            $value = null;

            try {
                $value = $this->parseValue($raw, $exported);
            } catch (InvalidFileException $e) {
                $errors[] = new SyntaxError($start, $this->safeMessage($e->getMessage()));
            }

            $lines[] = new EnvLine(EnvEntryType::Key, $raw, $start, $key, $value, $exported);
            $i = $end + 1;
        }

        return [$lines, $errors];
    }

    /**
     * Find the physical line index holding the closing quote, or null if unterminated.
     *
     * @param  list<string>  $physical
     */
    private function findClosingQuote(array $physical, int $lineIndex, int $offset, string $quote): ?int
    {
        $count = count($physical);

        // Only double-quoted values may span physical lines (matches phpdotenv).
        $last = $quote === '"' ? $count - 1 : $lineIndex;

        for ($i = $lineIndex; $i <= $last; $i++) {
            $text = $physical[$i];
            $len = strlen($text);

            for ($j = $i === $lineIndex ? $offset : 0; $j < $len; $j++) {
                if ($quote === '"' && $text[$j] === '\\') {
                    $j++;

                    continue;
                }

                if ($text[$j] === $quote) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * phpdotenv quotes the offending value ("... at [the value]."); that text may be a secret,
     * so it is cut off. Anything that still contains a bracket falls back to a fixed message.
     */
    private function safeMessage(string $message): string
    {
        $stripped = rtrim((string) preg_replace('/\s*at \[.*\]\.?$/s', '', $message));

        if ($stripped === '' || str_contains($stripped, '[')) {
            return 'Value could not be parsed.';
        }

        return rtrim($stripped, '.').'.';
    }

    private function parseValue(string $raw, bool $exported): string
    {
        $source = rtrim($raw, "\r\n");
        $source = ltrim($source);

        if ($exported) {
            $source = preg_replace('/^export\s+/', '', $source) ?? $source;
        }

        $entries = (new Parser)->parse($source);
        $value = isset($entries[0]) ? $entries[0]->getValue()->getOrElse(null) : null;

        return $value === null ? '' : $value->getChars();
    }
}
