<?php

namespace Mainstay\Settings;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Mainstay\Mainstay;
use Mainstay\Plugin;

/*
 | The site's settings: what each plugin declares in `settings()`, and what
 | the owner has set, kept in one table. Read by `Mainstay::setting()` and
 | written by `Mainstay::setSetting()`, which are code writing on its own
 | authority; the Settings screen is what asks who may.
 |
 | Stored once per request, the first time one is read, and again only if
 | something writes. A key nobody declared is never written, so a typo does
 | not become a setting nobody can reach.
 */
class Settings
{
    private const TABLE = 'mainstay_settings';

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function __construct(private Mainstay $mainstay) {}

    /**
     * Every plugin's settings by their full key, `<handle>.<key>`.
     *
     * @return array<string, array{plugin: Plugin, setting: Setting}>
     */
    public function declared(): array
    {
        $declared = [];

        foreach ($this->mainstay->plugins() as $handle => $plugin) {
            foreach ($plugin->settings() as $setting) {
                $key = "{$handle}.{$setting->key}";

                if (isset($declared[$key])) {
                    throw new LogicException($plugin::class." declares the setting {$setting->key} twice.");
                }

                $declared[$key] = ['plugin' => $plugin, 'setting' => $setting];
            }
        }

        return $declared;
    }

    /* What the owner set; else `$default`; else the default its plugin declared. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->stored()[$key] ?? $default ?? ($this->declared()[$key]['setting']->default ?? null);
    }

    /* Null forgets it, so it holds its default again. */
    public function set(string $key, mixed $value): void
    {
        if (! isset($this->declared()[$key])) {
            throw new InvalidArgumentException("No plugin declares a setting called {$key}. It is its plugin's handle, a dot and the key its settings() gives.");
        }

        $stored = $this->stored();

        if ($value === null) {
            DB::table(self::TABLE)->where('name', $key)->delete();
            unset($stored[$key]);
        } else {
            $now = now();
            DB::table(self::TABLE)->upsert(
                [['name' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['value', 'updated_at'],
            );
            $stored[$key] = $value;
        }

        $this->stored = $stored;
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return $this->stored ??= DB::table(self::TABLE)->pluck('value', 'name')->map(fn (string $value) => json_decode($value, true, flags: JSON_THROW_ON_ERROR))->all();
    }
}
