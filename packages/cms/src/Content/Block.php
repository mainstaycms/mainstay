<?php

namespace Mainstay\Content;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Mainstay\Fields\Field;
use Mainstay\Mainstay;

/*
 | One block of a content: a class whose properties carry the same field
 | attributes an entry's do, read and written through the #[Blocks] list
 | that names it -- an entry type's, or the block's that holds it. The blocks
 | themselves are the site's to write, and so are the views that draw them.
 |
 | Htmlable, drawn by the view `blocks.{handle}` with the block as `$block`,
 | so a template prints a content's with `@foreach ($entry->content->blocks()
 | as $block) {{ $block }} @endforeach`. A view that is not there is an
 | error, as an entry's is. A block whose class names blocks with #[Blocks]
 | holds them inside it, its inner blocks -- and text among them where it
 | names text -- which its view prints with `@inner($block)`.
 */
abstract class Block implements Arrayable, Htmlable
{
    /* Given by the layer when the block is first written, and kept: what
       tells a block moved from a block replaced. */
    public string $id;

    /* Private, so a block's own field of this name is a slot of its own. */
    private ?Document $inner = null;

    /* The view namespace its view is in, as a field type's is: a plugin's
       block names its plugin's handle, and the host's own names none. */
    protected ?string $viewNamespace = null;

    /** @return list<Block> the blocks inside this one, in their order, without the text among them */
    public function inner(): array
    {
        return $this->inner?->blocks() ?? [];
    }

    /* What is inside this one, the text among its blocks too, as @inner
       prints it: a document of it. */
    public function innerContent(): Document
    {
        return $this->inner ?? new Document(['type' => 'doc', 'content' => []]);
    }

    /**
     * The content's read hands a block what is inside it.
     *
     * @internal
     */
    public function holding(Document $inner): static
    {
        $this->inner = $inner;

        return $this;
    }

    /* What a stored block's `type` says, and its view's name, so one block's
       alone: two blocks of one handle are refused, in whichever fields. */
    public static function handle(): string
    {
        return Str::snake(class_basename(static::class));
    }

    public function toHtml(): string
    {
        $namespace = $this->viewNamespace === null ? '' : $this->viewNamespace.'::';

        return view($namespace.'blocks.'.static::handle(), ['block' => $this])->render();
    }

    /* The block as a save is given one: its id once it has one, its type,
       and what each field holds, which the save checks before anything is
       written. A field not set, on a block built by hand, holds nothing. */
    public function toArray(): array
    {
        return [
            ...(isset($this->id) ? ['id' => $this->id] : []),
            'type' => static::handle(),
            'data' => array_map(fn (Field $field) => $this->{$field->name} ?? null, app(Mainstay::class)->fields(static::class)),
        ];
    }
}
