<?php

namespace Mainstay\Mcp;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider;
use Mainstay\Http\AuthenticateToken;
use Mainstay\Plugin;

/*
 | The content API as an MCP server at `/mcp` beside it, behind the same
 | tokens: a token that only edits drafts can write drafts and never publish
 | them. It has no admin screens, so it asks no capability of its own.
 */
class McpPlugin extends Plugin
{
    public static function handle(): string
    {
        return 'mcp_server';
    }

    /*
     | laravel/mcp's provider is registered here, explicitly rather than
     | through package discovery, since a host turning discovery off for it
     | would otherwise fail on the facade. The route sits under the API's
     | domain, prefix and middleware, which the API's own routes file does not
     | reach.
     */
    public function boot(Application $app): void
    {
        $app->register(McpServiceProvider::class);

        Route::group([
            'domain' => config('mainstay.domain'),
            'prefix' => config('mainstay.api.prefix'),
            'middleware' => config('mainstay.api.middleware'),
        ], function () {
            Route::middleware(AuthenticateToken::class)->group(function () {
                Mcp::web('mcp', Server::class)->name('mainstay.api.mcp');
            });
        });
    }
}
