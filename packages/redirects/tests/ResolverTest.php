<?php

namespace Mainstay\Redirects\Tests;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mainstay\Redirects\Address;
use Mainstay\Redirects\Redirect;
use Mainstay\Redirects\RedirectLoop;
use Mainstay\Redirects\RedirectStore;
use Mainstay\Redirects\Resolver;
use PHPUnit\Framework\Attributes\Test;

/* What an address comes to, from the exact rules and the patterns. */
class ResolverTest extends RedirectsTestCase
{
    /** @return array{0: int, 1: ?string}|null */
    private function resolve(string $path): ?array
    {
        $resolution = app(Resolver::class)->resolve($path);

        return $resolution === null ? null : [$resolution->status, $resolution->target];
    }

    private function insertRaw(string $source, string $matchType, string $target, int $priority = 100): void
    {
        DB::table('mainstay_redirects')->insert([
            'source' => $source, 'source_hash' => Address::hash($matchType, $source), 'match_type' => $matchType, 'target' => $target,
            'status' => 301, 'origin' => 'custom', 'priority' => $priority, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[Test]
    public function an_exact_redirect_matches_the_address_whatever_its_case_or_trailing_slash(): void
    {
        $this->rule('/Old-Page/', '/new-page');

        $this->assertSame([301, '/new-page'], $this->resolve('/old-page'));
        $this->assertSame([301, '/new-page'], $this->resolve('/OLD-PAGE/'));
        $this->assertSame([301, '/new-page'], $this->resolve('/old%2Dpage'));
        $this->assertNull($this->resolve('/old-page/more'));
    }

    #[Test]
    public function the_source_is_stored_as_it_is_matched_and_hashed_once(): void
    {
        $rule = $this->rule('/Old-Page/', '/new');

        $this->assertSame('/old-page', $rule->source);
        $this->assertSame(sha1('/old-page'), $rule->source_hash);
    }

    #[Test]
    public function the_same_address_cannot_be_held_twice(): void
    {
        $this->rule('/a', '/b');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->rule('/a/', '/c');
    }

    #[Test]
    public function a_pattern_matches_the_whole_path_and_hands_its_groups_to_the_target(): void
    {
        $this->pattern('/old-blog/(.*)', '/articles/$1');

        $this->assertSame([301, '/articles/my-post'], $this->resolve('/old-blog/my-post'));
        $this->assertSame([301, '/articles/'], $this->resolve('/old-blog/'));
        $this->assertNull($this->resolve('/x/old-blog/my-post'), 'anchored at the start');
        $this->assertNull($this->resolve('/old-blogger'), 'and at the end');
    }

    #[Test]
    public function named_groups_and_braced_numbers_are_replaced(): void
    {
        $this->pattern('/(?<year>\d{4})/(?<slug>[a-z-]+)', '/archive/${year}/${slug}/${1}');

        $this->assertSame([301, '/archive/2024/hello/2024'], $this->resolve('/2024/hello'));
    }

    #[Test]
    public function a_captured_value_is_encoded_so_it_cannot_leave_the_site_or_split_a_header(): void
    {
        $this->pattern('/go/((?s).*)', '/$1');

        $this->assertSame([301, '/evil.example'], $this->resolve('/go//evil.example'));
        $this->assertSame([301, '/a%0D%0ALocation%3A%20x'], $this->resolve("/go/a\r\nLocation: x"));
    }

    #[Test]
    public function an_exact_redirect_is_tried_before_any_pattern_and_patterns_in_their_order(): void
    {
        $this->pattern('/docs/(.+)', '/second/$1', ['priority' => 20]);
        $this->pattern('/docs/(.*)', '/first/$1', ['priority' => 10]);
        $this->rule('/docs/intro', '/exact');

        $this->assertSame([301, '/exact'], $this->resolve('/docs/intro'));
        $this->assertSame([301, '/first/other'], $this->resolve('/docs/other'));
    }

    #[Test]
    public function equal_priorities_are_tried_in_the_order_they_were_made(): void
    {
        $this->pattern('/a/(.*)', '/one/$1');
        $this->pattern('/a/(.+)', '/two/$1');

        $this->assertSame([301, '/one/x'], $this->resolve('/a/x'));
    }

    #[Test]
    public function a_disabled_redirect_is_not_applied(): void
    {
        $this->rule('/off', '/on', ['enabled' => false]);
        $this->pattern('/p/(.*)', '/q/$1', ['enabled' => false]);

        $this->assertNull($this->resolve('/off'));
        $this->assertNull($this->resolve('/p/x'));
    }

    #[Test]
    public function the_status_is_the_rules_and_a_410_has_no_target(): void
    {
        $this->rule('/temp', '/elsewhere', ['status' => 307]);
        $this->rule('/gone', null);

        $this->assertSame([307, '/elsewhere'], $this->resolve('/temp'));
        $this->assertSame([410, null], $this->resolve('/gone'));
    }

    #[Test]
    public function a_chain_is_answered_in_one_hop_with_the_first_rules_status(): void
    {
        $this->rule('/a', '/b', ['status' => 302]);
        $this->rule('/b', '/c');

        $this->assertSame([302, '/c'], $this->resolve('/a'));
    }

    #[Test]
    public function a_chain_that_ends_in_410_is_gone_from_its_first_address(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/b', null);

        $this->assertSame([410, null], $this->resolve('/a'));
    }

    #[Test]
    public function a_chain_that_leaves_the_site_stops_there(): void
    {
        $this->rule('/a', 'https://example.com/b');
        $this->rule('/b', '/never');

        $this->assertSame([301, 'https://example.com/b'], $this->resolve('/a'));
    }

    #[Test]
    public function a_circle_is_a_loop_and_never_followed_for_ever(): void
    {
        $this->insertRaw('/a', 'exact', '/b');
        $this->insertRaw('/b', 'exact', '/a');

        $this->expectException(RedirectLoop::class);

        app(Resolver::class)->resolve('/a');
    }

    #[Test]
    public function a_pattern_that_fails_is_skipped_and_the_next_one_answers(): void
    {
        $this->insertRaw('(unclosed', 'regex', '/x', priority: 1);
        $this->pattern('/ok/(.*)', '/fine/$1');

        $this->assertSame([301, '/fine/y'], $this->resolve('/ok/y'));
        $this->assertNull($this->resolve('/nothing'));
    }

    #[Test]
    public function a_pattern_that_backtracks_without_end_is_cut_off_and_reported(): void
    {
        $this->insertRaw('/x/(a|aa)+', 'regex', '/y', priority: 1);
        $rule = Redirect::query()->sole();

        $this->assertNull(app(Resolver::class)->resolve('/x/'.str_repeat('a', 60).'!', reportFailures: true));
        $this->assertStringContainsString('Backtrack limit', Redirect::query()->findOrFail($rule->id)->last_error);
    }

    #[Test]
    public function a_path_that_is_not_utf8_matches_no_pattern_and_blames_none(): void
    {
        $this->pattern('/old/(.*)', '/new/$1');

        $this->assertNull(app(Resolver::class)->resolve('/old/%ff', reportFailures: true));
        $this->assertNull(Redirect::query()->sole()->last_error);
    }

    #[Test]
    public function the_cached_patterns_follow_every_change(): void
    {
        $rule = $this->pattern('/a/(.*)', '/b/$1');
        $this->assertSame([301, '/b/x'], $this->resolve('/a/x'));
        $this->assertTrue(Cache::has(RedirectStore::REGEX_CACHE_KEY));

        app(RedirectStore::class)->setEnabled([$rule->id], false);
        $this->assertFalse(Cache::has(RedirectStore::REGEX_CACHE_KEY));
        $this->assertNull($this->resolve('/a/x'));

        app(RedirectStore::class)->setEnabled([$rule->id], true);
        $rule->target = '/c/$1';
        app(RedirectStore::class)->save($rule);
        $this->assertSame([301, '/c/x'], $this->resolve('/a/x'));

        app(RedirectStore::class)->delete([$rule->id]);
        $this->assertNull($this->resolve('/a/x'));
    }

    #[Test]
    public function an_address_that_matches_nothing_costs_one_query_once_the_patterns_are_cached(): void
    {
        $this->pattern('/a/(.*)', '/b/$1');
        $this->resolve('/warm');

        DB::enableQueryLog();
        $this->resolve('/some/other/page');

        $this->assertCount(1, DB::getQueryLog());
    }

    #[Test]
    public function the_cache_is_flushed_on_a_driver_that_has_no_tags(): void
    {
        $path = sys_get_temp_dir().'/mainstay-redirects-cache-'.getmypid();
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
        Cache::purge('file');
        $this->pattern('/a/(.*)', '/b/$1');

        $this->assertSame([301, '/b/x'], $this->resolve('/a/x'));

        $this->pattern('/c/(.*)', '/d/$1');

        $this->assertSame([301, '/d/x'], $this->resolve('/c/x'));
        Cache::flush();
    }
}
