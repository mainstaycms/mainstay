<?php

namespace Mainstay\Redirects;

use Closure;
use Mainstay\Mainstay;
use Mainstay\Settings\Setting;

/* What the site's owner set for the plugin, and the settings it declares. */
class RedirectsSettings
{
    private const RECORD_MISSES = 'redirects.record_misses';

    private const KEEP_DAYS = 'redirects.keep_days';

    private const MAX_MISSES = 'redirects.max_misses';

    private const TRASHED_TARGET = 'redirects.trashed_target';

    public function __construct(private Mainstay $mainstay) {}

    /** @return list<Setting> */
    public static function declared(): array
    {
        return [
            new Setting('record_misses', 'Record addresses that lead to a 404', 'boolean', 'Visitors\' requests that end in a 404 are counted per address on the Misses tab, so a redirect can be made from one.', default: true),
            new Setting('keep_days', 'Keep 404s for (days)', 'number', 'An address not asked for in this long is dropped from the Misses tab.', default: 90, rules: ['nullable', 'integer', 'min:1', 'max:3650']),
            new Setting('max_misses', 'Keep at most this many 404 addresses', 'number', 'No new address is recorded beyond this, and the least recent are dropped.', default: 5000, rules: ['nullable', 'integer', 'min:100', 'max:1000000']),
            new Setting('trashed_target', 'Where a trashed page goes', 'text', 'Empty: its address answers 410 Gone. Otherwise a path or an address the visitor is sent to with a 301, such as /news. Restoring the page removes it.', default: '', placeholder: '/news', rules: [self::validTarget(...)]),
        ];
    }

    public function recordMisses(): bool
    {
        return (bool) $this->mainstay->setting(self::RECORD_MISSES);
    }

    public function keepDays(): int
    {
        return (int) $this->mainstay->setting(self::KEEP_DAYS);
    }

    public function maxMisses(): int
    {
        return (int) $this->mainstay->setting(self::MAX_MISSES);
    }

    /* Null where a trashed page is gone. */
    public function trashedTarget(): ?string
    {
        return trim((string) $this->mainstay->setting(self::TRASHED_TARGET)) ?: null;
    }

    private static function validTarget(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '' && ($problem = Target::problem(trim($value))) !== null) {
            $fail($problem);
        }
    }
}
