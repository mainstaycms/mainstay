<p align="center">
  <img src="docs/images/logo.svg" width="72" height="72" alt="">
</p>

<h1 align="center">Mainstay</h1>

<p align="center">
  A CMS for Laravel. Content is modelled in PHP, stored in real columns, built in an admin with a
  page builder, and served by your own application.
</p>

<p align="center">
  <a href="https://github.com/mainstaycms/mainstay/actions/workflows/ci.yml"><img src="https://github.com/mainstaycms/mainstay/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-13-ff2d20" alt="Laravel 13">
  <a href="LICENSE"><img src="https://img.shields.io/badge/licence-MIT-346fa9" alt="MIT licence"></a>
</p>

<p align="center">
  <a href="https://mainstaycms.org">Website</a> ·
  <a href="#features">Features</a> ·
  <a href="#screenshots">Screenshots</a> ·
  <a href="#getting-started">Getting started</a> ·
  <a href="#roadmap">Roadmap</a> ·
  <a href="#working-on-mainstay">Contributing</a>
</p>

<p align="center">
  <img src="docs/images/page-builder.png" alt="The Content tab of Mainstay's admin: the site's front page drawn as the site draws it, its hero block selected, with a toolbar floating above it to drag, move, replace its image, open its settings, duplicate or delete it.">
</p>

> [!NOTE]
> Mainstay is early and not released yet. Everything below works today and is tested on SQLite,
> PostgreSQL, MySQL and SQL Server, but its API can still change between commits.

## Why Mainstay

The CMSs that model content well need Node on the server, and the ones that run on a plain PHP host
keep content as rows in a meta table. Mainstay takes the first kind's model -- content declared in
code, with real columns underneath -- and puts it inside a Laravel application, where Blade renders
it. Its content API opens to other front ends in the next phase.

Mainstay owns its tables inside your app. "Headless" describes the consumer, not the storage.

## Features

- **Content types are PHP classes.** Typed properties with attributes are the schema, the form and
  the type your editor autocompletes. Adding a field is a commit, not a click.
- **Real columns.** Every type gets a table of its own with real columns and constraints.
  `mainstay:sync` keeps a development database in step, and `mainstay:schema:check` tells you what
  production's migrations still need.
- **One query layer.** Templates, seeders, the admin and the API read and write through
  `Mainstay::find()`, `create()`, `update()` and `delete()`, and every call asks Laravel's Gate first.
- **A page builder, a plugin (`mainstay/page-builder`).** Every entry has content: text and the blocks
  its type allows, each block a PHP class drawn by its own Blade view, edited as rich text and raw data
  in Details. With the plugin, and `mainstay/site` to give the type a page, the admin's Content tab is
  the page as your site draws it, with text typed in place, blocks added, dragged and nested, and each
  block's fields and images edited in dialogs beside it.
- **Template parts, a plugin (`mainstay/parts`).** A navigation or a footer built from blocks, declared as
  `class Footer extends Part` with `#[Blocks([...])]`, stored once per site, localized, drafted and
  published as a global is, and edited on the Content tab, listed in the admin apart from the globals.
- **Served by Laravel.** With the `mainstay/site` plugin, an entry is served at the path its
  `#[Route]` builds, through a Blade template, after any route your app declares itself. No Node on
  the server. Core alone gives entries no path.
- **Translated per field.** A field says whether it is translated. A title gets one value per
  language, a date or a price stays one, and each language is served on its own host or path prefix.
- **Images written once.** An installation-wide media library with alt text in every language and a
  focal point. Every size a field declares is written as AVIF and WebP the moment a file arrives,
  or in the original's own format where PHP's GD writes neither.
- **Relations, tags and globals.** Entries point at each other through relations a read follows to a
  depth, tags are the entries of a taxonomy, and a global holds a site's menu and footer.
- **Drafts, history and the trash.** A change waits as a draft until it is published, every version a
  publish replaces can be restored, and a deleted entry waits in the trash.
