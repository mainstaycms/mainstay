<?php

namespace Mainstay\Forms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Mainstay\Admin\Widget;
use Mainstay\Auth\Gate;
use Mainstay\Auth\User;
use Mainstay\Mainstay;
use Mainstay\Navigation;

/*
 | How many submissions there are and the newest, each linking to its
 | read-only page, with a link to Forms where the user may edit them. For
 | whoever may read submissions. A submission has one language's row, so the
 | total and the newest are read across every locale and not just the first.
 */
class SubmissionsWidget extends Widget
{
    private const NEWEST = 5;

    private const ANSWER_LENGTH = 60;

    public function __construct(private Mainstay $mainstay) {}

    public static function handle(): string
    {
        return 'forms';
    }

    public function title(): string
    {
        return 'Submissions';
    }

    public function view(): string
    {
        return 'forms::widgets.submissions';
    }

    /** @return array{total: int, newest: list<array{form: string, at: CarbonImmutable, answer: string, href: string}>, all: string, forms: string|null} */
    public function data(User $user): array
    {
        $total = 0;
        $found = [];

        foreach (array_keys($this->mainstay->locales()) as $locale) {
            $page = $this->mainstay->paginate(Submission::class, sort: '-createdAt', perPage: self::NEWEST, locale: $locale);
            $total += $page->total();
            array_push($found, ...$page->items());
        }

        usort($found, fn (Submission $first, Submission $second) => $second->createdAt <=> $first->createdAt);

        return [
            'total' => $total,
            'newest' => array_map($this->row(...), array_slice($found, 0, self::NEWEST)),
            'all' => route('mainstay.entries', Submission::handle(), absolute: false),
            'forms' => $this->mayOpen(Form::class) ? route('mainstay.entries', Form::handle(), absolute: false) : null,
        ];
    }

    public function visibleTo(User $user): bool
    {
        return $this->mayOpen(Submission::class);
    }

    private function row(Submission $submission): array
    {
        return [
            'form' => $submission->form->title ?? $submission->title,
            'at' => $submission->createdAt,
            'answer' => $this->firstAnswer($submission->answers),
            'href' => route('mainstay.entries.edit', [Submission::handle(), $submission->id], absolute: false),
        ];
    }

    /** @param list<array{value: mixed}> $answers */
    private function firstAnswer(array $answers): string
    {
        $value = $answers[0]['value'] ?? '';

        return Str::limit(match (true) {
            is_array($value) => implode(', ', $value),
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        }, self::ANSWER_LENGTH);
    }

    private function mayOpen(string $type): bool
    {
        return Navigation::writes($type) && Gate::about($type)->allows('viewAny', [$type, $type]);
    }
}
