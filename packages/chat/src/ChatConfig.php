<?php

namespace Mainstay\Chat;

use Laravel\Ai\Contracts\Tool;
use LogicException;

/*
 | What the developer sets for the chat in `config('mainstay.chat')`, checked
 | where it is read so that a wrong entry is named rather than ignored:
 |
 |   'chat' => [
 |       'instructions' => 'How to work here: ...',   // a string, or a list of them
 |       'tools' => [App\Ai\LookUpStock::class],      // laravel/ai tools, from the container
 |   ],
 */
class ChatConfig
{
    /** @return list<string> */
    public static function instructions(): array
    {
        $instructions = config('mainstay.chat.instructions', []);
        $instructions = is_string($instructions) ? [$instructions] : $instructions;

        if (! is_array($instructions) || array_filter($instructions, fn ($each) => ! is_string($each)) !== []) {
            throw new LogicException("config('mainstay.chat.instructions') is a string or a list of strings.");
        }

        return array_values(array_filter(array_map(trim(...), $instructions), fn (string $each) => $each !== ''));
    }

    /** @return list<class-string<Tool>> */
    public static function tools(): array
    {
        $tools = config('mainstay.chat.tools', []);

        if (! is_array($tools)) {
            throw new LogicException("config('mainstay.chat.tools') is a list of class names of laravel/ai tools.");
        }

        foreach ($tools as $tool) {
            if (! is_string($tool) || ! class_exists($tool)) {
                throw new LogicException("config('mainstay.chat.tools') names ".(is_string($tool) ? $tool : get_debug_type($tool)).', which is not a class.');
            }

            if (! is_subclass_of($tool, Tool::class)) {
                throw new LogicException("config('mainstay.chat.tools') names {$tool}, which does not implement ".Tool::class.'.');
            }
        }

        return array_values($tools);
    }
}
