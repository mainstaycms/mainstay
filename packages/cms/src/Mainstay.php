<?php

namespace Mainstay;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Mainstay\Admin\EntryTab;
use Mainstay\Admin\Widget;
use Mainstay\Admin\Widgets\AtAGlance;
use Mainstay\Auth\Accounts;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Tokens;
use Mainstay\Auth\User;
use Mainstay\Content\Block;
use Mainstay\Content\ContentType;
use Mainstay\Content\Entry;
use Mainstay\Content\GlobalSet;
use Mainstay\Content\HoldsPage;
use Mainstay\Content\Taxonomy;
use Mainstay\Content\Template;
use Mainstay\Database\ContentStore;
use Mainstay\Database\Drafts;
use Mainstay\Database\Paths;
use Mainstay\Database\Revisions;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Content;
use Mainstay\Fields\Field;
use Mainstay\Fields\Internal;
use Mainstay\Fields\Terms;
use Mainstay\Fields\Text;
use Mainstay\Fields\Textarea;
use Mainstay\Media\Library;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

class Mainstay
{
    public const VERSION = '0.1.0';

    /* The first segments of the admin's own screens, which a type's handle,
       naming its screens' segment, cannot be. */
    private const ADMIN = ['media', 'login', 'logout', 'users', 'roles', 'account', 'dashboard'];

    /* View namespaces a plugin's handle cannot be: Mainstay's own, and
       Laravel's mail and error pages, which it adds only as a mail or an
       error renders and then replaces. Any other already registered is
       refused as it is found. */
    private const NAMESPACES = ['mainstay', 'mail', 'errors'];

    /** @var list<class-string<Widget>> what the dashboard has of its own */
    private const CORE_WIDGETS = [AtAGlance::class];

    /* One or more lowercase segments, each after its slash: a locale's
       prefix. A path is held to the same by whatever serves one, so MySQL
       and SQL Server, which fold case in a comparison, match what SQLite
       and Postgres do. */
    private const PATH = '(?:/[a-z0-9]+(?:-[a-z0-9]+)*)+';

    /** @var array<string, class-string<ContentType>> */
    private array $types = [];

    /** @var array<string, Plugin> by handle */
    private array $plugins = [];

    /** @var array<class-string, array<string, Field>> */
    private array $fields = [];

    /** @var array<string, class-string<Block>> by handle */
    private array $blocks = [];

    /** @var array<class-string<Block>, Blocks|null> each block's inner blocks */
    private array $inner = [];

    /** @var array{0: mixed, 1: array<string, array{origin: string, host: ?string, prefix: string}>}|null */
    private ?array $locales = null;

    /** @var list<class-string<Widget>> the host's, in the order it named them */
    private array $widgets = [];

    /** @var array{stylesheets: list<string>, class: string}|null */
    private ?array $editorStyle = null;

    /* Whether blocks are being drawn for the Content tab's editor, where
       @field marks the text it prints. */
    private bool $marking = false;

    public function version(): string
    {
        return static::VERSION;
    }

