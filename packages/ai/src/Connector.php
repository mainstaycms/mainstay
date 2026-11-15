<?php

namespace Mainstay\Ai;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

/** An AI provider's key as saved from the Connectors screen, what the site
   uses it for -- `['text', 'images']` -- and the model it chose for some of
   that: `['text' => 'claude-haiku-5-5']`, null on a row saved before models
   were. Written through Connectors.

   @property array<string, string>|null $models */
class Connector extends Model
{
    protected $table = 'mainstay_connectors';

    protected $fillable = ['provider', 'key', 'defaults', 'models'];

    protected $hidden = ['key'];

    protected $attributes = ['defaults' => '[]'];

    protected function casts(): array
    {
        return ['key' => 'encrypted', 'defaults' => 'array', 'models' => 'array'];
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

    /* Null where none is chosen, and for a row saved before models were. */
    public function modelFor(string $use): ?string
    {
        return $this->models[$use] ?? null;
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
