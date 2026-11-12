<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin panel
    |--------------------------------------------------------------------------
    |
    | Where the admin single-page app is mounted. Set a domain to serve it from
    | a dedicated hostname; leave it null to mount on the application's domain.
    |
    | The admin runs a stack of its own, not the `web` group: its session is
    | under a cookie of its own, mainstay_session, so an editor's is never a
    | visitor's. Middleware listed here runs after that stack. Do not list
    | `web`, which would decrypt the cookies a second time and blank them.
    |
    */

    'domain' => env('MAINSTAY_DOMAIN'),

    'path' => env('MAINSTAY_PATH', 'admin'),

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Content API
    |--------------------------------------------------------------------------
    |
    | Mainstay is headless: this API is the contract every consumer uses, from
    | the static site generator that builds your front end to an MCP client at
    | {prefix}/mcp. Every route reads the bearer token an account made under
    | API tokens, or with none reads as nobody, who sees only the types marked
    | #[PublicRead]. Rate limiting is the middleware's, which is yours.
    |
    */

    'api' => [
        'prefix' => env('MAINSTAY_API_PREFIX', 'api/mainstay'),

        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content locales
    |--------------------------------------------------------------------------
    |
    | The languages content is written in, the first being the default, each
    | with where it is served: a path prefix, a host, or both. Either every
    | locale names a host or none does.
    |
    |     ['en' => '/', 'nl' => '/nl']
    |     ['en' => 'https://example.com', 'nl' => 'https://example.nl']
    |
    | Only a field declared localized differs between them. This is not the
    | application's locale: that one follows the visitor, and a public page
    | sets it to the locale the page is served in.
    |
    */

    'locales' => [env('APP_LOCALE', 'en') => '/'],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Where uploaded images and documents are kept: private disks, since
    | nothing is served from them directly. Every file goes out through the
    | `mainstay.media.file` route, which serves a public file to anyone for as
    | long as a browser will keep it, and a restricted one to a signed-in
    | editor or to a URL signed to expire. A host may point `disk` at any disk,
    | S3 included: the route streams from it.
    |
    | `disk` holds every copy an image field's sizes name, and each document as
    | it came. `originals` holds an image as it came, which still carries the
    | camera's EXIF, GPS included, and is never served; leave it where it is,
    | on a disk of its own or beside the rest.
    |
    | Copies are written in each of `formats` this PHP's GD can write, and,
    | where that leaves out WebP, in the original's own kind as well, so a
    | host without them serves larger files rather than none. A format gained
    | later reaches older images through mainstay:media:reprocess.
    |
    | `documents` is the closed list of extensions a document may have; a file
    | is also checked by what its bytes are. `max_document_kb` is the largest
    | one taken. `url_minutes` is how long a URL for a restricted file lives.
    | `sendfile` lets a proxy that sends X-Sendfile-Type (X-Accel-Redirect,
    | X-Sendfile) deliver files from a local disk itself.
    |
    */

    'media' => [
        'disk' => env('MAINSTAY_MEDIA_DISK', 'local'),

        'originals' => env('MAINSTAY_MEDIA_ORIGINALS', 'local'),

        'formats' => ['avif', 'webp'],

        /* Where an earlier Mainstay kept the copies: served from there, with a
           warning, until mainstay:media:move has copied them. Null for none. */
        'legacy_disk' => env('MAINSTAY_MEDIA_LEGACY_DISK', 'public'),

        'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'zip'],

        'max_document_kb' => 20480,

        'url_minutes' => 60,

        'sendfile' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Revisions
    |--------------------------------------------------------------------------
    |
    | How many of an entry's earlier versions are kept, newest first: each
    | publish, and each write that replaces something live, files the outgoing
    | version and prunes past this many. Null keeps them all.
    |
    */

    'revisions' => 50,

    /*
    |--------------------------------------------------------------------------
    | Schema sync
    |--------------------------------------------------------------------------
    |
    | mainstay:sync alters the database to match the declared content types,
    | and drops the column of any property that was renamed or removed. Turn
    | it on in development only; it refuses to run in production regardless.
    |
    */

    'schema' => [
        'sync' => env('MAINSTAY_SCHEMA_SYNC', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugins
    |--------------------------------------------------------------------------
    |
    | The plugins Mainstay loads, by class, and the only ones: installing a
    | package switches nothing on. A plugin is code that runs as the
    | application does, so list only what you would install as a dependency.
    | With routes cached, run route:cache again after changing this.
    |
    */

    'plugins' => [],

];
