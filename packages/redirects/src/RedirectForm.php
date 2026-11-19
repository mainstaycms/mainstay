<?php

namespace Mainstay\Redirects;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
 | A redirect as the admin's form posts it, checked: what each field may be,
 | whether the source is taken already and whether the rule would send
 | visitors round in a circle. Hands back the rule with the form applied, not
 | saved, and throws the ValidationException the form shows.
 */
class RedirectForm
{
    private const NOTE_MAX = 500;

    public function __construct(private LoopCheck $loops) {}

    /** @throws ValidationException */
    public function apply(array $input, Redirect $redirect): Redirect
    {
        $valid = Validator::make($input, [
            'source' => ['required', 'string', 'max:'.Pattern::MAX_LENGTH, fn (string $field, mixed $source, callable $fail) => $this->exactSourceProblem($input, (string) $source, $fail)],
            'match_type' => ['required', Rule::in([Redirect::EXACT, Redirect::REGEX])],
            'status' => ['required', Rule::in(Redirect::STATUSES)],
            'target' => ['nullable', 'string', 'max:'.Target::MAX_LENGTH],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
            'preserve_query' => ['boolean'],
            'enabled' => ['boolean'],
        ])->validate();

        $redirect->fill($this->attributes($valid));
        $redirect->source_hash = Address::hash($redirect->match_type, $redirect->source);

        if (($problems = array_filter($this->problems($redirect))) !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $redirect;
    }

    private function exactSourceProblem(array $input, string $source, callable $fail): void
    {
        if (($input['match_type'] ?? null) === Redirect::EXACT && (! str_starts_with(trim($source), '/') || preg_match('/[?#]/', $source))) {
            $fail('An address starts with / and has no ? or # in it: the query string is kept or dropped by the option below.');
        }
    }

    /* The input as it is stored: a 410 with no target, and the error of an
       earlier failure cleared. */
    private function attributes(array $valid): array
    {
        $source = trim($valid['source']);
        $note = trim((string) ($valid['note'] ?? ''));

        return [
            'source' => $source,
            'match_type' => $valid['match_type'],
            'status' => (int) $valid['status'],
            'target' => (int) $valid['status'] === Redirect::GONE ? null : trim((string) ($valid['target'] ?? '')),
            'priority' => (int) ($valid['priority'] ?? Redirect::DEFAULT_PRIORITY),
            'note' => $note === '' ? null : $note,
            'preserve_query' => (bool) ($valid['preserve_query'] ?? false),
            'enabled' => (bool) ($valid['enabled'] ?? false),
            'origin' => Redirect::CUSTOM,
            'last_error' => null,
        ];
    }

    /** @return array<string, ?string> a message per field with a problem */
    private function problems(Redirect $redirect): array
    {
        $problems = [
            'source' => $redirect->isRegex() ? Pattern::problem($redirect->source, $redirect->target) : null,
            'target' => $this->targetProblem($redirect),
        ];

        if (Redirect::query()->where('source_hash', $redirect->source_hash)->whereKeyNot($redirect->getKey() ?? 0)->exists()) {
            $problems['source'] ??= 'A redirect for this '.($redirect->isRegex() ? 'pattern' : 'address').' exists already.';
        }

        if (array_filter($problems) === []) {
            $problems['target'] = $this->loops->problem($redirect);
        }

        return $problems;
    }

    private function targetProblem(Redirect $redirect): ?string
    {
        if ($redirect->isGone()) {
            return null;
        }

        return $redirect->target === '' ? 'A target is needed, unless the status is 410 Gone.' : Target::problem($redirect->target);
    }
}
