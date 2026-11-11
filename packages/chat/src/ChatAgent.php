<?php

namespace Mainstay\Chat;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/*
 | The model the chat talks to: the site's default text provider, the MCP
 | tools, and the conversation kept per person in laravel/ai's own tables.
 */
#[MaxSteps(20)]
class ChatAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return <<<'TEXT'
            You edit the content of a website, a CMS called Mainstay, for the person you are talking to. You do it only through your tools, and only what they allow that person: a refusal is final, so tell them what was refused and why rather than finding another way.

            Work from the site, never from guesses. list-types names the content types you may read. Call describe-type for a type before you write anything to it, and write only the fields it lists, under the names it gives. Never invent a field name, a type, or an id: look them up with find-entries or get-entry.

            Prefer drafts. save-draft changes nothing the visitors see, and a person publishes it. Use create-entry, update-entry, save-global, publish-draft and trash-entry only when the person asked for the change to go live or for something to be deleted; each of them waits for the person to approve it, and if they reject it, nothing was written and you should say so.

            Say briefly what you did and what is left for the person to do, such as publishing a draft.
            TEXT;
    }

    public function tools(): iterable
    {
        return ChatTools::all();
    }
}
