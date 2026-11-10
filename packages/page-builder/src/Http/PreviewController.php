<?php

namespace Mainstay\PageBuilder\Http;

use Illuminate\Http\Request;
use Mainstay\Content\Block;
use Mainstay\Content\Document;
use Mainstay\Fields\Blocks;
use Mainstay\Http\Form;
use Mainstay\Mainstay;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/* The Add block dialog's previews: one block, or a kind of text, on a page of its own. */
class PreviewController
{
    private const SAMPLES = [
        'paragraph' => ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Text runs on in paragraphs like this one, with '],
            ['type' => 'text', 'marks' => [['type' => 'strong']], 'text' => 'bold'],
            ['type' => 'text', 'text' => ' and '],
            ['type' => 'text', 'marks' => [['type' => 'em']], 'text' => 'italic'],
            ['type' => 'text', 'text' => ' words in it, and '],
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '#']]], 'text' => 'links'],
            ['type' => 'text', 'text' => ' to other pages.'],
        ]],
        'heading' => ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'A heading over a section']]],
        'blockquote' => ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Words someone else said, set apart from the text around them.']]]]],
        'code_block' => ['type' => 'code_block', 'content' => [['type' => 'text', 'text' => "<?php\n\necho 'Shown as it was typed';"]]],
        'bullet_list' => ['type' => 'bullet_list', 'content' => [
            ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One thing']]]]],
            ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Another thing']]]]],
            ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'In no order']]]]],
        ]],
        'ordered_list' => ['type' => 'ordered_list', 'content' => [
            ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First this']]]]],
            ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Then this']]]]],
            ['type' => 'list_item', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Last this']]]]],
        ]],
        'horizontal_rule' => ['type' => 'horizontal_rule'],
    ];

    public function __construct(private Mainstay $mainstay, private PagedTypes $types, private Visitor $visitor) {}

    /*
     | A block as the Add block dialog previews it, `insert` naming it as the
     | dialog does -- a kind of text, `paragraph`, or `block:{handle}` -- on a
     | page of its own in the site's editor styles, drawn as a visitor sees
     | it: text from a sample, a site block from its #[Example]. Only one the
     | type's content holds; a site block with no example has no preview.
     */
    public function previews(Request $request, string $type): Response
    {
        $class = $this->types->resolve($type);
        $insert = (string) $request->query('insert');
        $block = str_starts_with($insert, 'block:') ? Form::blocks($class)[substr($insert, 6)] ?? null : null;

        if (! isset(self::SAMPLES[$insert]) && ($block === null || Form::example($block) === null)) {
            throw PagedTypes::missing();
        }

        $locale = Form::locale($request);

        try {
            $html = $block === null
                ? (new Document(['type' => 'doc', 'content' => [self::SAMPLES[$insert]]]))->toHtml()
                : $this->visitor->draw($request, $locale, fn () => $this->sample($block)->toHtml());
        } catch (Throwable $exception) {
            report($exception);

            $html = '<p>'.e($exception->getMessage() ?: 'The preview stopped drawing with '.class_basename($exception).'.').'</p>';
        }

        return response()->view('page_builder::preview', ['html' => $html, 'locale' => $locale, 'style' => $this->mainstay->editorStyle()]);
    }

    /* A block of `$class` from its #[Example], holding what it puts inside,
       or else one of each block it holds that has one, each from its own --
       but none of a kind it is inside, which would never end. */
    private function sample(string $class, array $around = []): Block
    {
        $example = Form::example($class);
        $inner = $example !== null && $example->inner !== [] ? $example->nodes() : ($this->mainstay->innerBlocks($class)->of ?? []);
        $nodes = $blocks = [];

        foreach ($inner as $item) {
            if (is_array($item)) {
                $nodes[] = $item;
            } elseif (Form::example($item) !== null && ! in_array($item, [...$around, $class], true)) {
                $block = $this->sample($item, [...$around, $class]);
                $blocks[$block->id] = $block;
                $nodes[] = ['type' => 'block', 'attrs' => ['id' => $block->id, 'type' => $item::handle()]];
            }
        }

        return (new Blocks([$class]))
            ->readItem(['type' => $class::handle(), 'data' => $example->data ?? []], '#[Example]')
            ->holding(new Document(['type' => 'doc', 'content' => $nodes], $blocks));
    }
}
