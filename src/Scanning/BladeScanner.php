<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\Parser;

/**
 * Finds env calls in the parts of a Blade file that run as PHP: echoes, directive arguments, `@php`
 * blocks and PHP tags. Text, Blade comments and `@verbatim` are skipped. Each part is tokenized, not
 * compiled, so nothing runs and a part that isn't a full PHP statement (`$a as $b`) is fine.
 */
final class BladeScanner
{
    /** What opens a part worth looking at, unless escaped with a leading `@`. */
    private const OPENER = '/(?<!@)(?:\{\{--|\{!!|\{\{|<\?php\b|<\?=|@verbatim\b|@php\b(?!\s*\()|@[A-Za-z_]\w*\s*\()/';

    private const CLOSERS = ['{{--' => '--}}', '{!!' => '!!}', '{{' => '}}', '<?php' => '?>', '<?=' => '?>', '@verbatim' => '@endverbatim', '@php' => '@endphp'];

    public function __construct(private readonly Parser $parser) {}

    /**
     * @return list<array{key: ?string, pattern: ?string, default: DefaultKind, defaultValue: ?string, line: int}>
     */
    public function scan(string $code): array
    {
        $found = [];
        $offset = 0;

        while (preg_match(self::OPENER, $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$opener, $start] = $match[0];
            $bodyStart = $start + strlen($opener);

            if (str_starts_with($opener, '@') && str_ends_with($opener, '(')) {
                $bodyEnd = $this->closingBracket($code, $bodyStart);
                $next = $bodyEnd + 1;
            } else {
                $closer = self::CLOSERS[$opener];
                $position = strpos($code, $closer, $bodyStart);
                $bodyEnd = $position === false ? strlen($code) : $position;
                $next = $bodyEnd + strlen($closer);
            }

            if ($opener !== '{{--' && $opener !== '@verbatim') {
                $line = substr_count($code, "\n", 0, $bodyStart) + 1;
                array_push($found, ...$this->calls(substr($code, $bodyStart, $bodyEnd - $bodyStart), $line));
            }

            $offset = min($next, strlen($code));
        }

        return $found;
    }

    /** Where the bracket matching an already opened one closes; quoted text doesn't count. */
    private function closingBracket(string $code, int $from): int
    {
        $depth = 1;
        $quote = null;
        $length = strlen($code);

        for ($i = $from; $i < $length; $i++) {
            $char = $code[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * @return list<array{key: ?string, pattern: ?string, default: DefaultKind, defaultValue: ?string, line: int}>
     */
    private function calls(string $php, int $firstLine): array
    {
        $tokens = token_get_all('<?php '.$php);
        $count = count($tokens);
        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $this->callAt($tokens, $i) : null;

            if (is_array($token) && $text !== null) {
                foreach ($this->parseCall($text) as $info) {
                    $found[] = $info + ['line' => $firstLine + $token[2] - 1];
                }
            }
        }

        return $found;
    }

    /**
     * The source of an env call starting at token $i, up to its closing bracket.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function callAt(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];

        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $previous = $this->significant($tokens, $i, -1);

        if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            return null;
        }

        $name = strtolower(ltrim($token[1], '\\'));
        $next = $i + 1;

        if (in_array($name, ['env', 'illuminate\support\env'], true) && $this->significantToken($tokens, $next) === [T_DOUBLE_COLON]) {
            $next = $this->significantIndex($tokens, $next) + 1;
            $method = $tokens[$this->significantIndex($tokens, $next)] ?? null;

            if (! is_array($method) || ! in_array(strtolower($method[1]), ['get', 'getorfail'], true)) {
                return null;
            }

            $next = $this->significantIndex($tokens, $next) + 1;
        } elseif ($name !== 'env') {
            return null;
        }

        $open = $this->significantIndex($tokens, $next);

        if (($tokens[$open] ?? null) !== '(') {
            return null;
        }

        $source = '';
        $depth = 0;
        $count = count($tokens);

        for ($j = $i; $j < $count; $j++) {
            $piece = $tokens[$j];
            $source .= is_array($piece) ? $piece[1] : $piece;

            if ($j >= $open && $piece === '(') {
                $depth++;
            } elseif ($j >= $open && $piece === ')' && --$depth === 0) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function significantIndex(array $tokens, int $from): int
    {
        while (is_array($tokens[$from] ?? null) && in_array($tokens[$from][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $from++;
        }

        return $from;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int}|null
     */
    private function significantToken(array $tokens, int $from): ?array
    {
        $token = $tokens[$this->significantIndex($tokens, $from)] ?? null;

        return is_array($token) ? [$token[0]] : null;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function significant(array $tokens, int $from, int $direction): array|string|null
    {
        for ($i = $from + $direction; isset($tokens[$i]); $i += $direction) {
            if (! is_array($tokens[$i]) || ! in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @return list<array{key: ?string, pattern: ?string, default: DefaultKind, defaultValue: ?string}>
     */
    private function parseCall(string $source): array
    {
        try {
            $stmts = $this->parser->parse('<?php '.$source.';') ?? [];
        } catch (Error) {
            return [];
        }

        // Only the outer call: one nested in its arguments has its own tokens and is found separately.
        $call = (new NodeFinder)->findFirst($stmts, static fn (Node $n): bool => $n instanceof FuncCall || $n instanceof StaticCall);
        $info = $call instanceof FuncCall || $call instanceof StaticCall ? EnvCallExtractor::fromCall($call) : null;

        return $info !== null ? [$info] : [];
    }
}
