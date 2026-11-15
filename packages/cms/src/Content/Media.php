<?php

namespace Mainstay\Content;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Mainstay\Mainstay;
use Mainstay\Media\Documents;
use Mainstay\Media\Size;

/*
 | An image or a document in the media library, as a read hands it out: its
 | alt text (an image) or title and description (a document) in the locale it
 | was read in, and the copies of an image the field it was read through
 | declares, by name.
 |
 | Every address is the `mainstay.media.file` route's, never a disk's. A
 | restricted file's is signed to expire, so a page that prints one prints a
 | link that works for a while and then does not.
 |
 | One that is in the trash, or gone, is still a Media -- `missing`, holding
 | its id and nothing else -- so a list of blocks written back while it is
 | away keeps the reference, and the image's return brings back every place
 | it was used. It prints nothing and links nowhere.
 */
final class Media
{
    public const PUBLIC = 'public';

    public const RESTRICTED = 'restricted';

    /* What a copy can be written in, by MIME type. */
    private const TYPES = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public readonly bool $missing;

    /* The block and the field it was read from, which the Content tab's
       editor marks it with. */
    private ?array $at = null;

    /**
     * @param  array{0: int, 1: int}|null  $focal  percentages across and down
     * @param  array<string, Size>  $sizes  by name, the field's
     * @param  list<string>  $formats  every copy's, the one an <img> shows last
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $alt = null,
        public readonly ?string $hash = null,
        public readonly ?string $type = null,
        public readonly ?string $name = null,
        public readonly ?array $focal = null,
        private readonly int $originalWidth = 0,
        private readonly int $originalHeight = 0,
        private readonly array $sizes = [],
        /* Who uploaded it, none for code on its own authority: what
           MediaPolicy asks. */
        public readonly ?int $ownerId = null,
        private readonly array $formats = [],
        public readonly ?int $bytes = null,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly string $visibility = self::PUBLIC,
    ) {
        $this->missing = $hash === null;
    }

    /* This image with the sizes of the field it is read through. */
    public function sized(array $sizes): self
    {
        return new self($this->id, $this->alt, $this->hash, $this->type, $this->name, $this->focal, $this->originalWidth, $this->originalHeight, $sizes, $this->ownerId, $this->formats, $this->bytes, $this->title, $this->description, $this->visibility);
    }

    /* Whether this is a document rather than an image: by its type, which a
       missing one has none of. */
    public function isDocument(): bool
    {
        return $this->type !== null && ! str_starts_with($this->type, 'image/');
    }

    /* The size as an editor reads it. */
    public function readableBytes(): string
    {
        $bytes = $this->bytes ?? 0;

        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    public function isRestricted(): bool
    {
        return $this->visibility === self::RESTRICTED;
    }

    /* This image as a block's field holds it. */
    public function at(string $block, string $field): self
    {
        $copy = clone $this;
        $copy->at = [$block, $field];

        return $copy;
    }

    /*
     | The copy's address, in the format an <img> shows unless another is
     | asked for. Null for an image that is missing, or a format this one
     | was not written in: the host's GD could not write it, or null for
     | a file or image in the trash or gone. A document has
     | one file and no size to name: its address is the one that downloads it.
     */
    public function url(?string $size = null, ?string $format = null): ?string
    {
        /* Read through a File field, or any field, a missing one has no
           type to tell a document from an image by, and prints nothing as
           an image's does. */
        if ($this->missing && ($size === null || $this->sizes === [])) {
            return null;
        }

        if ($this->isDocument()) {
            return $this->address($this->file());
        }

        if ($size === null) {
            throw new InvalidArgumentException("Image {$this->id} is printed at a size its field declares: url('card').");
        }

        if ($format !== null && ! isset(self::TYPES[$format])) {
            throw new InvalidArgumentException('Copies are written as '.implode(', ', array_keys(self::TYPES)).", not {$format}.");
        }

        $declared = $this->size($size);
        $format ??= $this->shown();

        return $this->missing || ! in_array($format, $this->formats, true) ? null : $this->address($this->copyFile($declared, $format));
    }

    /*
     | The address of a document, or of an image's copy at `$size` and
     | `$format`, signed to stop working at `$expires` -- an hour from now by
     | default -- whether the file is restricted or not: what a link sent
     | by mail is. Null for what url() gives null for.
     */
    public function temporaryUrl(?string $size = null, ?string $format = null, ?DateTimeInterface $expires = null): ?string
    {
        $file = $this->isDocument() ? $this->file() : ($this->url($size, $format) === null ? null : $this->copyFile($this->size($size), $format ?? $this->shown()));

        return $file === null ? null : $this->signed($file, $expires);
    }

    public function width(string $size): ?int
    {
        return $this->missing ? null : $this->size($size)->output($this->originalWidth, $this->originalHeight, $this->focal)[0];
    }

    public function height(string $size): ?int
    {
        return $this->missing ? null : $this->size($size)->output($this->originalWidth, $this->originalHeight, $this->focal)[1];
    }

    /*
     | The copy as a `<picture>`: a source for each better format it was
     | written in, AVIF before WebP, and the last as the `<img>`, with alt and
     | real dimensions on it. Each offers the size's fractions beside it in a
     | `srcset`; a lazy image is sized by the width it is laid out at, any
     | other as wide as the screen unless the attributes give `sizes`. Lazy
     | unless the attributes say otherwise, and nothing at all for an image
     | that is missing. A block's, drawn for the Content tab's editor, is
     | marked with the block's id and the field's name, so it is picked and
     | edited on the page.
     */
    public function picture(string $size, array $attributes = []): HtmlString
    {
        if ($this->missing) {
            if ($this->sizes !== []) {
                $this->size($size);
            }

            return new HtmlString('');
        }

        $srcset = $this->shown() === null ? null : $this->srcset($size, $this->shown());
        $attributes = [
            'src' => $this->url($size),
            ...($srcset === null ? [] : ['srcset' => $srcset]),
            'alt' => $this->alt ?? '',
            'width' => $this->width($size),
            'height' => $this->height($size),
            'loading' => 'lazy',
            'decoding' => 'async',
            ...$attributes,
        ];

        if (isset($attributes['srcset'])) {
            $attributes['sizes'] ??= $attributes['loading'] === 'lazy' ? 'auto, 100vw' : '100vw';
        }

        $sources = array_map(fn (string $format) => sprintf(
            '<source type="%s" srcset="%s"%s>',
            self::TYPES[$format],
            e($this->srcset($size, $format) ?? $this->url($size, $format)),
            isset($attributes['sizes']) ? ' sizes="'.e($attributes['sizes']).'"' : '',
        ), array_slice($this->formats, 0, -1));

        return new HtmlString(sprintf(
            '<picture%s>%s<img %s></picture>',
            $this->at !== null && app(Mainstay::class)->marking() ? sprintf(' data-mainstay-image="%s" data-mainstay-item="%s"', e($this->at[1]), e($this->at[0])) : '',
            implode('', $sources),
            implode(' ', array_map(fn (string $name, mixed $value) => $name.'="'.e((string) $value).'"', array_keys($attributes), $attributes)),
        ));
    }

    /* The image as the API hands it out: what picture() prints, for every
       size its field declares and each size's fractions after it, named
       `card@0.5` and `card@0.25`, so a reader draws it with no id to look
       up. `src` is the <img>'s; AVIF and WebP are null where they were not
       written. */
    public function toArray(): array
    {
        $sizes = [];

        foreach ($this->sizes as $name => $declared) {
            $sizes[$name] = $this->copy($declared);

            foreach ($declared->fractions() as $fraction => $size) {
                $sizes["{$name}@{$fraction}"] = $this->copy($size);
            }
        }

        return ['id' => $this->id, 'alt' => $this->alt, 'focal' => $this->focal, 'sizes' => (object) $sizes];
    }

    /* The document as the API hands it out, with the address that downloads
       it: signed to expire where it is restricted. */
    public function fileArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'bytes' => $this->bytes,
            'title' => $this->title,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'url' => $this->url(),
        ];
    }

    /* The format an `<img>` shows: the last written, the most widely read. */
    private function shown(): ?string
    {
        return $this->formats === [] ? null : $this->formats[array_key_last($this->formats)];
    }

    /* One copy as the API hands it out, every value null for an image
       that is missing. */
    private function copy(Size $size): array
    {
        $url = fn (?string $format) => ! $this->missing && in_array($format, $this->formats, true) ? $this->address($this->copyFile($size, $format)) : null;
        [$width, $height] = $this->missing ? [null, null] : $size->output($this->originalWidth, $this->originalHeight, $this->focal);

        return ['src' => $url($this->shown()), 'avif' => $url('avif'), 'webp' => $url('webp'), 'width' => $width, 'height' => $height];
    }

    private function address(string $file): string
    {
        return $this->isRestricted() ? $this->signed($file) : route('mainstay.media.file', $file);
    }

    private function signed(string $file, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute('mainstay.media.signed', $expires ?? now()->addMinutes((int) config('mainstay.media.url_minutes', 60)), ['file' => $file]);
    }

    /* The name a document is kept and served under. */
    private function file(): string
    {
        return "{$this->hash}.".Documents::extension($this->type);
    }

    private function copyFile(Size $size, string $format): string
    {
        return basename($size->path($this->hash, $this->focal, $format));
    }

    /* A size and its fractions in one format, each named by the width it
       was written at. Null when they were all written at one width -- an
       original no wider than the smallest -- which a plain `src` says. */
    private function srcset(string $size, string $format): ?string
    {
        $declared = $this->size($size);
        $candidates = [];

        foreach ([...array_reverse($declared->fractions()), $declared] as $copy) {
            $width = $copy->output($this->originalWidth, $this->originalHeight, $this->focal)[0];
            $candidates[$width] ??= $this->address($this->copyFile($copy, $format))." {$width}w";
        }

        return count($candidates) > 1 ? implode(', ', $candidates) : null;
    }

    /* A size the field declares. An image read through no field -- from
       find() or upload() -- has none to name. */
    private function size(string $size): Size
    {
        return $this->sizes[$size] ?? throw new InvalidArgumentException($this->sizes === []
            ? "Image {$this->id} was not read through a field, so it has no sizes to name. Read it through the entry that holds it."
            : "Image {$this->id} has no size called {$size}; its field declares ".implode(', ', array_keys($this->sizes)).'.');
    }
}
