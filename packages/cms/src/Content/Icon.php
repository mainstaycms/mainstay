<?php

namespace Mainstay\Content;

use Attribute;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

/*
 | A block's icon where the admin lists blocks in little room -- the Content
 | tab's tree, the `/` menu, the blocks inside a block, and an Add block card
 | with no preview: one of Mainstay's own by name, `#[Icon('grid')]`, drawn
 | as every icon in the admin is, or an SVG file of the site's,
 | `#[Icon(file: 'icons/hero.svg')]`, under resources/ unless the path is
 | absolute, drawn with its own colours. A block with none is drawn with
 | `block`. On a plugin's class, it is the plugin's item in the sidebar.
 |
 | A file is drawn as an image of itself rather than inlined: the admin draws
 | each icon more than once, and an inlined copy's ids -- a gradient's, a clip
 | path's, which every design tool's export has -- would be the page's twice,
 | the first copy's in a hidden menu blanking the rest.
 |
 | Not inherited, as #[Route] is not: a subclass is a block of its own.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Icon implements Htmlable
{
    /* Each icon's shapes, in the 16-unit box the admin's icons are drawn in. */
    private const SHAPES = [
        'block' => '<rect x="2.25" y="2.25" width="11.5" height="11.5" rx="1.5" />',
        'paragraph' => '<path d="M12.75 2.25H6.75a3 3 0 0 0 0 6h2M8.75 2.25v11.5M11.5 2.25v11.5" />',
        'heading' => '<path d="M3.25 2.75v10.5M12.75 2.75v10.5M3.25 8h9.5" />',
        'quote' => '<path d="M2.75 2.75v10.5M6 4.75h7.25M6 8h7.25M6 11.25h4.5" />',
        'code' => '<path d="m5.25 4.5-3.5 3.5 3.5 3.5M10.75 4.5l3.5 3.5-3.5 3.5M9.25 2.75l-2.5 10.5" />',
        'list' => '<path d="M6.25 4h7.5M6.25 8h7.5M6.25 12h7.5" /><circle cx="2.75" cy="4" r=".75" /><circle cx="2.75" cy="8" r=".75" /><circle cx="2.75" cy="12" r=".75" />',
        'ordered-list' => '<path d="M6.25 4h7.5M6.25 8h7.5M6.25 12h7.5M2 2.75h1v3M2 9.75a1 1 0 0 1 1.75.5c0 .75-1.75 1.5-1.75 2.25h1.9" />',
        'separator' => '<path d="M1.75 8h2.5M6.75 8h2.5M11.75 8h2.5M3.75 3.75h8.5M3.75 12.25h8.5" />',
        'grid' => '<rect x="2.25" y="2.25" width="4.5" height="4.5" rx="1" /><rect x="9.25" y="2.25" width="4.5" height="4.5" rx="1" /><rect x="2.25" y="9.25" width="4.5" height="4.5" rx="1" /><rect x="9.25" y="9.25" width="4.5" height="4.5" rx="1" />',
        'columns' => '<rect x="1.75" y="2.75" width="12.5" height="10.5" rx="1.5" /><path d="M8 2.75v10.5" />',
        'banner' => '<rect x="1.75" y="2.25" width="12.5" height="6.5" rx="1.25" /><path d="M1.75 11.25h12.5M1.75 13.75h8" />',
        'image' => '<rect x="1.75" y="2.75" width="12.5" height="10.5" rx="1.5" /><circle cx="5.5" cy="6.25" r="1.25" /><path d="m14.25 10.25-3.5-3.5-6.75 6.5" />',
        'gallery' => '<rect x="4.75" y="1.75" width="9.5" height="8.5" rx="1.25" /><path d="M11.75 12.5v.5a1 1 0 0 1-1 1h-8a1 1 0 0 1-1-1V6.25a1 1 0 0 1 1-1h.5" />',
        'video' => '<rect x="1.75" y="3.25" width="12.5" height="9.5" rx="1.5" /><path d="M6.75 6v4l3.5-2z" />',
        'star' => '<path d="m8 1.75 1.9 3.9 4.3.6-3.1 3 .75 4.25L8 11.5l-3.85 2 .75-4.25-3.1-3 4.3-.6z" />',
        'newspaper' => '<path d="M2.25 2.75h9v10.5h-9zM11.25 5.25h2.5v6.5a1.5 1.5 0 0 1-1.5 1.5M4.25 5.25h5M4.25 8h5M4.25 10.75h3" />',
        'book' => '<path d="M8 3.75C6.75 2.75 4.75 2.25 1.75 2.25v10c3 0 5 .5 6.25 1.5 1.25-1 3.25-1.5 6.25-1.5v-10c-3 0-5 .5-6.25 1.5zM8 3.75v10" />',
        'table' => '<rect x="1.75" y="2.75" width="12.5" height="10.5" rx="1.5" /><path d="M1.75 6.25h12.5M1.75 9.75h12.5M6 6.25v7" />',
        'link' => '<path d="M6.75 9.25a2.75 2.75 0 0 0 3.9 0l2.1-2.1a2.75 2.75 0 0 0-3.9-3.9l-.6.6M9.25 6.75a2.75 2.75 0 0 0-3.9 0l-2.1 2.1a2.75 2.75 0 0 0 3.9 3.9l.6-.6" />',
        'button' => '<rect x="1.75" y="4.75" width="12.5" height="6.5" rx="3.25" /><path d="M5.5 8h5" />',
        'calendar' => '<rect x="1.75" y="2.75" width="12.5" height="11" rx="1.5" /><path d="M1.75 6.25h12.5M5.25 1.25v3M10.75 1.25v3" />',
        'pin' => '<path d="M8 14.25s4.75-4.25 4.75-8a4.75 4.75 0 0 0-9.5 0c0 3.75 4.75 8 4.75 8z" /><circle cx="8" cy="6.25" r="1.75" />',
        'mail' => '<rect x="1.75" y="3.25" width="12.5" height="9.5" rx="1.5" /><path d="m2.25 4 5.75 4.75L13.75 4" />',
        'person' => '<circle cx="8" cy="5.25" r="2.75" /><path d="M2.75 14c0-2.9 2.35-4.75 5.25-4.75s5.25 1.85 5.25 4.75" />',
        'chat' => '<path d="M2.25 3.25h11.5v8h-6.5l-3.5 2.75v-2.75h-1.5z" />',
    ];

    public function __construct(public readonly ?string $name = null, public readonly ?string $file = null)
    {
        if (($name === null) === ($file === null)) {
            throw new InvalidArgumentException("#[Icon] names one of Mainstay's icons, #[Icon('grid')], or an SVG file, #[Icon(file: 'icons/hero.svg')].");
        }

        if ($name !== null && ! isset(self::SHAPES[$name])) {
            throw new InvalidArgumentException("#[Icon('{$name}')] is none of Mainstay's icons, which are ".implode(', ', array_keys(self::SHAPES)).'.');
        }
    }

    /* The icon sized as the admin's are, the file read each time it is
       drawn. */
    public function toHtml(): string
    {
        if ($this->name !== null) {
            return '<svg aria-hidden="true" viewBox="0 0 16 16" class="size-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'.self::SHAPES[$this->name].'</svg>';
        }

        $path = str_starts_with($this->file, '/') ? $this->file : resource_path($this->file);
        $svg = is_file($path) ? file_get_contents($path) : false;

        if ($svg === false || ! preg_match('/<svg\b.*<\/svg>/is', $svg)) {
            throw new InvalidArgumentException("#[Icon(file: '{$this->file}')] is not an SVG file: {$path}.");
        }

        return '<img alt="" class="size-4" src="data:image/svg+xml;base64,'.base64_encode($svg).'">';
    }
}
