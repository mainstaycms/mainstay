<?php

namespace Mainstay\Redirects;

/*
 | What makes two spellings of an address one: decoded, lowercase, one slash
 | between segments and none at the end, since the site's own paths are
 | lowercase and its catch-all sends a trailing slash to the path without.
 */
final class Address
{
    public static function normalise(string $path): string
    {
        $path = strtolower(rawurldecode(strtok($path, '?#') ?: ''));

        return '/'.trim((string) preg_replace('#/+#', '/', $path), '/');
    }

    /* Both kinds of source in one unique index, told apart by their type. */
    public static function hash(string $matchType, string $source): string
    {
        return sha1($matchType === Redirect::EXACT ? self::normalise($source) : "{$matchType}:{$source}");
    }
}
