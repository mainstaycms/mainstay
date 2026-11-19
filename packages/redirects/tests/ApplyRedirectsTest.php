<?php

namespace Mainstay\Redirects\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Mainstay\Facades\Mainstay;
use Mainstay\Redirects\Address;
use Mainstay\Redirects\Miss;
use Mainstay\Redirects\MissLog;
use Mainstay\Redirects\Redirect;
use PHPUnit\Framework\Attributes\Test;

/* The redirects as a visitor meets them, and the 404s the same middleware counts. */
class ApplyRedirectsTest extends RedirectsTestCase
{
    private const BOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private function miss(string $address): ?Miss
    {
        return Miss::query()->where('address_hash', Address::hash(Redirect::EXACT, $address))->first();
    }

    #[Test]
    public function a_request_for_a_redirected_address_is_answered_before_routing(): void
    {
        $this->rule('/old', '/new');

        $this->get('/old')->assertStatus(301)->assertRedirect('/new');
    }

    #[Test]
    public function the_status_the_rule_holds_is_the_one_sent(): void
    {
        foreach ([302, 307, 308] as $status) {
            $this->rule("/s{$status}", '/new', ['status' => $status]);

            $this->get("/s{$status}")->assertStatus($status);
        }
    }

    #[Test]
    public function the_query_string_is_kept_where_the_rule_keeps_it(): void
    {
        $this->rule('/keep', '/new');
        $this->rule('/mix', '/new?a=1#top');
        $this->rule('/drop', '/new', ['preserve_query' => false]);

        $this->get('/keep?utm=mail&x=1')->assertRedirect('/new?utm=mail&x=1');
        $this->get('/mix?utm=mail')->assertRedirect('/new?a=1&utm=mail#top');
        $this->get('/drop?utm=mail')->assertRedirect('/new');
        $this->get('/keep')->assertRedirect('/new');
    }

