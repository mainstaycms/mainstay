<?php

namespace Mainstay\Redirects;

/*
 | Whether saving a rule would make a circle of redirects, asked by following
 | it as a visitor would be sent. An exact rule is followed from its own
 | address. A regex rule has no one address, so it is followed from where it
 | would send a made-up one: a rule that sends what it matches to somewhere it
 | matches again is the circle, and any other is left to the runtime's limit
 | on hops.
 */
class LoopCheck
{
    /* What a group of the pattern stands for in the made-up address. */
    private const SAMPLE = 'x';

    public function __construct(private Resolver $resolver) {}

    public function problem(Redirect $candidate): ?string
    {
        $start = $this->start($candidate);

        if ($start === null) {
            return null;
        }

        try {
            $this->resolver->resolve($start, $candidate);
        } catch (RedirectLoop $loop) {
            return $loop->getMessage();
        }

        return null;
    }

    private function start(Redirect $candidate): ?string
    {
        if ($candidate->target === null || ! $candidate->enabled || $candidate->target[0] !== '/') {
            return null;
        }

        return $candidate->isRegex() ? preg_replace('/\$(?:\d|\{\w+\})/', self::SAMPLE, $candidate->target) : $candidate->source;
    }
}
