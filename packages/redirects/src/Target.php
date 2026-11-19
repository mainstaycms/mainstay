<?php

namespace Mainstay\Redirects;

/* Where a redirect may send: an address on this site or a full web address,
   and nothing a browser would read as a different host. */
final class Target
{
    public const MAX_LENGTH = 2000;

    public static function problem(string $target): ?string
    {
        if (mb_strlen($target) > self::MAX_LENGTH) {
            return 'The target is at most '.self::MAX_LENGTH.' characters.';
        }

        if (preg_match('/[\x00-\x20\x7f\\\\]/', $target)) {
            return 'The target has no spaces, control characters or backslashes: write a space as %20.';
        }

        if (str_starts_with($target, '/')) {
            return str_starts_with($target, '//') ? 'The target starts with two slashes, which browsers read as another site. Use one, or the full address.' : null;
        }

        return in_array(parse_url($target, PHP_URL_SCHEME), ['http', 'https'], true) && filter_var($target, FILTER_VALIDATE_URL) !== false
            ? null
            : 'The target is a path starting with /, or a full address starting with http:// or https://.';
    }
}