- **Accounts and roles.** The admin has its own sign-in. Accounts are invited by mail, and a role's
  capabilities are a grid of the types you register.
- **Plugins.** A package adds types, blocks, field types, admin screens and listeners on content
  changes. Only the plugins your config names load, and their screens answer behind the admin's
  sign-in and a capability of their own.
- **An API, and an MCP server as a plugin (`mainstay/mcp`).** The same reads and writes over JSON and as tools a language model
  calls, behind tokens that never hold more than the account that made them. `/schema` describes
  every payload, and `mainstay:typescript` writes it out for a front end.
- **AI connectors, a plugin (`mainstay/ai`).** Every provider laravel/ai speaks -- OpenAI, Anthropic, Gemini, Mistral, Ollama
  and more -- on one admin screen: a key from the environment or saved there encrypted, a test, and
  the provider each kind of work goes to. Code prompts with laravel/ai and reaches what the site
  connected, and a plugin adds a provider of its own.

## Screenshots

<table>
  <tr>
    <td width="50%"><img src="docs/images/block-settings.png" alt="A feature grid block's settings in a dialog at the side: its heading, and the nine features inside it, each selectable, with Add feature below."></td>
    <td width="50%"><img src="docs/images/entry-form.png" alt="An article's Details tab: title, raw content, slug, summary, publish date, cover image and tags, with the address, author and history beside them."></td>
  </tr>
  <tr>
    <td align="center">Block settings, with the blocks inside it</td>
    <td align="center">An entry's details</td>
  </tr>
  <tr>
    <td><img src="docs/images/entry-list.png" alt="The list of articles, each with its status, author and last change, with a language switch, a status filter, search, the trash and New article."></td>
    <td><img src="docs/images/media-library.png" alt="The media library: every image in a grid with its file name, the trash and Upload."></td>
  </tr>
  <tr>
    <td align="center">A type's entries</td>
    <td align="center">The media library</td>
  </tr>
  <tr>
    <td><img src="docs/images/roles.png" alt="The editor role: a grid of capabilities -- edit, edit published, edit others', publish, delete, delete others' -- for every entry type and for each one, then taxonomies and globals."></td>
    <td><img src="docs/images/site.png" alt="The front page of the Mainstay website, which is built with Mainstay: a headline, a lead, a code sample of an Article content type and a screenshot of the admin."></td>
  </tr>
  <tr>
    <td align="center">A role's capabilities</td>
    <td align="center">A site built with it</td>
  </tr>
</table>

## Getting started

### Requirements

- PHP 8.3 or newer, with GD for images
- Laravel 13
- SQLite, PostgreSQL, MySQL or SQL Server

### Install

```bash
composer require mainstay/cms
php artisan vendor:publish --tag=mainstay-assets
```

The admin is then at `/admin`, behind a sign-in of its own, and the content API at `/api/mainstay`.
That is all the package serves. The Content tab is the `mainstay/page-builder` plugin: require it with
`mainstay/site`, name `Mainstay\PageBuilder\PageBuilderPlugin` in `config('mainstay.plugins')`, and
publish its script and stylesheet again with the same `mainstay-assets` tag, which copies them to
`public/vendor/page_builder`. Without it, Details edits the content. Public pages are the `mainstay/site` plugin: require it and name
`Mainstay\Site\SitePlugin` in `config('mainstay.plugins')`, and every other path an entry holds is
answered by its catch-all, after any route your app declares itself, behind the middleware of
`mainstay.site.middleware` (`web` unless you say otherwise). The two paths, their middleware, an
optional dedicated domain and where each content language is served are configurable:

```bash
php artisan vendor:publish --tag=mainstay-config
```

The page builder adds a *Pages in the builder* dashboard widget, the five entries changed last, and the site
plugin a *Site* one, the front page and each type's live entries with an address. Each is for whoever may edit
and read such a type, and no one else sees or lists it.

