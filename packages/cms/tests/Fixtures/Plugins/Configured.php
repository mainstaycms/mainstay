<?php

namespace Mainstay\Tests\Fixtures\Plugins;

use Mainstay\Plugin;
use Mainstay\Settings\Setting;

/* A plugin with one setting of each type, one asking for a capability of its own, and a `title` that Tuned declares too. */
class Configured extends Plugin
{
    public static function handle(): string
    {
        return 'configured';
    }

    public static function label(): string
    {
        return 'Configured';
    }

    public function settings(): array
    {
        return [
            new Setting('title', 'Title', description: 'Shown above everything.', default: 'Untitled', rules: ['max:12']),
            new Setting('blurb', 'Blurb', 'textarea', placeholder: 'Say something'),
            new Setting('visible', 'Visible', 'boolean', default: true),
            new Setting('limit', 'Limit', 'number', rules: ['min:1']),
            new Setting('tone', 'Tone', 'select', default: 'plain', options: ['plain' => 'Plain', 'warm' => 'Warm']),
            new Setting('secret', 'Secret', capability: 'configure_secret'),
        ];
    }
}
