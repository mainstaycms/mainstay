<?php

namespace Mainstay\Redirects;

use RuntimeException;

/*
 | A regex source as the admin takes it: PCRE without delimiters, matched
 | against the whole path, so `/old/(.*)` is anchored at both ends as the
 | reader would think and a partial match is written with `.*`. A pattern a
 | visitor's path can make backtrack without end is cut off by PCRE's own
 | limit, lowered here, and reported as an error rather than waited for.
 */
final class Pattern
{
    public const MAX_LENGTH = 500;

    /* Far below PHP's default of a million: a real path is short. */
    private const BACKTRACK_LIMIT = 20000;

    /* Near misses a pattern that backtracks without end shows itself on when
       saved, as `(a+)+$` does, with and without a path's leading slash. It
       is a heuristic: what it does not catch is cut off by the limit when a
       visitor's path finds it. */
    private const PROBES = ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!', '/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!'];

    /* `$1`, `${1}` and `${name}` in a target. */
    private const REFERENCE = '/\$(?:(\d)|\{(\w+)\})/';

    /**
     * @return array<int|string, string> the groups the path matched, by number and name
     *
     * @throws RuntimeException where the pattern cannot be run, naming why
     */
    public static function match(string $pattern, string $path): ?array
    {
        $limit = ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

        try {
            $groups = [];
            $matched = self::run(function () use ($pattern, $path, &$groups) {
                return preg_match(self::compile($pattern), $path, $groups);
            });
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        return $matched === 1 ? $groups : null;
    }

    /* The target with each reference replaced by what the group captured,
       encoded as a path segment so a captured `//evil.example` or line break
       cannot become part of the address. */
    public static function expand(string $target, array $groups): string
    {
        $expanded = preg_replace_callback(
            self::REFERENCE,
            fn (array $reference) => implode('/', array_map(rawurlencode(...), explode('/', (string) ($groups[($reference[2] ?? '') ?: (int) $reference[1]] ?? '')))),
            $target,
        );

        return preg_replace('#^/{2,}#', '/', $expanded);
    }

    /* What is wrong with a pattern and the target it is used with, or null. */
    public static function problem(string $pattern, ?string $target): ?string
    {
        if (mb_strlen($pattern) > self::MAX_LENGTH) {
            return 'The pattern is at most '.self::MAX_LENGTH.' characters.';
        }

        try {
            $names = self::groupNames($pattern);

            foreach (self::PROBES as $probe) {
                self::match($pattern, $probe);
            }
        } catch (RuntimeException $failure) {
            return "The pattern does not work: {$failure->getMessage()}";
        }

        foreach (self::references((string) $target) as $reference) {
            if (! in_array($reference, $names, true)) {
                return 'The target uses '.(is_int($reference) ? "\${$reference}" : "\${{$reference}}").', which the pattern has no group for.';
            }
        }

        return null;
    }

    /**
     * @return list<int|string> the numbers and names of the pattern's groups
     */
    public static function groupNames(string $pattern): array
    {
        $groups = [];
        self::run(function () use ($pattern, &$groups) {
            return preg_match('~(?:'.self::delimited($pattern).')|~u', '', $groups, PREG_UNMATCHED_AS_NULL);
        });

        return array_keys($groups);
    }

    /**
     * @return list<int|string> the groups a target asks for
     */
    public static function references(string $target): array
    {
        preg_match_all(self::REFERENCE, $target, $found, PREG_SET_ORDER);

        return array_map(fn (array $reference) => ($reference[2] ?? '') ?: (int) $reference[1], $found);
    }

    private static function compile(string $pattern): string
    {
        return '~^(?:'.self::delimited($pattern).')$~u';
    }

    /* The delimiter, escaped wherever it is not already. */
    private static function delimited(string $pattern): string
    {
        return preg_replace('/(?<!\\\\)~/', '\\~', $pattern);
    }

    /* preg_* warns about a pattern it cannot compile, which Laravel turns into
       an exception nobody can read; a failure of any kind is one of ours. */
    private static function run(callable $call): int
    {
        set_error_handler(function (int $level, string $message) {
            throw new RuntimeException(preg_replace('/^preg_\w+\(\): /', '', $message));
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new RuntimeException(preg_last_error_msg());
        }

        return $result;
    }
}
