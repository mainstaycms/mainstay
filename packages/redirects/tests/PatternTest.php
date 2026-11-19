<?php

namespace Mainstay\Redirects\Tests;

use Mainstay\Redirects\Address;
use Mainstay\Redirects\Pattern;
use Mainstay\Redirects\Target;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/* The pieces that need no site: patterns, targets and addresses. */
class PatternTest extends TestCase
{
    #[Test]
    public function a_pattern_is_anchored_at_both_ends_even_with_alternatives(): void
    {
        $this->assertNotNull(Pattern::match('/a|/b', '/a'));
        $this->assertNotNull(Pattern::match('/a|/b', '/b'));
        $this->assertNull(Pattern::match('/a|/b', '/a/b'));
        $this->assertNull(Pattern::match('/a', '/xa'));
    }

    #[Test]
    public function a_tilde_in_a_pattern_is_an_ordinary_character(): void
    {
        $this->assertNotNull(Pattern::match('/~(\w+)', '/~ada'));
        $this->assertNotNull(Pattern::match('/\~(\w+)', '/~ada'));
    }

    #[Test]
    public function groups_come_back_by_number_and_name(): void
    {
        $groups = Pattern::match('/(?<year>\d+)/(\w+)', '/2024/hello');

        $this->assertSame('2024', $groups['year']);
        $this->assertSame('2024', $groups[1]);
        $this->assertSame('hello', $groups[2]);
    }

    #[Test]
    public function a_pattern_that_cannot_run_says_why(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Compilation failed/');

        Pattern::match('(unclosed', '/x');
    }

    #[Test]
    public function a_pattern_that_backtracks_without_end_is_cut_off(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backtrack limit exhausted');

        Pattern::match('/x/(a|aa)+', '/x/'.str_repeat('a', 60).'!');
    }

    #[Test]
    public function the_limit_it_lowers_is_put_back(): void
    {
        $before = ini_get('pcre.backtrack_limit');

        Pattern::match('/a', '/a');

        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> */
    public static function problems(): array
    {
        return [
            'fine' => ['/old/(.*)', '/new/$1', null],
            'fine with a name' => ['/(?<slug>.*)', '/new/${slug}', null],
            'does not compile' => ['/old/(', '/new', 'The pattern does not work: '],
            'too long' => ['/'.str_repeat('a', 500), '/new', 'The pattern is at most 500 characters.'],
            'backtracks' => ['(a+)+$', '/new', 'The pattern does not work: Backtrack limit exhausted'],
            'backtracks after a slash' => ['/(a+)+$', '/new', 'The pattern does not work: Backtrack limit exhausted'],
            'a group it lacks' => ['/old/(.*)', '/new/$2', 'The target uses $2, which the pattern has no group for.'],
            'a name it lacks' => ['/old/(.*)', '/new/${slug}', 'The target uses ${slug}, which the pattern has no group for.'],
            'the whole match' => ['/old', '/new/$0', null],
        ];
    }

    #[Test]
    #[DataProvider('problems')]
    public function a_pattern_is_checked_with_its_target(string $pattern, ?string $target, ?string $expected): void
    {
        $problem = Pattern::problem($pattern, $target);

        $expected === null ? $this->assertNull($problem) : $this->assertStringStartsWith($expected, (string) $problem);
    }

    #[Test]
    public function a_target_lands_on_this_site_or_a_web_address(): void
    {
        $this->assertNull(Target::problem('/news?page=2#top'));
        $this->assertNull(Target::problem('https://example.com/a?b=c'));
        $this->assertNull(Target::problem('http://example.com'));
        $this->assertStringContainsString('two slashes', Target::problem('//evil.example'));
        $this->assertStringContainsString('backslashes', Target::problem('/\\evil.example'));
        $this->assertStringContainsString('control characters', Target::problem("/a\r\nSet-Cookie: x"));
        $this->assertStringContainsString('spaces', Target::problem('/a b'));
        $this->assertStringContainsString('http://', Target::problem('javascript:alert(1)'));
        $this->assertStringContainsString('http://', Target::problem('news'));
        $this->assertStringContainsString('at most 2000', Target::problem('/'.str_repeat('a', 2000)));
    }

    #[Test]
    public function an_address_is_decoded_lowercase_and_without_trailing_or_doubled_slashes(): void
    {
        $this->assertSame('/a/b', Address::normalise('/A//B/'));
        $this->assertSame('/a b', Address::normalise('/A%20B'));
        $this->assertSame('/', Address::normalise('/'));
        $this->assertSame('/', Address::normalise(''));
        $this->assertSame('/a', Address::normalise('/a?x=1#y'));
        $this->assertSame(Address::hash('exact', '/A/'), Address::hash('exact', '/a'));
        $this->assertNotSame(Address::hash('regex', '/a'), Address::hash('exact', '/a'));
    }
}
