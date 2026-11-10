{{-- The server's address, to copy into a client, and its tools. --}}
<label class="block text-sm text-muted" for="mcp-endpoint">Endpoint</label>
<input id="mcp-endpoint" type="text" readonly value="{{ $endpoint }}" class="mt-1 w-full rounded-control border border-border bg-surface px-2 py-1 font-mono text-xs">
<p class="mt-2 text-sm"><span class="font-medium">{{ $tools }}</span> <span class="text-muted">{{ $tools === 1 ? 'tool' : 'tools' }}</span></p>
<p class="mt-2 text-sm"><a href="{{ $tokens }}" class="text-accent hover:underline">Make an API token</a></p>
