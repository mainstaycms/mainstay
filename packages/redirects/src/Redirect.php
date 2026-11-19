<?php

namespace Mainstay\Redirects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One rule: where a request for `source` goes. An exact source is an address
 * and a regex source a pattern over addresses. It is written through
 * RedirectStore, which keeps the hash and the cached rules in step.
 *
 * @property int $id
 * @property string $source
 * @property string $source_hash
 * @property string $match_type
 * @property ?string $target
 * @property int $status
 * @property string $origin
 * @property ?string $entry_type
 * @property ?int $entry_id
 * @property ?string $locale
 * @property bool $preserve_query
 * @property bool $enabled
 * @property int $priority
 * @property ?string $note
 * @property int $hits
 * @property ?CarbonInterface $last_hit_at
 * @property ?string $last_error
 */
class Redirect extends Model
{
    public const EXACT = 'exact';

    public const REGEX = 'regex';

    public const CUSTOM = 'custom';

    public const MOVED = 'moved';

    public const TRASHED = 'trashed';

    public const GONE = 410;

    public const STATUSES = [301, 302, 307, 308, self::GONE];

    public const DEFAULT_STATUS = 301;

    public const DEFAULT_PRIORITY = 100;

    protected $table = 'mainstay_redirects';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'preserve_query' => 'boolean',
            'enabled' => 'boolean',
            'priority' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    public function isRegex(): bool
    {
        return $this->match_type === self::REGEX;
    }

    public function isGone(): bool
    {
        return $this->status === self::GONE;
    }

    public function isAutomatic(): bool
    {
        return $this->origin !== self::CUSTOM;
    }
}
