<?php

namespace Mainstay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Mainstay\Api\Schema;
use Mainstay\Api\TypeScript;

/*
 | The API's payloads as TypeScript, for a front end built outside PHP, from
 | the same document /schema serves, as a token holding no capability reads
 | it: every type, and no internal field.
 */
class TypeScriptCommand extends Command
{
    protected $signature = 'mainstay:typescript
        {path=resources/types/mainstay.d.ts : Where to write the declarations, from the project\'s root}';

    protected $description = 'Write the content API\'s payloads out as TypeScript declarations';

    public function handle(Schema $schema): int
    {
        $path = base_path($this->argument('path'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, TypeScript::from($schema->document()));

        $this->components->info("Written to {$this->argument('path')}.");

        return self::SUCCESS;
    }
}