Template parts are the `mainstay/parts` plugin. A global holds settings, plain fields; a part is a piece of
the layout built from blocks, a navigation or a footer. Require it with `mainstay/page-builder` and
`mainstay/site`, name `Mainstay\Parts\PartsPlugin` in `config('mainstay.plugins')`, and declare parts like
any type. It has no files to publish.

```php
#[Blocks([Link::class, Social::class])]
class Footer extends Part {}
```

A part has content, as an entry does, and no address: it is stored once per site, localized, drafted and
published as a global is, so who may edit and publish it is what a global asks (`edit_footer`,
`publish_footer`). The admin lists it under Parts, apart from the globals, and its edit screen draws it alone
in the site's `Mainstay::editorStyles()`, its blocks edited in place. A view prints it, in the request's
locale, with `@part(Footer::class)`. It adds a *Template parts* dashboard widget, each part you may edit as
published, changed, draft or empty, for whoever may edit one.

Forms are the `mainstay/forms` plugin. Require it (it needs `mainstay/page-builder`), name
`Mainstay\Forms\FormsPlugin` in `config('mainstay.plugins')` and run `php artisan mainstay:sync`. You declare
nothing: the plugin ships two types, `Form` and `Submission`, and the blocks a form is built from: text input,
email input, textarea, select (its options one to a line), checkbox and submit button, each with a name, a
label and whether it is required. Add a form in the admin under Forms and build it on the Content tab, with
ordinary text between the fields. A form is mailed to its `recipients` (comma-separated), and thanks the
visitor with its `message` or sends them on to its `redirect` address. Its rules come from its blocks, a name is
used once per form, and a form needs to be published to take a post; the same goes for a draft-only or trashed one.

Print a form on a page with `@form($form)` (or `Mainstay\Forms\Forms::print()`), or let editors drop the Form
block into a type's content by listing `Mainstay\Forms\Blocks\FormBlock::class` in its `#[Blocks([...])]`. A form
that is not live prints nothing. The markup is unstyled, for your stylesheet.

```php
#[Blocks([Hero::class, FormBlock::class])]
class Page extends Entry {}
```

A post is kept as a `Submission` first, then mailed with Laravel's mailer; a mail that fails is reported and the
visitor still sees success. Submissions are read and deleted in the admin and the API and never written by hand
(`SubmissionPolicy`). Two endpoints take a post, both rate limited by address with the `mainstay-forms` limiter
(30 a minute, `mainstay.forms.limit`): `POST /mainstay/forms/{id}` from a page, in the site's middleware
(`mainstay.site.middleware`, `web`) with its CSRF token, redirecting back with the errors and what was typed, and
`POST {api prefix}/forms/{id}/submissions`, open without a token, answering 201 with the confirmation or 422 with the
errors; `?locale=` picks the language. The plugin adds a *Submissions* dashboard widget, the total and the
newest, for whoever holds `edit_submissions`. No spam protection beyond the limiter, file uploads or export yet.

Nothing on the web makes the first account. Make it on the server, and invite the rest from the
admin's Users:

```bash
php artisan mainstay:user you@example.com --role=administrator
```

### Declare a content type

```php
namespace App\Content;

use App\Blocks\FeatureGrid;
use App\Blocks\Hero;
use Mainstay\Content\Entry;
use Mainstay\Fields\Blocks;
use Mainstay\Fields\Text;
use Mainstay\Site\Route;

#[Route('/{slug}')]
#[Blocks([Hero::class, FeatureGrid::class])]
class Page extends Entry
{
    #[Text(required: true, localized: true)]
    public string $slug;
}
```

`#[Route]` comes from the site plugin (`Mainstay\Site\Route`), which keeps the entries' paths, their
front page and the `/path` lookup; without it an entry has no `uri` and `/path` finds nothing.

Every entry already has a title and content, and `#[Blocks]` names the blocks a page's content may
hold. Register the type in a service provider's `boot()`:

```php
Mainstay::types([Page::class]);
```

With `MAINSTAY_SCHEMA_SYNC=true` in development's `.env`, `php artisan mainstay:sync` makes its table.

