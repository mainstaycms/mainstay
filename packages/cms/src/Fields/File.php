<?php

namespace Mainstay\Fields;

use Attribute;
use Closure;
use InvalidArgumentException;
use Mainstay\Content\Media;
use Mainstay\Media\Documents;
use ReflectionProperty;

/*
 | A document from the media library -- a PDF, an Office file, a zip -- stored
 | as its plain id like an image is, and read back as a Media:
 |
 |     #[File(accept: ['pdf', 'docx'])]
 |     public ?Media $brochure;
 |
 | `accept` names the extensions the field takes, out of the library's closed
 | list; left out, every extension `mainstay.media.documents` holds. It is
 | what the picker offers and what a save is checked against. One file a
 | field: a list of files is a block with a File field in it, repeated.
 |
 | Resolved as an image is: one trashed or gone reads as a Media marked
 | missing that keeps its id, and writing it back writes the id.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class File extends Field
{
    /** @var list<string> */
    public readonly array $accept;

    public function __construct(
        ?bool $required = null,
        bool $localized = false,
        ?string $label = null,
        array $accept = [],
    ) {
        $this->accept = array_values(array_unique(array_map(fn (string $extension) => strtolower(ltrim($extension, '.')), $accept)));

        /* Refused where it is declared, which is where the mistake is. */
        Documents::allowed($this->accept === [] ? null : $this->accept);

        parent::__construct($required, $localized, $label);
    }

    public function bind(ReflectionProperty $property): static
    {
        $this->stores($property, [Media::class], 'a file field stores a Media');

        if (! ($property->getType()?->allowsNull() ?? true)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is not nullable, and a file field holds null for no file chosen. Declare it ?%s; required: true still asks every write for one.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                class_basename(Media::class),
            ));
        }

        return parent::bind($property);
    }

    /* The extensions this field takes now: the host may have narrowed the
       library since it was declared. */
    public function extensions(): array
    {
        return Documents::allowed($this->accept === [] ? null : $this->accept);
    }

    public function mediaTypes(): array
    {
        return Documents::types($this->extensions());
    }

    public function column(): array
    {
        return ['unsignedBigInteger'];
    }

    public function rules(): array
    {
        return [...parent::rules(), 'bail', 'integer', 'min:1', self::ID];
    }

    public function complete(mixed $value, bool $stored = false): mixed
    {
        return $value instanceof Media ? $value->id : $value;
    }

    public function references(mixed $value, string $at): array
    {
        $id = filter_var($value instanceof Media ? $value->id : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        /* The types the field takes ride along, for a save to check the id against. */
        return $id === false ? [] : [$at => [Media::class, $id, implode(',', $this->mediaTypes())]];
    }

    public function cast(mixed $value, array $loaded = []): mixed
    {
        if ($this->blank($value)) {
            return null;
        }

        if (($reference = $this->references($value, $this->name)) === []) {
            throw new InvalidArgumentException("{$this->name} holds something that is not a file's id.");
        }

        $id = reset($reference)[1];

        return $loaded[Media::class][$id] ?? new Media($id);
    }

    protected function to(mixed $value): mixed
    {
        return $value instanceof Media ? $value->id : (int) $value;
    }

    protected function json(): array
    {
        return ['type' => 'integer'];
    }

    /* The file with its name, size and the address that downloads it: signed
       to expire where it is restricted. The id alone for one that is
       missing. */
    public function payload(mixed $value, Closure $nested): mixed
    {
        return $value instanceof Media && ! $value->missing ? $value->fileArray() : $this->serialize($value);
    }

    public function payloadSchema(Closure $ref): array
    {
        return ['anyOf' => [$this->schema(), $ref(self::class)]];
    }
}
