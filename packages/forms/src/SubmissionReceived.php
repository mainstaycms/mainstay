<?php

namespace Mainstay\Forms;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/* The notice mailed to a form's recipients: each answer under the label the form gave it. */
class SubmissionReceived extends Mailable
{
    /** @param list<array{name: string, label: string, value: mixed}> $answers */
    public function __construct(public Form $form, public array $answers) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New submission: {$this->form->title}");
    }

    public function content(): Content
    {
        return new Content(view: 'forms::mail.submission', text: 'forms::mail.submission-text');
    }
}