A content type or a part carries `#[Icon]` too, which is its item in the admin's sidebar in place of its
kind's icon: `#[Icon('newspaper')]` on an `Article`. Inside a sidebar group -- Content, Globals, a part's
heading, Plugins -- the items are alphabetical by label.

### Write a block

A block is a class with fields, as a content type is, drawn by a Blade view named after it. `@field`
prints a text field so it can be typed in place in the admin. `#[Blocks]` on a block names the blocks
it holds inside it, and `text: true` lets it hold text among them, headings and paragraphs typed as the
page's own; `@inner` prints what it holds. Give a block's headings, paragraphs, buttons and images that
way, as text and blocks inside it rather than fields, and each use of it can differ. `#[Icon]` names one
of Mainstay's built-in icons, or an SVG file under `resources/`, to list it by; `#[Description]` and
`#[Example]` are what the Add block dialog says of it and previews it with:

```php
#[Icon('grid')]
#[Description('Selling points: a heading over a grid of them.')]
#[Example(inner: [['h2' => 'What you get'], Feature::class])]
#[Blocks([Feature::class], text: true)]
class FeatureGrid extends Block {}

#[Icon('button')]
#[Example(['label' => 'Read the docs'])]
class Button extends Block
{
    #[Text(required: true)]
    public string $label;

    #[Relation(to: Page::class)]
    public ?Page $page;
}
```

```blade
{{-- resources/views/blocks/feature_grid.blade.php --}}
<section class="features">@inner($block)</section>

{{-- resources/views/blocks/button.blade.php --}}
@if ($block->page?->missing === false)
    <a class="button" href="{{ $block->page->url() }}">@field($block, 'label')</a>
@endif
```

### Render it

The type's template gets the entry, and printing its content draws its text and every block in it:

```blade
{{-- resources/views/page.blade.php --}}
<main>
    <h1>{{ $entry->title }}</h1>
    <div class="prose">{{ $entry->content }}</div>
</main>
```

### Write a plugin

A plugin is a package's types, blocks, field types, admin screens, dashboard widgets, listeners and AI providers, on
one class. Only
the plugins `config/mainstay.php` names load: installing a package switches nothing on, and nothing is
switched on from the admin.

```php
// config/mainstay.php
'plugins' => [Acme\Seo\Seo::class],
```

```php
namespace Acme\Seo;

use Mainstay\Content\Icon;
use Mainstay\Events\Published;
use Mainstay\Plugin;

#[Icon('star')]
class Seo extends Plugin
{
    public static function handle(): string
    {
        return 'seo';
    }

    public static function label(): string
    {
        return 'SEO';
    }

    public function types(): array
    {
        return [SeoRedirect::class, SeoSettings::class];
    }

    public function views(): ?string
    {
        return __DIR__.'/../resources/views';
    }

    public function routes(): ?string
    {
        return __DIR__.'/../routes/admin.php';
    }

    public function listeners(): array
    {
        return [Published::class => [PurgeCache::class]];
    }
}
```

The label is its name in the sidebar and in the roles grid. It has no default, since a class's name is
not a label, and a blank one is refused as Mainstay boots. The handle names everything the plugin takes: its views are `seo::`, its screens are under
`/admin/seo` and its capability is `use_seo`. A handle one of the admin's screens, a view namespace, a
type or another plugin has already is refused as Mainstay boots.

Its types register as yours do. A block or a field type draws from its plugin's views by naming the
handle as its `$viewNamespace` -- `protected ?string $viewNamespace = 'seo';` on a block, `protected
string` on a field type: a block is drawn by `seo::blocks.<handle>`, a field type by
`seo::components.fields.<kebab-name>`. One handle names one block across the site, so give a
plugin's blocks names of its own, `SeoFaq` rather than `Faq`. Any of its views can be overridden at
`resources/views/vendor/seo`.

The routes file is loaded inside the admin, behind its sign-in and CSRF check, and answered only to
someone holding `use_seo`, which the roles grid lists under Plugins. Its route named `index` is the
plugin's item in the sidebar. A screen's view is drawn in `<x-mainstay::admin>`, and its forms carry
`@csrf`:

