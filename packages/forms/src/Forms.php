<?php

namespace Mainstay\Forms;

use Illuminate\Support\HtmlString;
use Mainstay\Mainstay;

/*
 | Forms as the public sees them: found by id among those that are live, and
 | printed for a page with `@form($form)`.
 |
 | A form printed after a post that came back refused shows what was typed and
 | what was wrong with it, and one that was accepted shows its confirmation;
 | both are flashed by the web endpoint with the form's id, so a page holding
 | two forms marks only the one that was sent. The form being printed is kept
 | for the length of its printing, for its field blocks to ask.
 */
class Forms
{
    public const REFUSED = 'forms.refused';

    public const ACCEPTED = 'forms.accepted';

    private ?Form $printing = null;

    public function __construct(private Mainstay $mainstay) {}

    /* Live in the locale: published there, and not in the trash. A draft-only form has no row to find. */
    public function live(int $id, string $locale): ?Form
    {
        $form = $this->mainstay->findById(Form::class, $id, locale: $locale, overrideAccess: true);

        return $form instanceof Form ? $form : null;
    }

    /* The form as a page prints it, or nothing for one that is not live: a missing one is a form the reader cannot read. */
    public function print(?Form $form): HtmlString
    {
        if ($form === null || $form->missing) {
            return new HtmlString('');
        }

        $outer = $this->printing;
        $this->printing = $form;

        try {
            return new HtmlString(view('forms::form', [
                'form' => $form,
                'accepted' => session(self::ACCEPTED) === $form->id,
                'content' => $form->content?->toHtml() ?? '',
                'problems' => $this->refused() ? session('errors')?->all() ?? [] : [],
            ])->render());
        } finally {
            $this->printing = $outer;
        }
    }

    public function typed(string $name): mixed
    {
        return $this->refused() ? old($name) : null;
    }

    public function error(string $name): ?string
    {
        return $this->refused() ? session('errors')?->first($name) : null;
    }

    private function refused(): bool
    {
        return $this->printing !== null && session(self::REFUSED) === $this->printing->id;
    }
}
