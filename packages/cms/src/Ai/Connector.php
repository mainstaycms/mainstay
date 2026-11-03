<?php

namespace Mainstay\Ai;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/* An AI provider's key as saved from the Connectors screen, and what the
   site uses it for: `['text', 'images']`. Written through Connectors. */
class Connector extends Model
{
    protected $table = 'mainstay_connectors';

    protected $fillable = ['provider', 'key', 'defaults'];

    protected $hidden = ['key'];

    protected $attributes = ['defaults' => '[]'];

    protected function casts(): array
    {
        return ['key' => 'encrypted', 'defaults' => 'array'];
    }

    /* The key, or null when it was saved under another APP_KEY -- a rotated
       one, or a database copied from another site. Reported, not thrown, so
       one such row stops neither laravel/ai nor the screen that replaces it. */
    public function readableKey(): ?string
    {
        try {
            return $this->key;
        } catch (DecryptException $exception) {
            report($exception);

            return null;
        }
    }

    /* Whether a key is saved, readable or not, without decrypting it. */
    public function holdsKey(): bool
    {
        return isset($this->attributes['key']);
    }

    /* A key saved under another APP_KEY cannot be decrypted to compare with,
       so whatever is saved over it is a change. */
    public function originalIsEquivalent($key)
    {
        try {
            return parent::originalIsEquivalent($key);
        } catch (DecryptException) {
            return false;
        }
    }
}