    /*
     | The host names its content types in a service provider. A directory scan
     | and a config array were the alternatives; registration wins on being the
     | same gesture the onboarding steps are added with, rather than a third
     | mechanism to learn.
     |
     | The cost, paid knowingly: the set only exists once the application has
     | booted, so schema sync and drift are artisan commands rather than
     | anything that could read the filesystem alone. They were always going to
     | be artisan commands.
     */
    public function types(array $types): void
    {
        foreach ($types as $type) {
            if (! is_string($type) || ! is_subclass_of($type, ContentType::class)) {
                throw new InvalidArgumentException(sprintf(
                    '%s is not a Mainstay content type. Extend Entry, GlobalSet or Taxonomy.',
                    is_string($type) ? $type : get_debug_type($type),
                ));
            }

            /* Its own branch, because it is its own mistake: a base class does
               extend Entry, and telling its author to do that is an answer to
               a question they did not ask. */
            if ((new ReflectionClass($type))->isAbstract()) {
                throw new InvalidArgumentException("{$type} is abstract, so there is no content to register. Register the types that extend it.");
            }

            $type = $this->canonical($type);
            $handle = $type::handle();

            /*
             | Two types answering to one handle is two classes claiming one
             | table, one URL segment and one set of capabilities. Caught at
             | registration, where the second class is named in the message,
             | rather than in phase 2 where it looks like a schema bug.
             */
            if (($existing = $this->types[$handle] ?? $type) !== $type) {
                throw new InvalidArgumentException("Two content types are called \"{$handle}\": {$existing} and {$type}.");
            }

            /* Nor two claiming one capability: an entry type Entry would
               derive `edit_entries`, which already means every entry type. */
            if (! isset($this->types[$handle])) {
                $claimed = array_fill_keys(Capabilities::shared(), 'Mainstay keeps for itself');

                foreach ($this->types as $other) {
                    $claimed = [...$claimed, ...array_fill_keys(Capabilities::of($other), "{$other} already derives")];
                }

                foreach (Capabilities::of($type) as $name) {
                    if (isset($claimed[$name])) {
                        throw new InvalidArgumentException("{$type} would derive the capability \"{$name}\", which {$claimed[$name]}. Rename the class.");
                    }
                }
            }

            /* Nor one claiming a segment the admin draws a screen of its own
               at: its list would be the library's, the login's or the accounts'
               address. */
            if (in_array($handle, self::ADMIN, true)) {
                throw new InvalidArgumentException("{$type} would be called \"{$handle}\", which the admin uses for a screen of its own. Rename the class.");
            }

            /* Nor a plugin's, whose screens answer at that segment first. */
            if (isset($this->plugins[$handle])) {
                throw new InvalidArgumentException("{$type} would be called \"{$handle}\", which the plugin ".$this->plugins[$handle]::class.' is called. Rename the class.');
            }

            $this->types[$handle] = $type;
        }
    }

    /*
     | Every capability there is, computed from the registered types and never
     | stored: each type's own, and the forms covering every type, the media
     | library and the accounts.
     |
     | @return list<string>
     */
    public function capabilities(): array
    {
        return [...array_merge(...array_map(Capabilities::of(...), array_values($this->types))), ...Capabilities::shared(), ...array_values(Capabilities::plugins())];
    }

    /** @return array<string, class-string<ContentType>> */
    public function registered(): array
    {
        return $this->types;
    }

    /*
     | The plugins `config('mainstay.plugins')` names, made as Mainstay boots,
     | before the admin's routes load to route their screens, and each one's
     | types registered as the host's are. A handle is the segment a plugin's
     | screens answer at, the namespace its views are in and its capability's
     | noun, so it is checked here, where the class can be named: none of the
     | admin's screens, no view namespace already there, no type's and no other
     | plugin's.
     */
    public function loadPlugins(array $plugins): void
    {
        foreach ($plugins as $plugin) {
            if (! is_string($plugin) || ! is_subclass_of($plugin, Plugin::class)) {
                throw new InvalidArgumentException(sprintf(
                    '%s is not a Mainstay plugin. Extend Mainstay\Plugin.',
                    is_string($plugin) ? $plugin : get_debug_type($plugin),
                ));
            }

            if ((new ReflectionClass($plugin))->isAbstract()) {
                throw new InvalidArgumentException("{$plugin} is abstract, so there is no plugin to load. Name the class that extends it.");
            }

            $handle = $plugin::handle();

            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $handle) !== 1) {
                throw new InvalidArgumentException("{$plugin} is called \"{$handle}\", and a plugin's handle is lowercase letters, digits and underscores, starting with a letter.");
            }

            if (isset($this->plugins[$handle])) {
                throw new InvalidArgumentException("Two plugins are called \"{$handle}\": ".$this->plugins[$handle]::class." and {$plugin}.");
            }

            if (in_array($handle, [...self::ADMIN, ...self::NAMESPACES], true) || array_key_exists($handle, app('view')->getFinder()->getHints())) {
                throw new InvalidArgumentException("{$plugin} is called \"{$handle}\", which the admin or a view namespace uses already. Return another handle().");
            }

            if (isset($this->types[$handle])) {
                throw new InvalidArgumentException("{$plugin} is called \"{$handle}\", which the content type {$this->types[$handle]} is called. Return another handle().");
            }

