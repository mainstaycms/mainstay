<?php

namespace Mainstay\Forms\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mainstay\Forms\Forms;
use Mainstay\Forms\Submissions;

/*
 | A form posted from a page of the site: back to the page with the
 | confirmation flashed, or on to the form's own address; or back with what
 | was typed and what was wrong, and nothing kept.
 */
class WebSubmitController
{
    public function __construct(private Forms $forms, private Submissions $submissions, private Locale $locale) {}

    public function __invoke(Request $request, int $form): RedirectResponse
    {
        $locale = $this->locale->ofRequest();
        $found = $this->forms->live($form, $locale) ?? abort(404);

        try {
            $this->submissions->receive($found, $request->post(), $locale);
        } catch (ValidationException $exception) {
            return back()->withInput()->withErrors($exception->errors())->with(Forms::REFUSED, $found->id);
        }

        return ($address = Confirmation::redirect($found)) === null
            ? back()->with(Forms::ACCEPTED, $found->id)
            : redirect()->away($address);
    }
}
