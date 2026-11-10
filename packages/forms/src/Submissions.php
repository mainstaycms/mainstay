<?php

namespace Mainstay\Forms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mainstay\Forms\Fields\Recipients;
use Mainstay\Mainstay;
use Throwable;

/*
 | What becomes of a post to a form: held to the form's rules, kept, and then
 | mailed. Kept first, so a mail that cannot be sent costs the people who read
 | the mail a notice and the visitor nothing.
 */
class Submissions
{
    public function __construct(private Mainstay $mainstay) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function receive(Form $form, array $input, string $locale): Submission
    {
        $answers = $this->answers($form, $input);

        /** @var Submission $submission */
        $submission = DB::transaction(fn () => $this->mainstay->create(Submission::class, [
            'title' => Str::limit($form->title.' '.now()->toDateTimeString(), 255, ''),
            'form' => $form->id,
            'answers' => $answers,
            'language' => $locale,
        ], locale: $locale, overrideAccess: true));

        $this->notify($form, $answers);

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{name: string, label: string, value: mixed}>
     *
     * @throws ValidationException
     */
    private function answers(Form $form, array $input): array
    {
        $fields = FormRules::fields($form);
        $labels = array_combine(array_map(fn ($field) => $field->name, $fields), array_map(fn ($field) => $field->label, $fields));
        $valid = Validator::make($input, FormRules::for($form), attributes: $labels)->validate();

        return array_map(fn ($field) => [
            'name' => $field->name,
            'label' => $field->label,
            'value' => $field->answer($valid[$field->name] ?? null),
        ], $fields);
    }

    /* A mailer that is down is the site's to hear about, not the visitor's. */
    private function notify(Form $form, array $answers): void
    {
        $recipients = Recipients::split($form->recipients);

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new SubmissionReceived($form, $answers));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
