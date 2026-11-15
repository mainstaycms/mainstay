<?php

namespace Mainstay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Mainstay\Media\Library;
use RuntimeException;

/*
 | The copies of an install that served its images from the public disk, onto
 | the media disk they are served from now. Each file is copied as a stream
 | and read back by its size; one already there at the same size is left. The
 | public disk is never written to: its files are the site's until the one who
 | runs this has seen the images load, and deletes them by hand.
 */
class MoveMediaCommand extends Command
{
    protected $signature = 'mainstay:media:move {--from= : The disk the files were served from, mainstay.media.legacy_disk (public) unless given}';

    protected $description = 'Copy the media files of an install that served them from a public disk onto the private media disk, deleting nothing';

    public function handle(Library $library): int
    {
        $source = $this->option('from') ?: config('mainstay.media.legacy_disk') ?? 'public';
        $from = Storage::disk($source);
        $to = $library->disk();
        [$copied, $kept] = [0, 0];

        foreach ($from->files('media') as $path) {
            if (basename($path)[0] === '.') {
                continue;
            }

            if ($to->exists($path) && $to->size($path) === $from->size($path)) {
                $kept++;

                continue;
            }

            $stream = $from->readStream($path) ?? throw new RuntimeException("{$path} could not be read.");
            $to->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $to->exists($path) || $to->size($path) !== $from->size($path)) {
                throw new RuntimeException("{$path} is not on the media disk at its size after the copy.");
            }

            $copied++;
        }

        $this->components->info("Copied {$copied}, already there {$kept}. Nothing was deleted from the {$source} disk: delete media/ there by hand once the images load.");

        return self::SUCCESS;
    }
}
