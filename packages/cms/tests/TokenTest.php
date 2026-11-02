<?php

namespace Mainstay\Tests;

use Illuminate\Database\RecordNotFoundException;
use Illuminate\Validation\ValidationException;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Role;
use Mainstay\Auth\Token;
use Mainstay\Auth\Tokens;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Api\Colophon;
use Mainstay\Tests\Fixtures\Api\Essay;
use Mainstay\Tests\Fixtures\Api\Essayist;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use PHPUnit\Framework\Attributes\Test;

/*
 | Tokens as an account makes them: never holding what the account could
 | not hand out, from the layer, the admin's screen and the console alike;
 | the secret shown once and kept only as its hash; and another account's
 | tokens not there to see or revoke.
 */
class TokenTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Essay::class, Essayist::class, Colophon::class, Person::class, Story::class, Genre::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    private function user(array $capabilities = []): User
    {
        $n = ++$this->made;
        $role = Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities]);

        return User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    private function signIn(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function refused(callable $issue): string
    {
        try {
            $issue();
        } catch (ValidationException $exception) {
            return $exception->validator->errors()->first('capabilities');
        }

        $this->fail('The token was made.');
    }

    #[Test]
    public function a_token_holds_no_capability_its_account_does_not(): void
    {
        $user = $this->user(['edit_essays']);
        $issue = fn (array $capabilities) => Mainstay::tokens()->issue($user->id, 'Build', $capabilities, overrideAccess: true);

        [$token, $secret] = $issue(['edit_essays', 'edit_essays']);
        $this->assertSame(['edit_essays'], $token->fresh()->capabilities);
        $this->assertMatchesRegularExpression('/\A'.Tokens::PREFIX.'[A-Za-z0-9]{48}\z/', $secret);
        $this->assertSame(hash('sha256', $secret), Token::query()->whereKey($token->id)->value('token'));
        $this->assertArrayNotHasKey('token', $token->toArray());

        $this->assertSame('This hands out publish_essays, which Ada 1 does not hold.', $this->refused(fn () => $issue(['publish_essays'])));
        $this->assertSame('Only an account holding everything can make a token that does.', $this->refused(fn () => $issue(['*'])));
        $this->assertSame('No registered type or plugin derives fly_kites.', $this->refused(fn () => $issue(['fly_kites'])));
        $this->assertSame(1, Token::query()->count());

        $everything = Mainstay::tokens()->issue($this->user(['*'])->id, 'All', ['*', 'edit_essays'], overrideAccess: true)[0];
        $this->assertSame(['*'], $everything->fresh()->capabilities);
    }

    #[Test]
    public function the_screen_offers_what_the_account_holds_and_shows_the_secret_once(): void
    {
        $this->signIn($user = $this->user(['edit_essays']));

        $page = $this->get(route('mainstay.tokens'))->assertOk()->assertSee('None yet.');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*value="edit_essays"[^>]*disabled/', $page->getContent());
        $this->assertMatchesRegularExpression('/<input[^>]*value="publish_essays"[^>]*disabled/', $page->getContent());
        $this->assertMatchesRegularExpression('/<input[^>]*value="manage_genres"[^>]*disabled/', $page->getContent());
        $this->assertStringNotContainsString('value="upload_media"', $page->getContent());

        $this->post(route('mainstay.tokens.store'), ['name' => 'CI build', 'capabilities' => ['edit_essays']])->assertRedirect(route('mainstay.tokens'));
        $made = $this->get(route('mainstay.tokens'))->assertOk()->assertSee('CI build')->assertSee('edit_essays');
        $this->assertMatchesRegularExpression('/value="'.Tokens::PREFIX.'[A-Za-z0-9]{48}"/', $made->getContent());
        $this->assertNotNull(Mainstay::tokens()->find(preg_match('/value="('.Tokens::PREFIX.'\w+)"/', $made->getContent(), $secret) ? $secret[1] : ''));

        $this->assertDoesNotMatchRegularExpression('/'.Tokens::PREFIX.'[A-Za-z0-9]{48}/', $this->get(route('mainstay.tokens'))->getContent());

        $this->post(route('mainstay.tokens.store'), ['name' => 'Greedy', 'capabilities' => ['publish_essays']])->assertSessionHasErrors('capabilities');
        $this->assertSame(1, Token::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function another_accounts_tokens_are_not_there_to_see_or_revoke(): void
    {
        $theirs = Mainstay::tokens()->issue($this->user()->id, 'Theirs', [], overrideAccess: true)[0];
        $this->signIn($this->user());

        $this->get(route('mainstay.tokens'))->assertDontSee('Theirs');
        $this->post(route('mainstay.tokens.destroy', $theirs->id))->assertNotFound();
        $this->assertTrue(Token::query()->whereKey($theirs->id)->exists());

        $this->app['request']->attributes->set(Gate::USER, auth('mainstay')->user());
        $this->assertCount(0, Mainstay::tokens()->of($theirs->user_id));
        $this->expectException(RecordNotFoundException::class);
        Mainstay::tokens()->issue($theirs->user_id, 'Theirs too', []);
    }

    #[Test]
    public function an_account_revokes_its_own_tokens(): void
    {
        $this->signIn($this->user());

        $mine = Mainstay::tokens()->issue(auth('mainstay')->id(), 'Mine', [], overrideAccess: true)[0];
        $this->post(route('mainstay.tokens.destroy', $mine->id))->assertRedirect(route('mainstay.tokens'));
        $this->assertFalse(Token::query()->whereKey($mine->id)->exists());
    }

    #[Test]
    public function the_command_makes_a_token_within_what_its_account_holds(): void
    {
        $this->user(['edit_essays']);

        $this->artisan('mainstay:token', ['email' => 'ADA-1@example.com', 'name' => 'CI', '--capability' => ['edit_essays']])
            ->expectsOutputToContain(Tokens::PREFIX)
            ->assertSuccessful();
        $this->assertSame(['edit_essays'], Token::query()->sole()->capabilities);

        $this->artisan('mainstay:token', ['email' => 'ada-1@example.com', 'name' => 'CI', '--capability' => ['publish_essays']])
            ->expectsOutputToContain('does not hold')
            ->assertFailed();
        $this->artisan('mainstay:token', ['email' => 'nobody@example.com', 'name' => 'CI'])->assertFailed();
        $this->assertSame(1, Token::query()->count());
    }
}
