<?php

namespace Mainstay\Redirects;

/*
 | What a request for an address comes to: the rules it passed through, the
 | address it ends at and the status to answer with. A chain is answered in
 | one hop, with the status of the rule the request met first, or 410 where
 | the chain ends in one.
 */
final class Resolution
{
    /** @param non-empty-list<Redirect> $rules */
    public function __construct(public readonly array $rules, public readonly ?string $target, public readonly int $status) {}

    public function isGone(): bool
    {
        return $this->status === Redirect::GONE;
    }

    /**
     * The target with the visitor's query string on it, where the first rule
     * keeps it: a campaign's tags survive the move. A target is checked when
     * saved and a captured value is encoded when expanded, so none can break
     * out of the header.
     *
     * @psalm-taint-escape header
     */
    public function location(?string $query): string
    {
        if ($query === null || $query === '' || ! $this->rules[0]->preserve_query) {
            return $this->target;
        }

        [$address, $fragment] = array_pad(explode('#', $this->target, 2), 2, null);

        return $address.(str_contains($address, '?') ? '&' : '?').$query.($fragment === null ? '' : "#{$fragment}");
    }

    /** @return list<int> */
    public function ruleIds(): array
    {
        return array_values(array_filter(array_map(fn (Redirect $rule) => $rule->id, $this->rules)));
    }
}