```php
// routes/admin.php
Route::get('/', [RedirectController::class, 'index'])->name('index');
```

A plugin adds a tab to an entry's form by returning classes implementing `Mainstay\Admin\EntryTab`
from `entryTabs()`: the view of its panel, which types it applies to, and optionally a toolbar for the
end of the tab bar and an aside in the publishing rail's place while the tab is open. `assets()` lists
the scripts and stylesheets, relative to `public/vendor/<handle>`, that every admin screen loads after
Mainstay's own; the plugin publishes them under the `mainstay-assets` tag.

`tools()` returns the name of a view drawn on every admin screen, for someone holding `use_<handle>` and
for no one else: in the top bar beside the user menu, where it draws its button, and it
`@push('dialogs')` the dialog the button opens. The dialog's id is `<handle>-dialog`, which is how the
command center lists the tool under Open and opens it. The view is given `$label`, the plugin's
`label()`. A plugin with `tools()` is one with screens even without `routes()`, and needs no route
named `index`, so no sidebar item.

`settings()` returns the `Mainstay\Settings\Setting`s the site's owner may set: a key, a label, a type
(`text`, `textarea`, `boolean`, `number` or `select`, which takes `options`), and optionally a
`description`, a `default`, validation `rules`, a `placeholder` and a `capability`. They are drawn as a
section under the plugin's `label()` on **Settings** in the admin, for someone holding `manage_settings`
(and the setting's own `capability`, if it names one, which the roles grid lists), and are read in code
as `Mainstay::setting('<handle>.<key>')`, which falls back to the default. The handle scopes the key, so
two plugins can each have a `title`, and the screen writes only the settings it drew for the person
saving. `Mainstay::setSetting('<handle>.<key>', $value)` writes as code does, with no check of who is
asking, and refuses a key no plugin declared; null forgets the value. Values are kept as JSON in
`mainstay_settings`, so run `php artisan migrate` after updating.

```php
public function settings(): array
{
    return [new Setting('instructions', 'Instructions for the chat', 'textarea', rules: ['max:2000'])];
}
```

Where the generic form is not enough, `settingsRoutes()` returns a routes file loaded under
`/admin/settings/<handle>`, named `mainstay.settings.<handle>.*` and answered to someone holding
`manage_settings`. Its route named `index` is a screen inside Settings, listed under the plugin's
`label()` in Settings' own navigation beside General. The sidebar has one item, Settings, and gains
nothing from a plugin. Draw the screen in `<x-mainstay::settings-screen title="...">`, which brings the
navigation. It is separate from `routes()`, which answers to `use_<handle>` and is the plugin's own item
in the sidebar.

An entry's form, a template part's included, draws its tabs -- Details, the Content tab, and any a plugin
adds -- alphabetically by label, and opens on the first, so Content comes before Details. `#[Tabs]` on
a content type or a part puts the tabs it names first, by id, in that order, and the rest follow
alphabetically; an id the form has no tab for is an error. A tab's id is the same either way:

```php
#[Tabs('details', 'content')]
class Page extends Entry {}
```

`Saved`, `Published`, `Deleted` and `Restored`, in `Mainstay\Events`, carry the type and id of live
content written, heard once the write commits and never when it rolls back. Your app listens to them
with `Event::listen` as a plugin does.

A plugin runs as your application does, with its access: Mainstay keeps what it declares from taking
another's name, not what its code does, so install one as you would any dependency. A listener running
in the request throws after the content has committed; make one that can fail a queued listener.

### Dashboard widgets

The admin's front page is a masonry of widgets, flowing down one, two or three columns by screen width, each
as tall as its content, and each user chooses which to show under **Screen options**, a dialog opened from the top bar. A widget is a class extending `Mainstay\Admin\Widget` that draws a Blade view.
Core has one, *At a glance*, which counts the entries and media the user may read. Yours is a handle,
a title and a view, and a one-sentence `description()` that Screen options shows under the title:

```php
namespace App\Dashboard;

use App\Models\Order;
use Mainstay\Admin\Widget;
use Mainstay\Auth\User;

class LatestOrders extends Widget
{
    public static function handle(): string
    {
        return 'latest_orders'; // lowercase letters, digits and underscores
    }

    public function title(): string
    {
        return 'Latest orders';
    }

    public function description(): string
    {
        return 'The five newest orders, with their status.';
    }

    public function view(): string
    {
        return 'dashboard.latest-orders';
    }

    public function data(User $user): array
    {
        return ['orders' => Order::query()->latest()->limit(5)->get()];
    }

    public function visibleTo(User $user): bool
    {
        return $user->holds('manage_orders');
    }
}
```

```blade
{{-- resources/views/dashboard/latest-orders.blade.php --}}
<ul>
    @foreach ($orders as $order)
        <li>{{ $order->reference }}</li>
    @endforeach
</ul>
```

Name it in a service provider, as you name types:

```php
Mainstay::widgets([App\Dashboard\LatestOrders::class]);
```

A plugin returns its own from `Plugin::widgets()`. Each is made by the container, so a constructor may
ask for what it needs, and a handle another widget has is refused. Nothing in the view is caught: an error in
`data()` or the view is the dashboard's.

`visibleTo()` is where a widget checks the capability that makes it useful. Someone it returns false for
never sees the widget or its checkbox, and cannot switch it on by posting its handle. Beyond that a widget
may override `enabledByDefault()` (`false`
leaves it off until a user ticks it).

Screen options are saved per account, as a map of handle to on or off under `dashboard.widgets` in the
new `preferences` JSON column of `mainstay_users`, which `php artisan migrate` adds. A widget a user has
never chosen follows `enabledByDefault()`. Your own code reads and writes the same column with
`$user->preference('dashboard.widgets')` and `$user->setPreference('editor.density', 'compact')`.

Screen options are not the dashboard's alone: any admin screen can offer its own. A screen's Blade view fills
the layout's `options` slot, with a form that saves what the user chooses, and the layout adds the button to
the top bar and draws the slot in a dialog. A screen that fills nothing has no button, so only the current
screen's options are ever shown:

```blade
<x-mainstay::admin :breadcrumb="[['label' => 'Orders']]">
    <x-slot:options>
        <form method="post" action="{{ route('orders.screen') }}">@csrf ...</form>
    </x-slot:options>
    ...
</x-mainstay::admin>
```

### Over HTTP and MCP

The API is at `/api/mainstay` and answers JSON. A read with no token sees only the types marked
`#[PublicRead]`, never an internal field, and writes nothing:

```php
#[PublicRead]
#[Route('/blog/{slug}')]
class Article extends Entry { /* ... */ }
```

Everything else needs a token. An account makes its own under **API tokens** in the account menu,
or the server makes one with `php artisan mainstay:token ada@example.com "CI build"
--capability=edit_articles`. A token holds the capabilities ticked for it, never one its account
does not hold, and at use no more than the account holds then: take a capability off a role and its
accounts' tokens lose it too. A program sends it as `Authorization: Bearer mst_...`. The secret is
shown once and only its hash is kept.

A token binds Mainstay's own policies through `User::holds()`. A policy of your own binds tokens only
if it asks `holds()` too, rather than reading the role.

| Route | |
| --- | --- |
| `GET /types`, `GET /types/{type}` | the types you may read, with the JSON Schema a write takes |
| `GET /schema` | the JSON Schema of every payload you may read |
| `GET /entries/{type}` | a page: `where`, `sort`, `page`, `perPage` (at most 100), `locale`, `depth` |
| `GET /entries/{type}/{id}`, `GET /path?uri=/blog/hello` | one entry |
| `POST /entries/{type}`, `PATCH`, `DELETE /entries/{type}/{id}` | live writes; a delete goes to the trash |
| `GET`, `PUT /globals/{type}` | a global |
| `GET /drafts?type=&entry=`, `GET /drafts/{id}` | a draft |
| `POST /drafts`, `POST /drafts/{id}/publish`, `DELETE /drafts/{id}` | drafts |

`where` is the query layer's own as JSON, `?where={"readingMinutes":{">":3}}`, and `sort` a list,
`?sort=-publishedAt,title`. A write sends `{"data": {...}, "locale": "en"}`, a relation or an image by
its id. A refusal is a 401 with no token, a 403 with one, and a 422 naming each field that did not
take its value.

A payload is the entry's `id`, `type`, `locale`, `uri`, `createdAt` and `updatedAt`, then its fields.
Moments are RFC 3339 in UTC. An image is its alt text, focal point and a URL per size. A relation is
the entry it points at, as a payload of its own, as far as `depth` reaches (1 by default, at most 3,
and 1 without a token), and its id past that or where you may not read its type. `php artisan
mainstay:typescript` writes `/schema` out as TypeScript declarations, to
`resources/types/mainstay.d.ts` unless you name a path.

With the `mainstay/mcp` plugin (name `Mainstay\Mcp\McpPlugin` in `config('mainstay.plugins')`), the same operations are an MCP server at `/api/mainstay/mcp`, for a client that speaks Streamable
HTTP: `find-entries`, `get-entry`, `save-draft`, `publish-draft` and the rest, behind the same token.
Give an agent a token that edits and does not publish, and it writes drafts for a person to put live. The plugin
adds an *MCP server* dashboard widget with the endpoint, the tool count and a link to API tokens, which every
account sees as every account may make tokens.

### Connect an AI provider

The `mainstay/ai` plugin talks to AI providers through [laravel/ai](https://github.com/laravel/ai), so
every provider it speaks is one a site can connect. Require it and name `Mainstay\Ai\AiConnectors` in
`config('mainstay.plugins')`: OpenAI, Anthropic, Gemini, Mistral, Groq, xAI, DeepSeek,
OpenRouter, Ollama and the rest. The admin's **Ai Connectors** screen, open to whoever holds
`use_ai`, has a card per provider -- what it does, where its key comes from, Test -- and
chooses the provider each kind of work goes to: text, images, speech, transcription, embeddings,
reranking and classification. It adds an *AI connectors* dashboard widget, how many providers have a key and
where each kind of work goes, for whoever holds `use_ai`; a key is never shown.

A key in the environment, under the name laravel/ai's config reads (`ANTHROPIC_API_KEY`,
`OPENAI_API_KEY`), wins. Without one, the screen saves a key encrypted with `APP_KEY` and never shows
it again. Test asks the provider's cheapest model for one word, so it costs a fraction of a cent.

Code prompts with laravel/ai itself and reaches the provider the screen chose for that kind of work:

```php
use Laravel\Ai\Image;

use function Laravel\Ai\agent;

$alt = agent('You write alt text of at most 120 characters.')->prompt('A harbour at dusk, two boats.')->text;
$poster = Image::of('A harbour at dusk, as a linocut')->generate();
```

A provider's other settings, such as a URL or a region, are laravel/ai's config: publish it with
`php artisan vendor:publish --tag=ai-config`. A queue worker reads the screen's keys when it first
prompts, so restart workers after changing one.

A plugin adds providers by implementing `Mainstay\Ai\DeclaresProviders` and its `providers()`, as entries of laravel/ai's `ai.providers` with a label for
the screen. One on a driver laravel/ai has is config alone -- an `openai-compatible` one names its
models, since laravel/ai cannot guess them -- and one on a driver of its own hands over a closure that
returns a laravel/ai provider:

```php
public function providers(): array
{
    return [
        'together' => [
            'label' => 'Together AI',
            'driver' => 'openai-compatible',
            'url' => 'https://api.together.xyz/v1',
            'models' => ['text' => ['default' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo']],
        ],
        'acme' => ['label' => 'Acme', 'driver' => fn ($app, array $config) => new AcmeProvider($config, $app['events'])],
    ];
}
```

Your own `ai.providers` entry of the name wins key by key, so `'together' => ['key' =>
env('TOGETHER_API_KEY')]` in `config/ai.php` gives it a key from the environment.

### Upgrading

Re-run the asset publish with `--force` after upgrading the package. An upgrade can also add a column
Mainstay keeps for itself to the content tables, and production's schema comes from hand-written
migrations: run `php artisan mainstay:schema:check` against it before deploying, and write a
migration for whatever it reports.

## Roadmap

Mainstay is built in phases, each ending at something that works. Phases 1 to 13 are done: content
types and schema sync, reading and writing from PHP, public rendering, rich text and blocks, media,
relations and taxonomies, drafts and revisions, identity, the admin, accounts, the page builder, and
the API with its tokens and MCP server. AI connectors came after them, and then the split of core
from its plugins: `ai`, `mcp`, `site`, `page-builder`, `parts` for template parts and `forms` for a
form builder. Next:

- **The second locale and the second site.**
- **Onboarding.**
- **Releasing the plugins**, and `mainstay-site` running on them.

[`docs/plan.md`](docs/plan.md) has every phase in detail, and [`docs/decisions.md`](docs/decisions.md)
the reasons behind them.

## Repository layout

| Path | Package | What it is |
| --- | --- | --- |
| `packages/cms` | `mainstay/cms` | The Laravel package: service provider, routes, content API, and the built admin bundle |
| `packages/cms/resources/js` | `@mainstay/admin` | The admin's client bundle: the editor island, plus the design system's behaviour. Private — built into `packages/cms/dist`, never published |
| `packages/ui` | `mainstay/ui`, `@mainstay/ui` | Design system: Blade components as a Composer package; theme tokens and the components' own behaviour as an npm one |
| `packages/editor` | `@mainstay/editor` | The content editor, built directly on ProseMirror |

`packages/cms/dist` is committed on purpose. A host installs Mainstay with Composer and must never be
asked to run a JavaScript build to get an admin panel.

## Working on Mainstay

```bash
composer install
pnpm install
pnpm build            # editor, then the admin bundle into packages/cms/dist
composer serve        # a real Laravel app at http://127.0.0.1:8000/admin
```

The dev app comes from [Testbench](https://packages.tools/testbench) — `testbench.yaml` describes
it and `workbench/` is generated, so there is no checked-in playground application to drift.

```bash
composer test         # PHPUnit, via Testbench
composer lint         # Pint
pnpm typecheck
pnpm test             # Vitest
```

The design system has a Storybook. Components are developed there rather than by
round-tripping through the admin panel:

```bash
pnpm --filter @mainstay/ui storybook          # :6106, bound to 0.0.0.0 for LAN access
pnpm --filter @mainstay/ui build-storybook    # compiles every story and the theme; CI runs this
```

The static build is a check, not a site: rendering a story needs the PHP process the dev server
runs beside it, so `storybook-static` will not draw components on its own.

Stories stay TypeScript and import the Blade file they render: `storybook-php` runs PHP
server-side and hands Storybook the markup back. `.storybook/bootstrap.php` boots Testbench so
the components compile through the same Blade the admin uses. Stories live in `packages/ui/stories`
rather than beside the components, which is what keeps the workshop's own utilities out of a
consumer's stylesheet. Telemetry is disabled in `.storybook/main.ts`.

The behaviour the platform gives no markup for — dropdown dismissal, the sidebar's fold, the tag
input, the command centre, list selection, unsaved-changes — lives in `packages/ui/src/js` beside
the components it belongs to, and both the admin bundle and Storybook mount the same `mount()`. A
component whose behaviour only existed in the app would be a component the workshop could not
actually exercise. `mount()` is safe to call again, which is what lets Storybook re-run it after
every story render.

`pnpm dev` runs every package in watch mode, so a change in `@mainstay/editor` or in the design
system's behaviour rebuilds the admin bundle that `composer serve` is already serving. The Blade
components themselves need no build at all.

## Licence

Mainstay is open source under the [MIT licence](LICENSE).
