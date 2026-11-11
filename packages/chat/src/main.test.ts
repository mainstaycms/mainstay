import { afterEach, beforeEach, expect, test, vi } from 'vitest'

const CONVERSATION = '0198a000-0000-7000-8000-000000000001'

function mount(): HTMLElement {
  document.body.innerHTML = `
    <div data-chat data-send="/messages" data-show="/c/__id__" data-decide="/c/__id__/approvals" data-open="">
      <ul data-conversations></ul>
      <div data-transcript><p data-empty></p></div>
      <p data-error hidden></p>
      <form data-composer><textarea></textarea><button type="submit">Send</button></form>
    </div>`

  return document.querySelector<HTMLElement>('[data-chat]')!
}

function events(...lines: object[]): Response {
  const body = lines.map((line) => `data: ${JSON.stringify(line)}\n\n`).join('') + 'data: [DONE]\n\n'

  return new Response(body, { headers: { 'Content-Type': 'text/event-stream', 'X-Conversation-Id': CONVERSATION } })
}

async function send(text: string): Promise<void> {
  const composer = document.querySelector<HTMLFormElement>('[data-composer]')!
  composer.querySelector('textarea')!.value = text
  composer.dispatchEvent(new Event('submit', { cancelable: true }))
  await vi.waitFor(() => expect(composer.querySelector('button')!.disabled).toBe(false))
}

function sentBody(call: number): { conversation: string | null } {
  return JSON.parse((vi.mocked(fetch).mock.calls[call]![1] as RequestInit).body as string)
}

beforeEach(() => {
  vi.resetModules()
  vi.stubGlobal('fetch', vi.fn())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

test('a failure the server reports is shown as text and the screen stays usable', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(events({ type: 'error', message: '<b>The provider rejected the request (401).</b>', conversation_kept: false }))
  await import('./main')

  await send('Hi')

  const failure = document.querySelector<HTMLElement>('[data-error]')!
  expect(failure.hidden).toBe(false)
  expect(failure.textContent).toBe('<b>The provider rejected the request (401).</b>')
  expect(failure.querySelector('b')).toBeNull()
  expect(document.querySelector<HTMLTextAreaElement>('textarea')!.disabled).toBe(false)
})

test('a turn that stored nothing leaves no conversation to retry in', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(events({ type: 'error', message: 'Down.', conversation_kept: false })).mockResolvedValueOnce(events())
  await import('./main')

  await send('Hi')

  expect(document.querySelector('[data-conversation]')).toBeNull()
  expect(location.search).toBe('')

  await send('Hi again')

  expect(sentBody(1).conversation).toBeNull()
})

test('a turn that failed after it was stored keeps its conversation', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(events({ type: 'error', message: 'Down.', conversation_kept: true })).mockResolvedValueOnce(events())
  await import('./main')

  await send('Hi')

  expect(document.querySelector(`[data-conversation="${CONVERSATION}"]`)).not.toBeNull()

  await send('Hi again')

  expect(sentBody(1).conversation).toBe(CONVERSATION)
})

test('a streamed answer shows as markdown, with raw html left as text', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(events({ type: 'text_delta', delta: '# Title\n\n- **one**\n- two <img src=x onerror=alert(1)>\n\n' }, { type: 'text_delta', delta: '[docs](javascript:alert(1)) and [ok](https://example.com)' }))
  await import('./main')

  await send('Hi')

  const transcript = document.querySelector('[data-transcript]')!
  expect(transcript.querySelector('h1')!.textContent).toBe('Title')
  expect(transcript.querySelector('li strong')!.textContent).toBe('one')
  expect(transcript.querySelector('img')).toBeNull()
  expect(transcript.textContent).toContain('<img src=x onerror=alert(1)>')
  expect(Array.from(transcript.querySelectorAll('a')).map((anchor) => anchor.getAttribute('href'))).toEqual(['https://example.com/'])
})

test('a saved conversation renders its answers as markdown and its questions as text', async () => {
  mount().dataset.open = CONVERSATION
  vi.mocked(fetch).mockResolvedValueOnce(
    Response.json({
      messages: [
        { role: 'user', content: 'a **question**', tool_calls: [] },
        { role: 'assistant', content: 'An `answer` with\n\n```\ncode\n```', tool_calls: [] },
      ],
    }),
  )
  await import('./main')

  await vi.waitFor(() => expect(document.querySelector('[data-transcript] code')).not.toBeNull())

  const transcript = document.querySelector('[data-transcript]')!
  expect(transcript.querySelectorAll('code')).toHaveLength(2)
  expect(transcript.querySelectorAll('strong')).toHaveLength(0)
  expect(transcript.textContent).toContain('a **question**')
})
