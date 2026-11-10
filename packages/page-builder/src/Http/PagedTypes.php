<?php

namespace Mainstay\PageBuilder\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Mainstay\Content\Entry;
use Mainstay\Database\Paths;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | The type a Content tab's request names: an entry type with a page, which
 | whoever asks may write to at all, as the sidebar offers it. Anything else is
 | the admin's own not-found.
 */
class PagedTypes
{
    public function __construct(private Mainstay $mainstay, private Paths $paths) {}

    /** @return class-string<Entry> */
    public function resolve(string $handle): string
    {
        $class = $this->mainstay->registered()[$handle] ?? throw self::missing();

        if (! is_subclass_of($class, Entry::class)) {
            throw self::missing();
        }

        if (! Navigation::writes($class)) {
            throw new AuthorizationException('You may not change '.Navigation::label($class).'.');
        }

        if (! $this->paths->routed($class)) {
            throw self::missing();
        }

        return $class;
    }

    /* Drawn in the shell, as any admin path nothing answers is. */
    public static function missing(): HttpResponseException
    {
        return new HttpResponseException(response()->view('mainstay::missing', status: 404));
    }
}
