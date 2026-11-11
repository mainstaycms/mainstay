import { afterEach, beforeEach, expect, test, vi } from 'vitest'

const CONVERSATION = '0198a000-0000-7000-8000-000000000001'

function mount(): HTMLElement {
  document.body.innerHTML = `
    <button data-chat-open title="Chat (Ctrl+.)">Chat</button>
    <dialog id="chat-dialog">
      <div data-chat data-conversations-url="/conversations" data-send="/messages" data-show="/c/__id__" data-decide="/c/__id__/approvals">
        <section data-unconnected hidden>No AI provider is connected</section>
        <nav data-list><ul data-conversations></ul><p data-no-conversations hidden></p></nav>
        <section data-conversation-pane>
          <button data-toggle-list aria-expanded="false">Conversations</button>
          <button data-new-chat>New chat</button>
          <div data-transcript><p data-empty>Ask for a change.</p></div>
          <p data-error hidden></p>
          <form data-composer><textarea></textarea><button type="submit">Send</button></form>
        </section>
      </div>
    </dialog>`

  return document.querySelector<HTMLElement>('[data-chat]')!
}

function chatDialog(): HTMLDialogElement {
  return document.querySelector<HTMLDialogElement>('#chat-dialog')!
}

function listing(ready: boolean, ...conversations: { id: string; title: string }[]): Response {
  return Response.json({ ready, conversations })
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
  sessionStorage.clear()

  /* jsdom has the element and not its modal behaviour. */
  HTMLDialogElement.prototype.showModal = function () {
    this.setAttribute('open', '')
  }
  HTMLDialogElement.prototype.close = function () {
    this.removeAttribute('open')
  }
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
  expect(sessionStorage.getItem('mainstay.chat.conversation')).toBeNull()

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
  sessionStorage.setItem('mainstay.chat.conversation', CONVERSATION)
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(listing(true, { id: CONVERSATION, title: 'Hi' })).mockResolvedValueOnce(
    Response.json({
      messages: [
        { role: 'user', content: 'a **question**', tool_calls: [] },
        { role: 'assistant', content: 'An `answer` with\n\n```\ncode\n```', tool_calls: [] },
      ],
    }),
  )
  await import('./main')
  chatDialog().showModal()

  await vi.waitFor(() => expect(document.querySelector('[data-transcript] code')).not.toBeNull())

  const transcript = document.querySelector('[data-transcript]')!
  expect(transcript.querySelectorAll('code')).toHaveLength(2)
  expect(transcript.querySelectorAll('strong')).toHaveLength(0)
  expect(transcript.textContent).toContain('a **question**')
})

test('opening the dialog loads the conversations once and remembers it is open; closing forgets', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(listing(true, { id: CONVERSATION, title: 'Older' }))
  await import('./main')

  chatDialog().showModal()
  await vi.waitFor(() => expect(document.querySelector(`[data-conversation="${CONVERSATION}"]`)!.textContent).toBe('Older'))
  expect(sessionStorage.getItem('mainstay.chat.open')).toBe('1')

  chatDialog().close()
  await vi.waitFor(() => expect(sessionStorage.getItem('mainstay.chat.open')).toBeNull())

  chatDialog().showModal()
  await vi.waitFor(() => expect(sessionStorage.getItem('mainstay.chat.open')).toBe('1'))
  expect(fetch).toHaveBeenCalledTimes(1)
})

test('the dialog is open again after a page load where it was open', async () => {
  sessionStorage.setItem('mainstay.chat.open', '1')
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(listing(true))
  await import('./main')

  expect(chatDialog().open).toBe(true)
  await vi.waitFor(() => expect(fetch).toHaveBeenCalledWith('/conversations', expect.anything()))
})

test('the shortcut toggles the dialog', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValue(listing(true))
  await import('./main')

  document.dispatchEvent(new KeyboardEvent('keydown', { key: '.', ctrlKey: true, cancelable: true }))
  expect(chatDialog().open).toBe(true)

  document.dispatchEvent(new KeyboardEvent('keydown', { key: '.', metaKey: true, cancelable: true }))
  expect(chatDialog().open).toBe(false)

  document.dispatchEvent(new KeyboardEvent('keydown', { key: '.', cancelable: true }))
  expect(chatDialog().open).toBe(false)
})

test('the chat works when storage throws', async () => {
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new DOMException('denied', 'SecurityError')
  })
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
    throw new DOMException('denied', 'SecurityError')
  })
  vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
    throw new DOMException('denied', 'SecurityError')
  })
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(listing(true)).mockResolvedValueOnce(events({ type: 'text_delta', delta: 'Hello' }))
  await import('./main')

  chatDialog().showModal()
  await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))
  chatDialog().close()
  await send('Hi')

  expect(document.querySelector('[data-transcript]')!.textContent).toContain('Hello')
  vi.restoreAllMocks()
})

test('without a connected provider the dialog says so and offers no conversation', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(listing(false))
  await import('./main')

  chatDialog().showModal()

  await vi.waitFor(() => expect(document.querySelector<HTMLElement>('[data-unconnected]')!.hidden).toBe(false))
  expect(document.querySelector<HTMLElement>('[data-conversation-pane]')!.hidden).toBe(true)
})

test('an answer streaming when the dialog closes is in the transcript when it opens again', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(listing(true)).mockResolvedValueOnce(events({ type: 'text_delta', delta: 'Done while closed' }))
  await import('./main')
  chatDialog().showModal()
  await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))

  const sending = send('Hi')
  chatDialog().close()
  await sending

  chatDialog().showModal()
  expect(document.querySelector('[data-transcript]')!.textContent).toContain('Done while closed')
  expect(fetch).toHaveBeenCalledTimes(2)
})

test('a conversation that is gone is dropped from storage and says so', async () => {
  sessionStorage.setItem('mainstay.chat.conversation', CONVERSATION)
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(listing(true)).mockResolvedValueOnce(new Response('', { status: 404 }))
  await import('./main')

  chatDialog().showModal()

  await vi.waitFor(() => expect(document.querySelector<HTMLElement>('[data-error]')!.textContent).toBe('That conversation is not there.'))
  expect(sessionStorage.getItem('mainstay.chat.conversation')).toBeNull()
})

test('a new chat starts empty with no conversation to continue', async () => {
  mount()
  vi.mocked(fetch).mockResolvedValueOnce(events({ type: 'text_delta', delta: 'Hello' })).mockResolvedValueOnce(events())
  await import('./main')
  await send('Hi')

  document.querySelector<HTMLButtonElement>('[data-new-chat]')!.click()

  expect(document.querySelector('[data-transcript]')!.textContent).toBe('Ask for a change.')
  expect(sessionStorage.getItem('mainstay.chat.conversation')).toBeNull()

  await send('Again')
  expect(sentBody(1).conversation).toBeNull()
})
