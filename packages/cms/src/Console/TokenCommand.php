<?php

namespace Mainstay\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Mainstay\Auth\User;
use Mainstay\Mainstay;

/*
 | A token for a program on a server -- a CI build reading the site, an MCP
 | client -- made for an account on the server's authority and still holding
 | no capability the account does not. The secret is printed this once.
 */
class TokenCommand extends Command
{
    protected $signature = 'mainstay:token
        {email : The account the token works for}
        {name : What the token is for, as its account sees it listed}
        {--capability=* : A capability it holds, as many times as it holds them; none reads, * is everything the account holds}';

    protected $description = 'Make an API token for a Mainstay account';

    public function handle(Mainstay $mainstay): int
    {
        $user = User::query()->where('email', mb_strtolower($this->argument('email')))->first();

        if ($user === null) {
            $this->components->error("{$this->argument('email')} has no account.");

            return self::FAILURE;
        }

        try {
            [, $secret] = $mainstay->tokens()->issue($user->id, $this->argument('name'), $this->option('capability'), overrideAccess: true);
        } catch (ValidationException $exception) {
            $this->components->bulletList($exception->validator->errors()->all());

            return self::FAILURE;
        }

        $this->components->info('The token, shown this once:');
        $this->line($secret);

        return self::SUCCESS;
    }
}
