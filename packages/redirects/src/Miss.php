<?php

namespace Mainstay\Redirects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * An address visitors asked for and found nothing at, with how often.
 *
 * @property int $id
 * @property string $address
 * @property string $address_hash
 * @property int $hits
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface $last_seen_at
 * @property ?string $referrer
 * @property bool $ignored
 */
class Miss extends Model
{
    public $timestamps = false;

    protected $table = 'mainstay_redirect_misses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'ignored' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function pendingCount(): int
    {
        return self::query()->where('ignored', false)->count();
    }
}
