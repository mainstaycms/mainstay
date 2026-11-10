<?php

namespace Mainstay\PageBuilder;

use Mainstay\Admin\EntryTab;
use Mainstay\Admin\EntryTabContext;
use Mainstay\Database\Paths;
use Mainstay\Fields\Content;
use Mainstay\Fields\Field;
use Mainstay\Fields\Image;
use Mainstay\Http\Form;
use Mainstay\Mainstay;

/*
 | The Content tab of a type with a page: the page the site draws from the
 | form, edited in place. A type without one -- the site plugin gives the
 | addresses -- has rich text in Details alone.
 */
class ContentTab implements EntryTab
{
    public function __construct(private Mainstay $mainstay, private Paths $paths, private RefusedBlocks $refused) {}

    public function handle(): string
    {
        return 'content';
    }

    public function label(): string
    {
        return 'Content';
    }

    public function applies(string $type): bool
    {
        return $this->paths->routed($type);
    }

    public function panel(): string
    {
        return 'page_builder::tab';
    }

    public function toolbar(): ?string
    {
        return 'page_builder::toolbar';
    }

    public function aside(): ?string
    {
        return 'page_builder::tree';
    }

    /*
     | Where the tab draws the page and a block's settings from, the Add
     | block dialog's previews, and every block the content holds, by handle,
     | with its icon, what it is for, whether it has a preview, the blocks it
     | holds inside it and whether text; and the ones at its top level.
     */
    public function data(EntryTabContext $context): array
    {
        $type = $context->type;
        $handle = $type::handle();
        $content = $context->fields['content'] ?? null;
        $at = match (true) {
            $context->id !== null => fn (string $kind) => route("mainstay.{$kind}.entry", [$handle, $context->id]),
            $context->draft !== null => fn (string $kind) => route("mainstay.{$kind}.draft", [$handle, $context->draft->id]),
            default => fn (string $kind) => route("mainstay.{$kind}.create", $handle),
        };

        return [
            'canvas' => $context->localized($at('canvas')),
            'block' => $context->localized($at('block')),
            'previews' => $context->localized(route('mainstay.previews', $handle)),
            'blocks' => collect(Form::blocks($type))->map(fn (string $block) => [
                'label' => Form::label($block),
                'icon' => Form::icon($block),
                'description' => Form::description($block),
                'example' => Form::example($block) !== null,
                'inner' => array_map(fn (string $inner) => $inner::handle(), $this->mainstay->innerBlocks($block)->of ?? []),
                'text' => $this->mainstay->innerBlocks($block)->text ?? false,
            ])->all(),
            'top' => array_map(fn (string $block) => $block::handle(), $content instanceof Content ? $content->blocks() : []),
            /* The library's picker and image dialog, for a type whose blocks
               hold an image: a block's settings are drawn after the page, too
               late to push them. */
            'pictures' => collect(Form::blocks($type))->contains(fn (string $block) => collect($this->mainstay->fields($block))->contains(fn (Field $field) => $field instanceof Image)),
            'invalid' => $content instanceof Content ? $this->refused->of($content, $context->values['content'] ?? null) : [],
        ];
    }
}