            $this->plugins[$handle] = app($plugin);
        }

        foreach ($this->plugins as $loaded) {
            $this->types($loaded->types());
        }
    }

    /** @return array<string, Plugin> by handle */
    public function plugins(): array
    {
        return $this->plugins;
    }

    /** @return list<EntryTab> every plugin's tabs for an entry's form of `$type` */
    public function entryTabs(string $type): array
    {
        $tabs = [];

        foreach ($this->plugins as $plugin) {
            foreach ($plugin->entryTabs() as $tab) {
                $tab = is_string($tab) ? app($tab) : $tab;

                if ($tab->applies($type)) {
                    $tabs[] = $tab;
                }
            }
        }

        return $tabs;
    }

    /*
     | The host's own dashboard widgets, named in its service provider as its
     | types are: each a class extending Widget, with a handle no other widget
     | -- core's, a plugin's or the host's -- has. Those are checked together
     | wherever the plugins are loaded by then, and again as they are read.
     |
     | @param  list<class-string<Widget>>  $widgets
     */
    public function widgets(array $widgets): void
    {
        $host = [...$this->widgets, ...$widgets];
        $this->widgetClasses($host);

        $this->widgets = $host;
    }

    /*
     | Every widget the user may have, in the order they are drawn: the
     | core's, then each plugin's in plugin order, then the host's. Made by
     | the container when asked, so a plugin's is not made while it loads.
     |
     | @return list<Widget>
     */
    public function dashboardWidgets(User $user): array
    {
        $widgets = array_map(fn (string $class) => app($class), array_values($this->widgetClasses($this->widgets)));

        return array_values(array_filter($widgets, fn (Widget $widget) => $widget->visibleTo($user)));
    }

    /*
     | The classes of every widget by handle, checked together: a handle is
     | the key of what a user has stored, so no two widgets share one.
     |
     | @param  list<mixed>  $host
     | @return array<string, class-string<Widget>>
     */
    private function widgetClasses(array $host): array
    {
        $classes = [...self::CORE_WIDGETS, ...array_merge(...array_map(fn (Plugin $plugin) => $plugin->widgets(), array_values($this->plugins))), ...$host];
        $byHandle = [];

        foreach ($classes as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Widget::class)) {
                throw new InvalidArgumentException(sprintf(
                    '%s is not a dashboard widget. Extend Mainstay\Admin\Widget.',
                    is_string($class) ? $class : get_debug_type($class),
                ));
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                throw new InvalidArgumentException("{$class} is abstract, so there is no widget to show. Name the class that extends it.");
            }

            $handle = $class::handle();

            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $handle) !== 1) {
                throw new InvalidArgumentException("{$class} is called \"{$handle}\", and a widget's handle is lowercase letters, digits and underscores, starting with a letter.");
            }

            if (isset($byHandle[$handle])) {
                throw new InvalidArgumentException("Two dashboard widgets are called \"{$handle}\": {$byHandle[$handle]} and {$class}. Return another handle() from one of them.");
            }

            $byHandle[$handle] = $class;
        }

        return $byHandle;
    }

    /** @return list<string> the scripts and stylesheets of every plugin, as public paths */
    public function assets(): array
    {
        $assets = [];

        foreach ($this->plugins as $handle => $plugin) {
            foreach ($plugin->assets() as $asset) {
                $assets[] = "vendor/{$handle}/{$asset}";
            }
        }

        return $assets;
    }

    /*
     | The site's stylesheets and the class its text sits in, which every rich
     | text editor in the admin draws its text with, as a WordPress theme's
     | add_editor_style: named in the site's service provider, so a path can
     | come from code, a hashed build file's among them.
     */
    public function editorStyles(array $stylesheets, string $class = ''): void
    {
        if (! array_is_list($stylesheets) || array_filter($stylesheets, fn (mixed $path) => ! is_string($path) || trim($path) === '') !== []) {
            throw new InvalidArgumentException("The editor's styles are a list of stylesheet addresses: Mainstay::editorStyles(['/css/site.css'], class: 'prose').");
        }

        $this->editorStyle = ['stylesheets' => $stylesheets, 'class' => trim($class)];
    }

    /** @return array{stylesheets: list<string>, class: string}|null what editorStyles() named, or null to draw in the admin's own typography */
    public function editorStyle(): ?array
    {
        return $this->editorStyle;
    }

    /*
     | `@field($block, 'headline')`: the field's text escaped, as `{{
     | $block->headline }}` prints it, and for the Content tab's editor in a
     | span marked with the block's id and the field's name, which the editor
     | makes typeable -- showing the field's label while it is empty. A Text or
     | a Textarea field only: any other is edited in the block's settings.
     */
    public function field(Block $item, string $name): HtmlString
    {
        $field = $this->fields($item::class)[$name] ?? null;

        if (! $field instanceof Text && ! $field instanceof Textarea) {
            throw new InvalidArgumentException(sprintf(
                "@field prints a Text or a Textarea field, and %s::\$%s is %s. Print it as the view would, and it is edited in the block's settings.",
                $item::class,
                $name,
                $field === null ? 'not a field' : (preg_match('/^[AEIOU]/', class_basename($field)) ? 'an ' : 'a ').class_basename($field).' field',
            ));
        }

        $value = e((string) ($item->{$name} ?? ''));

        if (! $this->marking) {
            return new HtmlString($value);
        }

        return new HtmlString(sprintf(
            '<span data-mainstay-field="%s" data-mainstay-item="%s" data-mainstay-label="%s"%s>%s</span>',
            e($name),
            e($item->id ?? ''),
            e($field->label()),
            $field instanceof Textarea ? ' data-mainstay-lines' : '',
            $value,
        ));
    }

    /*
     | `@inner($block)`: the blocks inside a block, each drawn by its view,
     | and the text among them, in their order. For the Content tab's editor
     | an empty <template> in their place, where the editor puts them, each
     | block drawn on its own: the element it sits in holds what is inside
     | the block and nothing else.
     */
    public function inner(Block $block): HtmlString
    {
        return new HtmlString($this->marking ? '<template data-mainstay-inner></template>' : $block->innerContent()->toHtml());
    }

    /* Whether what is drawn now is for the editor: an image marks itself. */
    public function marking(): bool
    {
        return $this->marking;
    }

    /*
     | The blocks a block holds inside it, as its class names them with
     | #[Blocks([...])], or null for one that holds none. Checked the first
     | time it is asked for, with the block and every block it names.
     */
    public function innerBlocks(string $block): ?Blocks
    {
        $block = $this->canonical($block);

        if (array_key_exists($block, $this->inner)) {
            return $this->inner[$block];
        }

        $this->fields($block);

        $attributes = (new ReflectionClass($block))->getAttributes(Blocks::class);
        $this->listing($block, $attributes);

        /* Kept before it is checked, so a block holding its own kind asks
           for this one rather than reading it again. */
        $this->inner[$block] = $attributes === [] ? null : $attributes[0]->newInstance();

        return $this->inner[$block]?->check($this);
    }

    /* What `$draw` gives with every @field it prints marked for the editor. */
    public function marked(callable $draw): mixed
    {
        [$was, $this->marking] = [$this->marking, true];

        try {
            return $draw();
        } finally {
            $this->marking = $was;
        }
    }

    /**
     | The field list: what every phase after this one reads, keyed by property
     | name and in declaration order. Nothing downstream reflects the class
     | again -- the schema plan, the form, the validator and the JSON Schema all
     | see this one list, so they cannot disagree about what a type holds.
     |
     | @return array<string, Field>
     */
    public function fields(string $type): array
    {
        /* Asked once per block read, and by then usually spelled as it was
           reflected, so a known name skips the reflection canonical() does. */
        if (isset($this->fields[$type])) {
            return $this->fields[$type];
        }

        $type = $this->canonical($type);

        return $this->fields[$type] ??= $this->reflect($type);
    }

    /*
     | The type as JSON Schema, which phase 13 serves from a discovery endpoint
     | and writes out as a `.d.ts` -- from here rather than derived twice.
     |
     | It describes what a reader is handed, so an internal field is in it
     | only for a reader who may see those, `$internal`. For any other it is
     | neither required nor named: a payload the query layer hands such a
     | reader never holds it, and would fail a schema that required it beside
     | `additionalProperties: false`, and the schema would tell every consumer
     | the field is there.
     */
    public function schema(string $type, bool $internal = false): array
    {
        $fields = array_filter($this->fields($type), fn (Field $field) => $internal || ! $field->internal);

        return [
            'type' => 'object',
            'title' => class_basename($type),
            /* An empty PHP array encodes as `[]`, and JSON Schema wants an
               object there -- ajv in strict mode and the .d.ts generator both
               refuse the array. */
            'properties' => array_map(fn (Field $field) => $field->schema(), $fields) ?: new stdClass,
            /*
             | Every key, because JSON Schema's `required` asks whether the key
             | is present in the object, not whether an editor has to fill the
             | box. Nullability already carries the second question, so
             | filtering by isRequired() here states it twice and generates
             | `summary?: string | null` for a key the payload always has.
             */
            'required' => array_keys($fields),
            'additionalProperties' => false,
        ];
    }

    /*
     | The query layer, reached from here so a template writes
     | `Mainstay::find(Article::class, where: [...])`. The arguments are
     | ContentStore's, passed through by name.
     */
    public function find(string $type, mixed ...$arguments): Collection
    {
        return $this->store()->find($type, ...$arguments);
    }

    public function findById(string $type, mixed ...$arguments): ?Entry
    {
        return $this->store()->findById($type, ...$arguments);
    }

    public function findByUri(string $uri, mixed ...$arguments): ?Entry
    {
        return $this->store()->findByUri($uri, ...$arguments);
    }

    public function paginate(string $type, mixed ...$arguments): LengthAwarePaginator
    {
        return $this->store()->paginate($type, ...$arguments);
    }

    public function create(string $type, mixed ...$arguments): Entry
    {
        return $this->store()->create($type, ...$arguments);
    }

    public function update(string $type, mixed ...$arguments): Entry
    {
        return $this->store()->update($type, ...$arguments);
    }

    public function delete(string $type, mixed ...$arguments): void
    {
        $this->store()->delete($type, ...$arguments);
    }

    /* A global, `Mainstay::global(Footer::class)`: this site's one, in the
       locale read, or null where it has not been written in it. */
    public function global(string $type, mixed ...$arguments): ?GlobalSet
    {
        return $this->store()->global($type, ...$arguments);
    }

    public function saveGlobal(string $type, mixed ...$arguments): GlobalSet
    {
        return $this->store()->saveGlobal($type, ...$arguments);
    }

    /* Out of the trash, with its paths back, by locale. */
    public function restore(string $type, mixed ...$arguments): array
    {
        return $this->store()->restore($type, ...$arguments);
    }

    /* Out of the trash for good. */
    public function destroy(string $type, mixed ...$arguments): void
    {
        $this->store()->destroy($type, ...$arguments);
    }

    /* `Mainstay::assign(Article::class, 5, owner: 3)` gives an entry to
       another account. */
    public function assign(string $type, mixed ...$arguments): void
    {
        $this->store()->assign($type, ...$arguments);
    }

    /* The entry served at `/`, as [type, id], or null. */
    public function frontPage(): ?array
    {
        return $this->store()->frontPage();
    }

    /* `Mainstay::setFrontPage(Page::class, 5)`, or null for none. */
    public function setFrontPage(?string $type, mixed ...$arguments): void
    {
        $this->store()->setFrontPage($type, ...$arguments);
    }

    /* An entry, or a global edited as a page, as it would read with unsaved
       changes over it, written nowhere: what the admin's Content tab draws. */
    /** @return Entry|(GlobalSet&HoldsPage) */
    public function preview(string $type, mixed ...$arguments): ContentType
    {
        return $this->store()->preview($type, ...$arguments);
    }

    /* Drafts and revisions, beside the reads and writes of what is live:
       `Mainstay::drafts()->save(...)`. */
    public function drafts(): Drafts
    {
        return new Drafts($this->store());
    }

    public function revisions(): Revisions
    {
        return new Revisions($this->store());
    }

    /* Accounts and roles, written as the admin writes them:
       `Mainstay::accounts()->create(...)`. */
    public function accounts(): Accounts
    {
        return new Accounts($this);
    }

    /* The tokens programs sign in to the API with: `Mainstay::tokens()
       ->issue(...)`. */
    public function tokens(): Tokens
    {
        return new Tokens($this);
    }

    private function store(): ContentStore
    {
        return new ContentStore($this, app(Paths::class));
    }

    /* The media library: `Mainstay::media()->upload(...)`, beside the
       entries rather than among them, since an image is no type's. */
    public function media(): Library
    {
        return new Library($this);
    }

    /*
     | The view an entry renders with: its own, then its type's #[Template],
     | then the type's handle. The first one given wins, not the first one
     | there is -- a view named and missing is an error, rather than a quiet
     | step down to one nobody chose.
     */
    public function template(Entry $entry): string
    {
        return (new ReflectionProperty(Entry::class, 'template'))->getValue($entry) ?? $this->templates($entry::class)[0];
    }

    /*
     | The views a type's entries may render with, the one they render with
     | first: its #[Template]'s, or its handle alone.
     |
     | @return list<string>
     */
    public function templates(string $type): array
    {
        $attributes = (new ReflectionClass($type))->getAttributes(Template::class);

        return $attributes === [] ? [$type::handle()] : $attributes[0]->newInstance()->views;
    }

    /*
     | The content locales, the default first, each with where it is served:
     | a path prefix, `'/nl'`, a host, `'https://example.nl'`, or both. `/` is
     | the unprefixed one on every host.
     |
     | Either every locale names a host or none does. One without a host
     | answers on all of them, so beside one with a host it would link to
     | itself on whichever host the page it is linked from is on.
     |
     | @return array<string, array{origin: string, host: ?string, prefix: string}>
     */
    public function locales(): array
    {
        $config = config('mainstay.locales');

        /* Parsed once for the config it was parsed from: nearly every read,
           write and link asks. */
        if ($this->locales !== null && $this->locales[0] === $config) {
            return $this->locales[1];
        }

        /* A list reads as locales called 0 and 1, served at `en` and `nl`. */
        if (! is_array($config) || $config === [] || array_is_list($config)) {
            throw new InvalidArgumentException("mainstay.locales maps each content locale to where it is served, the default first: ['en' => '/', 'nl' => '/nl'], or ['en' => 'https://example.com', 'nl' => 'https://example.nl'].");
        }

        $locales = $bases = [];

        foreach ($config as $locale => $base) {
            /* The prefix held to what a path is, lowercase, for the reason a
               path is: MySQL and SQL Server would match `/NL` to it and the
               others would not. */
            if (! is_string($locale) || ! is_string($base) || ! preg_match('#\A(https?://([A-Za-z0-9.-]+)(?::\d+)?)?((?:'.self::PATH.')?)/?\z#', $base, $parts)) {
                throw new InvalidArgumentException(sprintf(
                    'mainstay.locales serves %s at %s, which is neither a path of lowercase segments, like /nl, nor a URL, like https://example.nl.',
                    $locale,
                    is_string($base) ? "\"{$base}\"" : get_debug_type($base),
                ));
            }

            $host = $parts[2] === '' ? null : strtolower($parts[2]);

            if (($other = $bases[$host.$parts[3]] ?? null) !== null) {
                throw new InvalidArgumentException("mainstay.locales serves {$other} and {$locale} at the same base, so a request cannot tell them apart.");
            }

            $bases[$host.$parts[3]] = $locale;
            $locales[$locale] = ['origin' => $parts[1], 'host' => $host, 'prefix' => $parts[3]];
        }

        if (count(array_unique(array_map(fn (array $base) => $base['host'] === null, $locales))) > 1) {
            throw new InvalidArgumentException('mainstay.locales gives some locales a host and not others. One without a host answers on every host, so give each locale a host or none of them.');
        }

        return ($this->locales = [$config, $locales])[1];
    }

    /* `\App\Article` and `App\Article` are one class and two cache keys -- and
       two field lists that could disagree, which is the one thing fields()
       promises cannot happen. A type registers its handle from here for the
       same reason. Case is left alone, and cannot usefully be otherwise: PHP's
       class table is case-insensitive but PSR-4 is not, so `app\article` throws
       until something has loaded the class under its real name and resolves
       afterwards. Not a spelling to write. */
    private function canonical(string $type): string
    {
        /* fields() and schema() are the two entry points a host reaches with a
           class name it typed, so a typo answers here in the same currency
           types() pays in -- and not as the ReflectionException that no caller
           guarding registration is catching for. */
        if (! class_exists($type)) {
            throw new InvalidArgumentException("{$type} is not a class Mainstay can reflect.");
        }

        return (new ReflectionClass($type))->getName();
    }

    private function reflect(string $type): array
    {
        /*
         | A block's handle names the view that draws it, whichever list names
         | it, so two blocks of one handle in two lists would draw with one
         | view -- one of them with markup written for the other's properties.
         | Recorded before its fields are read; and every block a list names is
         | read here, since a list's check() reads each one.
         */
        if (is_subclass_of($type, Block::class)) {
            if (($read = $this->blocks[$type::handle()] ?? $type) !== $type) {
                throw new InvalidArgumentException("Two blocks are called \"{$type::handle()}\": {$read} and {$type}, and a block's handle names the view that draws it. Give one of them a handle() of its own.");
            }

            $this->blocks[$type::handle()] = $type;
        }

        $fields = [];
        $reflection = new ReflectionClass($type);

        /*
         | Base classes first. getProperties() answers with a class's own
         | properties before the ones it inherits, which would file a shared
         | base's title after everything the child adds -- an order the
         | declaration does not show anywhere, on a list phase 10 draws the form
         | from. A property a child redeclares keeps the base's position and
         | takes the child's attribute -- assignment by name overwrites in
         | place, which is also why a trait's property, seen again on the class
         | that uses it, keeps the slot the trait gave it.
         */
        foreach ($this->hierarchy($type) as $class) {
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $attributes = $property->getAttributes(Field::class, ReflectionAttribute::IS_INSTANCEOF);

                if ($property->getAttributes(Blocks::class) !== []) {
                    throw new InvalidArgumentException("{$type}::\${$property->getName()} is marked #[Blocks], which names the blocks a class holds: put it on the class, and a block prints the blocks it holds with @inner(\$block).");
                }

                /* A property without a field attribute is the type's own
                   business, not a field the admin should be drawing. */
                if ($attributes === []) {
                    /* Unless it says it is hiding something: a flag with no
                       field under it hides nothing, and reads as if it does.
                       A base's field widened here is one to hide. */
                    if ($property->getAttributes(Internal::class) !== [] && ! $this->fielded($class, $property->getName())) {
                        throw new InvalidArgumentException("{$type}::\${$property->getName()} is marked #[Internal] and carries no field attribute, so there is no field for it to hide. Put the field attribute beside it.");
                    }

                    continue;
                }

                /* All three of these are a declaration that reads as if it
                   works. Silently taking the first attribute, or silently
                   skipping a field because it is not public or not an
                   instance property, is a field an editor never sees and
                   nothing anywhere reports. */
                if (count($attributes) > 1) {
                    throw new InvalidArgumentException("{$type}::\${$property->getName()} has more than one field attribute on it.");
                }

                /*
                 | The declaration an entry actually holds. A class is read on
                 | its own here, so a base's `protected string $title` is the
                 | one these guards saw even where the child redeclares it
                 | public -- PHP allows the widening, and the field was refused
                 | for the mistake it fixes. The attribute stays the one this
                 | loop found, which is the child's where the child wrote one.
                 |
                 | A private declaration is left alone: a child's property of
                 | the same name is a second slot rather than the same one
                 | widened, and the field is on the slot nothing outside the
                 | base can write. Resolving by name would bind it to the
                 | other one, which is a silent wrong column where the guard
                 | below is a refusal naming the property.
                 */
                if (! $property->isPrivate() && $reflection->hasProperty($property->getName())) {
                    $property = $reflection->getProperty($property->getName());
                }

                if (! $property->isPublic()) {
                    throw new InvalidArgumentException("{$type}::\${$property->getName()} carries a field attribute but is not public.");
                }

                /* A field is a value an entry holds, and a static property is
                   one the class holds -- there is no row for it to be a
                   column of. */
                if ($property->isStatic()) {
                    throw new InvalidArgumentException("{$type}::\${$property->getName()} carries a field attribute but is static.");
                }

                $field = $attributes[0]->newInstance()->bind($property);

                $fields[$field->name] = $field;
            }
        }

        $this->terms($type, $fields);
        $this->content($type, $reflection, $fields);

        return $fields;
    }

    /*
     | An entry's blocks go in its content, the list its class names with
     | #[Blocks([...])]; a block's names the blocks it holds inside it. That
     | is all the attribute takes. A type with no page to edit a block on --
     | no #[Route], and not a type edited as a page -- holds text alone.
     | Content is Entry's, and the edit screen is built on it, so a type
     | redeclares it only as content. A global edited as a page declares it
     | too, and has no route for it to be a page of.
     */
    private function content(string $type, ReflectionClass $reflection, array $fields): void
    {
        $entry = is_subclass_of($type, Entry::class);
        $holdsPage = is_subclass_of($type, HoldsPage::class);
        $owns = $entry || $holdsPage;

        if ($holdsPage && is_subclass_of($type, Taxonomy::class)) {
            throw new InvalidArgumentException("{$type} is edited as a page and is a taxonomy: only a global or an entry type is.");
        }

        if ($holdsPage && ! $entry && ! is_subclass_of($type, GlobalSet::class)) {
            throw new InvalidArgumentException("{$type} is edited as a page and is not a global or an entry type.");
        }

        if ($holdsPage && app(Paths::class)->routed($type)) {
            throw new InvalidArgumentException("{$type} is edited as a page and has a #[Route]: a type has a page of its own or none.");
        }

        foreach ($fields as $name => $field) {
            if ($field instanceof Content && (! $owns || $name !== 'content')) {
                throw new InvalidArgumentException("{$type}::\${$name} is a content field, and content is an entry's own, its \$content.");
            }
        }

        if ($owns && ! ($fields['content'] ?? null) instanceof Content) {
            throw new InvalidArgumentException("{$type}::\$content is redeclared as another kind of field. The edit screen is built on an entry's content, so redeclare it with #[Content], as one content for every language is.");
        }

        $attributes = $reflection->getAttributes(Blocks::class);
        $page = $this->hasPage($type);

        if ($attributes !== [] && ! $owns && ! is_subclass_of($type, Block::class)) {
            throw new InvalidArgumentException("{$type} names blocks with #[Blocks], and only an entry type's content and a block hold them.");
        }

        $this->listing($type, $attributes);

        if ($attributes !== [] && $owns && ! $page) {
            throw new InvalidArgumentException("{$type} names blocks with #[Blocks] and has no #[Route]: with no page there is nowhere to edit a block on, so its content holds text alone.");
        }

        if ($owns) {
            $fields['content']->holds($attributes === [] ? null : $attributes[0]->newInstance()->check($this), $page);
        }
    }

    /*
     | Whether a type has a page to edit its content on: the address its
     | #[Route] gives an entry, or a global's being edited as one.
     */
    public function hasPage(string $type): bool
    {
        return is_subclass_of($type, HoldsPage::class) || app(Paths::class)->routed($type);
    }

    /** @param list<ReflectionAttribute> $attributes a class's #[Blocks], which names blocks, and on a block text, and nothing more */
    private function listing(string $type, array $attributes): void
    {
        $block = is_subclass_of($type, Block::class);

        if ($attributes !== [] && array_diff(array_keys($attributes[0]->getArguments()), $block ? [0, 1, 'of', 'text'] : [0, 'of']) !== []) {
            throw new InvalidArgumentException($block
                ? "{$type}'s #[Blocks] names the blocks it holds and whether it holds text, and nothing more: #[Blocks([Button::class], text: true)]."
                : "{$type}'s #[Blocks] names the blocks it holds and nothing more, its content holding text already: #[Blocks([Hero::class])].");
        }
    }

    /*
     | Terms on an entry only, one field per taxonomy. A term's page lists the
     | entries holding it, so a block's terms are ones it could not see and a
     | global's ones it has nothing to list; and the pivot says which entry a
     | row is for, not which of its fields.
     */
    private function terms(string $type, array $fields): void
    {
        $taxonomies = [];

        foreach ($fields as $name => $field) {
            if (! $field instanceof Terms) {
                continue;
            }

            if (! is_subclass_of($type, Entry::class)) {
                throw new InvalidArgumentException("{$type}::\${$name} is a terms field, and only an entry holds terms: a term's page lists the entries holding it. Point at the terms with a relation instead.");
            }

            if (($other = $taxonomies[$field->of] ?? null) !== null) {
                throw new InvalidArgumentException("{$type}::\${$other} and \${$name} both hold {$field->of}'s terms, and its pivot does not say which field a term is in. Keep them in one field.");
            }

            $taxonomies[$field->of] = $name;
        }
    }

    /* Whether a class this one extends carries a field attribute on the
       property of that name. */
    private function fielded(ReflectionClass $class, string $name): bool
    {
        for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if ($parent->hasProperty($name) && $parent->getProperty($name)->getAttributes(Field::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                return true;
            }
        }

        return false;
    }

    /*
     | Root-first, and each class's traits ahead of the class itself: a trait is
     | a horizontal base, so `use HasSeo` written at the top of a body should
     | put its fields at the top of the form, the same way a parent's lead. PHP
     | files trait properties after the ones the class declares itself, which is
     | an order the declaration shows nowhere.
     |
     | One level of traits. A trait using a trait sorts by PHP's order until
     | something actually declares fields that way.
     |
     | @return list<ReflectionClass>
     */
    private function hierarchy(string $type): array
    {
        $classes = [];

        for ($class = new ReflectionClass($type); $class !== false; $class = $class->getParentClass()) {
            $classes = [...array_values($class->getTraits()), $class, ...$classes];
        }

        return $classes;
    }
}
