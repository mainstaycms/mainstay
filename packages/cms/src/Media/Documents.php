<?php

namespace Mainstay\Media;

use finfo;
use InvalidArgumentException;

/*
 | The documents the library holds, closed: an extension is allowed when it
 | is here and in `mainstay.media.documents`, and a file is that document
 | when its bytes sniff as one of the types listed for the extension. Neither
 | alone is enough -- a name is the client's say, and docx, xlsx, pptx and
 | zip all sniff as a zip -- so the pair is checked, and a zip standing in for
 | an Office file must carry the folder only that kind has.
 |
 | Nothing that a browser would run is here, and never will be: no HTML, SVG,
 | script or executable. The type stored, and served, is the first of each
 | list: the extension's own, so two extensions never share one and a picker
 | can filter by type.
 */
final class Documents
{
    private const ZIP = ['application/zip', 'application/octet-stream'];

    private const OLE = ['application/x-ole-storage', 'application/CDFV2'];

    /* How far from the end of an Office file its central directory, which
       names every entry, is looked for. */
    private const DIRECTORY_BYTES = 524_288;

    /** @var array<string, array{0: list<string>, 1: ?string}> extension => [sniffed types, the folder an Office file holds] */
    private const KINDS = [
        'pdf' => [['application/pdf'], null],
        'doc' => [['application/msword', ...self::OLE], null],
        'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP], 'word/'],
        'xls' => [['application/vnd.ms-excel', ...self::OLE], null],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP], 'xl/'],
        'ppt' => [['application/vnd.ms-powerpoint', ...self::OLE], null],
        'pptx' => [['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP], 'ppt/'],
        'odt' => [['application/vnd.oasis.opendocument.text'], null],
        'ods' => [['application/vnd.oasis.opendocument.spreadsheet'], null],
        'odp' => [['application/vnd.oasis.opendocument.presentation'], null],
        'txt' => [['text/plain'], null],
        'csv' => [['text/csv', 'text/plain', 'application/csv'], null],
        'zip' => [['application/zip'], null],
    ];

    /* Every extension the library can hold, in the order the config lists
       them by default. */
    public static function known(): array
    {
        return array_keys(self::KINDS);
    }

    /*
     | The extensions a document may have: those `mainstay.media.documents`
     | names, narrowed to `$accept` where a field names its own. One this
     | class does not know is the host's mistake, refused naming it.
     |
     | @param  list<string>|null  $accept
     | @return list<string>
     */
    public static function allowed(?array $accept = null): array
    {
        $configured = array_map(self::normalized(...), config('mainstay.media.documents', self::known()));

        foreach ([...$configured, ...array_map(self::normalized(...), $accept ?? [])] as $extension) {
            if (! isset(self::KINDS[$extension])) {
                throw new InvalidArgumentException("The media library holds no .{$extension} documents. It holds ".implode(', ', self::known()).'.');
            }
        }

        return $accept === null ? $configured : array_values(array_intersect($configured, array_map(self::normalized(...), $accept)));
    }

    /* The type stored, and served, for an extension. */
    public static function type(string $extension): string
    {
        return self::KINDS[$extension][0][0];
    }

    /* The extension a stored type belongs to. */
    public static function extension(string $type): ?string
    {
        foreach (self::KINDS as $extension => [$sniffed]) {
            if ($sniffed[0] === $type) {
                return $extension;
            }
        }

        return null;
    }

    /*
     | @param  list<string>  $extensions
     | @return list<string>
     */
    public static function types(array $extensions): array
    {
        return array_map(self::type(...), $extensions);
    }

    /*
     | The extension and type of a file whose name and bytes agree on one in
     | `$extensions`, or null. The name is used for nothing else.
     |
     | @param  list<string>  $extensions
     | @return array{0: string, 1: string}|null
     */
    public static function match(string $path, string $name, array $extensions): ?array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! in_array($extension, $extensions, true) || ! in_array($sniffed, self::KINDS[$extension][0], true) || ! self::holds($path, $extension, $sniffed)) {
            return null;
        }

        return [$extension, self::type($extension)];
    }

    /* An Office file that sniffs as a plain zip is one only if its entries
       say so: what is looked for is written in the clear in the directory. */
    private static function holds(string $path, string $extension, string $sniffed): bool
    {
        $folder = self::KINDS[$extension][1];

        if ($folder === null || $sniffed === self::type($extension)) {
            return true;
        }

        $file = fopen($path, 'rb');
        fseek($file, -min(self::DIRECTORY_BYTES, (int) filesize($path)), SEEK_END);
        $directory = (string) stream_get_contents($file);
        fclose($file);

        return str_contains($directory, '[Content_Types].xml') && str_contains($directory, $folder);
    }

    private static function normalized(string $extension): string
    {
        return strtolower(ltrim($extension, '.'));
    }
}
