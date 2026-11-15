<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Mainstay\Hook;
use Mainstay\Mcp\Tests\Fixtures\CountPeople;
use Mainstay\Plugin;

/* Hangs on every hook there is, and adds a tool and a route: a title is
   refused above TITLE_LIMIT and trimmed, the payload gains `shout`, and a
   list never shows "Hidden". What it heard is kept for a test to read. */
class Hooked extends Plugin
{
    public const TITLE_LIMIT = 12;

    private const HEARD = [
        'content.created', 'content.updated', 'content.draft-saved', 'content.published',
        'content.trashed', 'content.restored', 'content.deleted',
    ];

    private const BEFORE = ['content.trashing', 'content.restoring', 'content.deleting', 'content.publishing'];

    /** @var list<string> */
    public static array $heard = [];

    public static function handle(): string
    {
        return 'hooked';
    }

    public static function label(): string
    {
        return 'Hooked';
    }

    public function hooks(): array
    {
        return [
            Hook::filter('content.creating', $this->vetTitle(...), priority: 5),
            Hook::filter('content.updating', $this->vetTitle(...), priority: 5),
            Hook::filter('content.draft-saving', $this->vetTitle(...), priority: 5),
            Hook::filter('content.creating', $this->trimTitle(...)),
            Hook::filter('content.payload', fn (array $payload) => isset($payload['title']) ? [...$payload, 'shout' => strtoupper($payload['title'])] : $payload),
            Hook::filter('content.query', fn (array $where) => [...$where, 'title' => ['!=' => 'Hidden']]),
            ...array_map(fn (string $name) => Hook::action($name, function (string $type, int $id) use ($name) {
                self::$heard[] = "{$name}:{$id}";
            }), self::HEARD),
            ...array_map(fn (string $name) => Hook::action($name, function () use ($name) {
                self::$heard[] = $name;
            }), self::BEFORE),
        ];
    }

    public function mcpTools(): array
    {
        return [CountPeople::class];
    }

    public function apiRoutes(): ?string
    {
        return __DIR__.'/hooked-routes.php';
    }

    private function vetTitle(array $data): array
    {
        if (($data['title'] ?? '') === 'forbidden') {
            throw new AuthorizationException('Plugin says no.');
        }

        if (mb_strlen($data['title'] ?? '') > self::TITLE_LIMIT) {
            throw ValidationException::withMessages(['title' => 'A title is at most '.self::TITLE_LIMIT.' characters.']);
        }

        return $data;
    }

    private function trimTitle(array $data): array
    {
        return isset($data['title']) ? [...$data, 'title' => trim($data['title'])] : $data;
    }
}
