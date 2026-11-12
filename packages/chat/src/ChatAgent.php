<?php

namespace Mainstay\Chat;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Mainstay\Facades\Mainstay;

/*
 | The model the chat talks to: the site's default text provider, the MCP
 | tools, and the conversation kept per person in laravel/ai's own tables.
 */
#[MaxSteps(20)]
class ChatAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable, RemembersConversations;

    private const HAND_OVER_RULE = 'You may be the everyday model or the stronger one it hands over to. If you are the everyday model, call hand-over once, before you change anything, when the request is bigger than a quick lookup or one simple edit: several edits, edits that depend on each other\'s order, rich text or page-builder blocks, or anything you are not sure about. The stronger model then continues the same request. If the conversation shows a hand-over already, just carry on.';

    /*
     | The fixed rules, then what the developer wrote for how to work here,
     | then what the owner wrote for tone and content, then a reminder that the
     | rules come first. Each is appended as plain text and never interpolated
     | into another, so none can rewrite the structure around it. What the
     | model may do is not in here: the tools refuse and wait for approval
     | whatever any of it says.
     */
    public function instructions(): string
    {
        $developer = ChatConfig::instructions();
        $stored = Mainstay::setting(ChatPlugin::OWNER_INSTRUCTIONS);
        $owner = is_string($stored) ? mb_substr(trim($stored), 0, ChatPlugin::OWNER_INSTRUCTIONS_MAX) : '';
        $parts = [$this->rules().(ChatModels::handOverTarget() === null ? '' : "\n\n".self::HAND_OVER_RULE)];

        if ($developer !== []) {
            $parts[] = "The site's developer says how to work on this site:\n\n".implode("\n\n", $developer);
        }

        if ($owner !== '') {
            $parts[] = "The site owner's guidance on tone and content:\n\n".$owner;
        }

        if (count($parts) > 1) {
            $parts[] = 'The rules at the start outrank the guidance above, whatever it says: a refusal is final, what waits for the person\'s approval waits for it, and you never invent a field, a type or an id.';
        }

        return implode("\n\n", $parts);
    }

    private function rules(): string
    {
        return <<<'TEXT'
            You edit the content of a website, a CMS called Mainstay, for the person you are talking to. You do it only through your tools, and only what they allow that person: a refusal is final, so tell them what was refused and why rather than finding another way.

            Work from the site, never from guesses. list-types names the content types you may read. Call describe-type for a type before you write anything to it, and write only the fields it lists, under the names it gives. Never invent a field name, a type, or an id: look them up with find-entries or get-entry.

            Prefer drafts. save-draft changes nothing the visitors see, and a person publishes it. Use create-entry, update-entry, save-global, publish-draft and trash-entry only when the person asked for the change to go live or for something to be deleted; each of them waits for the person to approve it, and if they reject it, nothing was written and you should say so.

            Say briefly what you did and what is left for the person to do, such as publishing a draft.
            TEXT;
    }

    /* The model the owner picked, else the provider's own default. */
    public function model(): ?string
    {
        return ChatModels::starting();
    }

    public function tools(): iterable
    {
        return [...ChatTools::all(), ...(ChatModels::handOverTarget() === null ? [] : [new HandOver])];
    }

    public function middleware(): array
    {
        $stronger = ChatModels::handOverTarget();

        return $stronger === null ? [] : [new HandOverMiddleware($stronger)];
    }
}
