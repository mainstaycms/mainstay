// What laravel/ai streams, as the chat reads it: the events it draws from.
export type StreamEvent =
  | { type: 'text_delta'; delta: string }
  | { type: 'tool_call'; tool_id: string; tool_name: string; arguments: Record<string, unknown> }
  | { type: 'tool_result'; tool_id: string; result: string; denied?: boolean; successful?: boolean }
  | { type: 'tool_approval_request'; approvals: { id: string; tool: string; arguments: Record<string, unknown> }[] }
  | { type: 'error'; message: string; conversation_kept: boolean }
  | { type: string }

const DONE = '[DONE]'

// Server-sent events from a fetch body: each event is `data: <json>` and a
// blank line, and `[DONE]` ends them. Not EventSource, which cannot POST or
// send the CSRF header.
export async function* readEvents(body: ReadableStream<Uint8Array>): AsyncGenerator<StreamEvent> {
  const reader = body.getReader()
  const decoder = new TextDecoder()
  let buffered = ''

  for (;;) {
    const { done, value } = await reader.read()
    buffered += decoder.decode(value, { stream: !done })
    const blocks = buffered.split('\n\n')
    buffered = blocks.pop() ?? ''

    for (const block of blocks) {
      const data = block.startsWith('data: ') ? block.slice('data: '.length) : ''

      if (data === DONE) {
        return
      }

      if (data !== '') {
        yield JSON.parse(data) as StreamEvent
      }
    }

    if (done) {
      return
    }
  }
}
