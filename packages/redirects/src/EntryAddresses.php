<?php

namespace Mainstay\Redirects;

use Mainstay\Database\Paths;
use Mainstay\Mainstay;

/*
 | The addresses an entry is answered at, in every locale, as a visitor asks
 | for them: its locale's prefix and its path. Whatever binds Paths gives
 | them; core's own gives none, which is what leaves the automatic redirects
 | with nothing to do on a site without addresses.
 */
class EntryAddresses
{
    public function __construct(private Paths $paths, private Mainstay $mainstay) {}

    /** @return array<string, string> by locale */
    public function of(string $type, int $id): array
    {
        if (! $this->paths->routed($type)) {
            return [];
        }

        $addresses = [];

        foreach ($this->paths->held($type, $id) as $locale => $path) {
            $prefix = $this->mainstay->locales()[$locale]['prefix'] ?? '';
            $addresses[$locale] = $prefix !== '' && $path === '/' ? $prefix : $prefix.$path;
        }

        return $addresses;
    }
}
