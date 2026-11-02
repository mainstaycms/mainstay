<?php

namespace Mainstay\Content;

use Attribute;
use InvalidArgumentException;

/*
 | What a block is previewed with in the Content tab's Add block dialog: its
 | values by field, as the content stores them, `#[Example(['label' => 'Read
 | the docs'])]`, and what is inside it, `inner:` -- text written as the tag
 | that draws it, `['h2' => 'What you get']` or `['p' => '...']`, and a block
 | it holds by its class, drawn from that block's own example. Its view draws
 | it from them in the site's editor styles; a field left out holds its
 | default, or nothing. With no `inner:`, a block holding others is drawn
 | holding one of each that has an example. Only a preview: a block added
 | starts with its fields empty, holding an empty paragraph where it holds
 | text, and otherwise the first block it holds. A block with none is shown
 | by its name alone.
 |
 | Not inherited, as #[Route] is not: a subclass is a block of its own.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Example
{
    /* The tags text is written as, and the node each one draws. */
    private const TAGS = ['p' => null, 'h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

    /** @param list<class-string<Block>|array<string, string>> $inner */
    public function __construct(public readonly array $data = [], public readonly array $inner = [])
    {
        if (($data === [] && $inner === []) || ($data !== [] && array_is_list($data))) {
            throw new InvalidArgumentException("#[Example] gives a block's values by field, #[Example(['heading' => 'What you get'])], and what is inside it, #[Example(inner: [['h2' => 'What you get'], Feature::class])].");
        }

        foreach ($inner as $item) {
            $text = is_array($item) && count($item) === 1 && array_key_exists($tag = array_key_first($item), self::TAGS) && is_string($item[$tag]);

            if (! $text && ! (is_string($item) && is_subclass_of($item, Block::class))) {
                throw new InvalidArgumentException('#[Example] puts '.json_encode($item).' inside a block, which is neither text, '.implode(', ', array_map(fn (string $tag) => "['{$tag}' => '...']", array_keys(self::TAGS))).', nor a block\'s class.');
            }
        }
    }

    /* What is inside the block, as the content stores it: its text as nodes,
       and each block it holds by its class. */
    public function nodes(): array
    {
        return array_map(function (string|array $item) {
            if (is_string($item)) {
                return $item;
            }

            $level = self::TAGS[$tag = array_key_first($item)];
            $text = $item[$tag] === '' ? [] : ['content' => [['type' => 'text', 'text' => $item[$tag]]]];

            return $level === null ? ['type' => 'paragraph', ...$text] : ['type' => 'heading', 'attrs' => ['level' => $level], ...$text];
        }, $this->inner);
    }
}
