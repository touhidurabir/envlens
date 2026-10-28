<?php

declare(strict_types=1);

namespace Touhidurabir\EnvLens\Scanning;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;

/**
 * Recognises env() / Env::get() calls in a parsed PHP syntax tree.
 */
final class EnvCallExtractor
{
    /**
     * @return array{key: ?string, pattern: ?string, default: DefaultKind, defaultValue: ?string}|null
     */
    public static function fromCall(FuncCall|StaticCall $node): ?array
    {
        if (! self::isEnvCall($node)) {
            return null;
        }

        $args = $node->args;
        $first = $args[0] ?? null;
        if (! $first instanceof Arg || $first->unpack) {
            return null;
        }

        $key = null;
        $pattern = null;
        $parts = self::keyParts($first->value);
        if ($parts === null) {
            // Not a recognisable literal composition: fully dynamic.
        } elseif (! in_array(null, $parts, true)) {
            $key = implode('', $parts);
        } else {
            $glob = preg_replace('/\*+/', '*', implode('', array_map(fn (?string $p) => $p ?? '*', $parts))) ?? '*';
            $pattern = $glob === '*' ? null : $glob;
        }

        [$default, $defaultValue] = self::defaultOf($args[1] ?? null);

        return ['key' => $key, 'pattern' => $pattern, 'default' => $default, 'defaultValue' => $defaultValue];
    }

    private static function isEnvCall(FuncCall|StaticCall $node): bool
    {
        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && strtolower($node->name->toString()) === 'env';
        }

        return $node->class instanceof Name
            && $node->name instanceof Identifier
            && in_array(strtolower($node->name->toString()), ['get', 'getorfail'], true)
            && in_array(strtolower(ltrim($node->class->toString(), '\\')), ['env', 'illuminate\support\env'], true);
    }

    /**
     * Literal parts of the key expression; null entries are variable parts.
     * Returns null when the expression is not a string, interpolation or concat.
     *
     * @return list<?string>|null
     */
    private static function keyParts(Expr $expr): ?array
    {
        if ($expr instanceof String_) {
            return [$expr->value];
        }

        if ($expr instanceof InterpolatedString) {
            $parts = [];
            foreach ($expr->parts as $part) {
                $parts[] = $part instanceof InterpolatedStringPart ? $part->value : null;
            }

            return $parts;
        }

        if ($expr instanceof Concat) {
            $left = self::keyParts($expr->left) ?? [null];
            $right = self::keyParts($expr->right) ?? [null];

            return [...$left, ...$right];
        }

        return null;
    }

    /**
     * @return array{0: DefaultKind, 1: ?string}
     */
    private static function defaultOf(mixed $arg): array
    {
        if (! $arg instanceof Arg) {
            return [DefaultKind::None, null];
        }

        $value = $arg->value;

        if ($value instanceof ConstFetch) {
            return match (strtolower($value->name->toString())) {
                'null' => [DefaultKind::Null, null],
                'true' => [DefaultKind::Literal, 'true'],
                'false' => [DefaultKind::Literal, 'false'],
                default => [DefaultKind::NonLiteral, null],
            };
        }

        if ($value instanceof String_) {
            return [DefaultKind::Literal, $value->value];
        }

        if ($value instanceof Int_ || $value instanceof Float_) {
            return [DefaultKind::Literal, (string) $value->value];
        }

        if ($value instanceof UnaryMinus && ($value->expr instanceof Int_ || $value->expr instanceof Float_)) {
            return [DefaultKind::Literal, '-'.$value->expr->value];
        }

        return [DefaultKind::NonLiteral, null];
    }
}
