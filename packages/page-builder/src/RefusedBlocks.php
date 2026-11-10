<?php

namespace Mainstay\PageBuilder;

use Mainstay\Content\Document;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Content;
use Mainstay\Http\Form;
use Mainstay\Mainstay;

class RefusedBlocks
{
    public function __construct(private Mainstay $mainstay) {}

    /*
     | After a save refused for a site block's field, each such block's
     | errors by its id, read from the content as it was posted -- or, for a
     | refused publish, as its draft saved it -- the blocks inside another
     | too: its name and its messages by where they are in it,
     | `data.headline`. A block posted without an id is the raw content's to
     | show alone.
     |
     | @return array<string, array{label: string, messages: array<string, list<string>>}>
     */
    public function of(Content $content, mixed $saved): array
    {
        $posted = old('content') !== null ? json_decode((string) old('content'), true) : ($saved instanceof Document ? $saved->toArray() : null);
        $errors = session('errors')?->getBag('default')->getMessages() ?? [];
        $invalid = [];

        $walk = function (mixed $nodes, ?Blocks $list, string $at) use (&$walk, &$invalid, $errors) {
            foreach (is_array($nodes) ? $nodes : [] as $index => $node) {
                $item = is_array($node) ? $node['attrs'] ?? null : null;

                if (($block = $list?->block($item)) === null) {
                    continue;
                }

                $messages = Form::blockErrors($block, $errors, "{$at}.{$index}.attrs.");

                if ($messages !== [] && is_string($id = $item['id'] ?? null) && $id !== '') {
                    $invalid[$id] = ['label' => Form::label($block), 'messages' => $messages];
                }

                $walk($node['content'] ?? null, $this->mainstay->innerBlocks($block), "{$at}.{$index}.content");
            }
        };

        $walk($posted['content'] ?? null, $content->listing(), 'content.content');

        return $invalid;
    }
}