    #[Test]
    public function a_gone_address_answers_410_with_a_minimal_body(): void
    {
        $this->rule('/gone', null);

        $response = $this->get('/gone')->assertStatus(410);

        $this->assertSame("Gone\n", $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $this->head('/gone')->assertStatus(410);
    }

    #[Test]
    public function a_pattern_redirects_every_address_it_matches(): void
    {
        $this->pattern('/old-blog/(.*)', '/articles/$1');

        $this->get('/old-blog/my-post')->assertRedirect('/articles/my-post');
        $this->get('/old-blog/2024/my-post?x=1')->assertRedirect('/articles/2024/my-post?x=1');
    }

    #[Test]
    public function a_chain_is_answered_in_one_redirect(): void
    {
        $this->rule('/a', '/b');
        $this->rule('/b', '/c');

        $this->get('/a')->assertRedirect('/c');
    }

    #[Test]
    public function head_is_redirected_and_other_methods_are_left_alone(): void
    {
        $this->rule('/old', '/new');

        $this->head('/old')->assertStatus(301);
        $this->post('/old')->assertStatus(405);
        $this->put('/old')->assertStatus(405);
        $this->delete('/old')->assertStatus(405);
    }

    #[Test]
    public function the_admin_the_api_and_the_files_the_server_hands_out_are_not_redirected(): void
    {
        $this->pattern('/((?!caught).*)', '/caught/$1', ['priority' => 1]);
        $this->rule('/admin/login', '/caught');

        $this->get(route('mainstay.login'))->assertOk();
        $this->assertNotSame(301, $this->get('/api/mainstay/types')->getStatusCode());
        $this->assertNotSame(301, $this->get('/storage/photo.jpg')->getStatusCode());
        $this->assertNotSame(301, $this->get('/vendor/mainstay/mainstay.css')->getStatusCode());
        $this->get('/anything-else')->assertStatus(301);
    }

    #[Test]
    public function the_pages_of_the_site_are_answered_by_the_site(): void
    {
        $this->makePost('hello');
        $this->rule('/elsewhere', '/blog/hello');

        $this->get('/blog/hello')->assertOk();
        $this->get('/elsewhere')->assertRedirect('/blog/hello');
    }

    #[Test]
    public function a_redirect_counts_its_hits_and_when_the_last_was(): void
    {
        $rule = $this->rule('/old', '/new');
        $gone = $this->rule('/gone', null);

        $this->get('/old');
        $this->get('/old');
        $this->get('/gone');

        $rule->refresh();
        $this->assertSame(2, $rule->hits);
        $this->assertNotNull($rule->last_hit_at);
        $this->assertSame(1, $gone->refresh()->hits);
    }

    #[Test]
    public function a_circle_is_let_through_and_written_on_the_rule(): void
    {
        foreach (['/a' => '/b', '/b' => '/a'] as $source => $target) {
            DB::table('mainstay_redirects')->insert(['source' => $source, 'source_hash' => sha1($source), 'match_type' => 'exact', 'target' => $target, 'status' => 301, 'origin' => 'custom', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->get('/a')->assertNotFound();

        $this->assertStringContainsString('circle', Redirect::query()->where('source', '/a')->value('last_error'));
    }

    #[Test]
    public function a_failing_pattern_is_written_on_the_rule_once_and_the_request_goes_on(): void
    {
        DB::table('mainstay_redirects')->insert(['source' => '/x/(a|aa)+', 'source_hash' => sha1('regex:/x/(a|aa)+'), 'match_type' => 'regex', 'target' => '/y', 'status' => 301, 'origin' => 'custom', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/x/'.str_repeat('a', 60).'!')->assertNotFound();

        $this->assertStringContainsString('Backtrack limit', Redirect::query()->value('last_error'));
    }

    #[Test]
    public function a_404_is_counted_per_address_whatever_its_case_slash_or_query(): void
    {
        $this->get('/nope', ['Referer' => 'https://example.com/a'])->assertNotFound();
        $this->get('/Nope/?x=1')->assertNotFound();
        $this->get('/other')->assertNotFound();

        $this->assertSame(2, Miss::query()->count());
        $this->assertSame(2, $this->miss('/nope')->hits);
        $this->assertSame('/nope', $this->miss('/nope')->address);
        $this->assertSame('https://example.com/a', $this->miss('/nope')->referrer);
        $this->assertSame(1, $this->miss('/other')->hits);
    }

    #[Test]
    public function what_the_site_answers_is_not_a_miss(): void
    {
        $this->makePost('hello');
        $this->rule('/old', '/new');
        $this->rule('/gone', null);

        $this->get('/blog/hello');
        $this->get('/old');
        $this->get('/gone');

        $this->assertSame(0, Miss::query()->count());
    }

    #[Test]
    public function robots_files_the_admin_the_api_and_other_methods_are_not_counted(): void
    {
        $this->get('/nope', ['User-Agent' => self::BOT]);
        $this->get('/missing.png');
        $this->get('/missing.css');
        $this->get('/admin/missing');
        $this->get('/api/mainstay/missing');
        $this->post('/nope-post');

        $this->assertSame(0, Miss::query()->count());
    }

    #[Test]
    public function recording_can_be_switched_off(): void
    {
        Mainstay::setSetting('redirects.record_misses', false);

        $this->get('/nope')->assertNotFound();

        $this->assertSame(0, Miss::query()->count());
    }

    #[Test]
    public function no_new_address_is_recorded_beyond_the_cap_but_known_ones_still_count(): void
    {
        Mainstay::setSetting('redirects.max_misses', 2);

        foreach (['/one', '/two', '/three', '/one'] as $path) {
            $this->get($path);
        }

        $this->assertSame(2, Miss::query()->count());
        $this->assertNull($this->miss('/three'));
        $this->assertSame(2, $this->miss('/one')->hits);
    }

    #[Test]
    public function an_address_with_a_redirect_made_is_forgotten_by_the_log(): void
    {
        $this->get('/nope');
        $this->assertNotNull($this->miss('/nope'));

        app(MissLog::class)->forget('/Nope/');

        $this->assertNull($this->miss('/nope'));
    }

    #[Test]
    public function the_log_drops_what_is_past_its_retention_and_what_is_over_its_cap(): void
    {
        Mainstay::setSetting('redirects.keep_days', 30);
        Mainstay::setSetting('redirects.max_misses', 3);

        foreach (['/old' => 100, '/a' => 5, '/b' => 4, '/c' => 3, '/d' => 2, '/e' => 1] as $address => $daysAgo) {
            Miss::query()->create(['address' => $address, 'address_hash' => Address::hash('exact', $address), 'first_seen_at' => now()->subDays($daysAgo), 'last_seen_at' => now()->subDays($daysAgo)]);
        }

        $this->artisan('mainstay:redirects:prune')->assertSuccessful();

        $this->assertSame(['/c', '/d', '/e'], Miss::query()->orderBy('address')->pluck('address')->all());
    }

    #[Test]
    public function the_prune_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'mainstay:redirects:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
