<?php

namespace Mainstay\Redirects;

use Illuminate\Support\Collection;
use RuntimeException;

/*
 | The address a request comes to: an exact redirect first, one indexed read,
 | then the regex rules in their order, the first that matches. A target that
 | is an address of this site is looked up again, so a chain is answered at
 | once, and a chain longer than MAX_HOPS is a loop.
 |
 | A candidate is a rule not yet saved, or saved and being changed, taken in
 | the place of what the database holds, so a save can ask what it would do.
 */
class Resolver
{
    public const MAX_HOPS = 8;

    public function __construct(private RedirectStore $store) {}

    /**
     * @param  bool  $reportFailures  write a pattern that cannot run, or a loop, on the rule for the admin to show
     *
     * @throws RedirectLoop
     */
    public function resolve(string $path, ?Redirect $candidate = null, bool $reportFailures = false): ?Resolution
    {
        $rules = [];
        $target = $path;

        while (count($rules) <= self::MAX_HOPS && ($found = $this->match($target, $candidate, $reportFailures)) !== null) {
            [$rule, $target] = $found;
            $rules[] = $rule;

            if ($rule->isGone() || ! str_starts_with((string) $target, '/')) {
                break;
            }
        }

        if (count($rules) > self::MAX_HOPS) {
            $loop = new RedirectLoop($rules);
            $reportFailures && $this->store->markFailing($rules[0], $loop->getMessage());

            throw $loop;
        }

        if ($rules === []) {
            return null;
        }

        return new Resolution($rules, $target, end($rules)->isGone() ? Redirect::GONE : $rules[0]->status);
    }

    /** @return array{0: Redirect, 1: ?string}|null the rule and the address it sends to */
    private function match(string $path, ?Redirect $candidate, bool $reportFailures): ?array
    {
        $exact = $this->exact($path, $candidate);

        if ($exact !== null) {
            return [$exact, $exact->target];
        }

        $decoded = rawurldecode(strtok($path, '?#') ?: '');

        /* A path that is not UTF-8 can match no pattern; trying them would flag
           every healthy rule as failing. */
        foreach (mb_check_encoding($decoded, 'UTF-8') ? $this->regexRules($candidate) : [] as $rule) {
            try {
                $groups = Pattern::match($rule->source, $decoded);
            } catch (RuntimeException $failure) {
                $reportFailures && $this->store->markFailing($rule, "The pattern failed: {$failure->getMessage()}");

                continue;
            }

            if ($groups !== null) {
                return [$rule, $rule->target === null ? null : Pattern::expand($rule->target, $groups)];
            }
        }

        return null;
    }

    private function exact(string $path, ?Redirect $candidate): ?Redirect
    {
        $hash = Address::hash(Redirect::EXACT, $path);

        if ($candidate?->enabled && ! $candidate->isRegex() && $candidate->source_hash === $hash) {
            return $candidate;
        }

        $rule = Redirect::query()->where('source_hash', $hash)->where('enabled', true)->first();

        return $rule === null || $rule->is($candidate) ? null : $rule;
    }

    /** @return Collection<int, Redirect> */
    private function regexRules(?Redirect $candidate): Collection
    {
        $rules = $this->store->regexRules();

        if ($candidate === null) {
            return $rules;
        }

        $rules = $rules->reject(fn (Redirect $rule) => $rule->is($candidate));

        return $candidate->isRegex() && $candidate->enabled
            ? $rules->push($candidate)->sortBy([['priority', 'asc'], ['id', 'asc']])->values()
            : $rules;
    }
}
