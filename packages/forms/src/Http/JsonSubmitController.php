<?php

namespace Mainstay\Forms\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mainstay\Forms\Forms;
use Mainstay\Forms\Submissions;

/*
 | A form posted as JSON by a front end built elsewhere, open to anyone like
 | the page's own: 201 with what to tell the submitter, 422 with the errors,
 | 404 for a form that is not live. The language is `?locale=`, the request's
 | otherwise.
 */
class JsonSubmitController
{
    public function __construct(private Forms $forms, private Submissions $submissions, private Locale $locale) {}

    public function __invoke(Request $request, int $form): JsonResponse
    {
        $locale = $this->locale->ofRequest(is_string($request->query('locale')) ? $request->query('locale') : null);
        $found = $this->forms->live($form, $locale);

        if ($found === null) {
            return response()->json(['message' => 'There is no such form.'], 404);
        }

        try {
            $this->submissions->receive($found, $request->json()->all(), $locale);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        return response()->json(['message' => Confirmation::message($found), 'redirect' => Confirmation::redirect($found)], 201);
    }
}
