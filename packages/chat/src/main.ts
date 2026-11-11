import { readEvents } from './events'
import { Transcript } from './transcript'
import './index.css'

type StoredToolCall = { id: string; name: string; arguments: Record<string, unknown>; result?: string; denied?: boolean; approval_reason?: string | null }
type StoredMessage = { role: string; content: string | null; tool_calls: StoredToolCall[] }

const CONVERSATION_HEADER = 'X-Conversation-Id'
const CONVERSATION_KEY = 'mainstay.chat.conversation'
const OPEN_KEY = 'mainstay.chat.open'
const CONVERSATION_PATTERN = /^[0-9a-f-]{36}$/i

// Per-viewer conveniences only: storage can be absent or throw, and the chat works without it.
function stored(key: string): string | null {
  try {
    return sessionStorage.getItem(key)
  } catch {
    return null
  }
}

function store(key: string, value: string | null): void {
  try {
    if (value === null) {
      sessionStorage.removeItem(key)
    } else {
      sessionStorage.setItem(key, value)
    }
  } catch {
    // Without storage the dialog just starts closed and empty on the next page.
  }
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

// The chat dialog: one conversation open at a time, its answer streamed in,
// and what the model asks to write held for the person's Approve or Reject.
// A run still streaming when the dialog closes keeps running; its answer is
// in the transcript when the dialog opens again.
function mountChat(dialog: HTMLDialogElement, root: HTMLElement): void {
  const composer = root.querySelector<HTMLFormElement>('[data-composer]')!
  const message = composer.querySelector<HTMLTextAreaElement>('textarea')!
  const send = composer.querySelector<HTMLButtonElement>('button')!
  const failure = root.querySelector<HTMLElement>('[data-error]')!
  const list = root.querySelector<HTMLElement>('[data-conversations]')!
  const emptyText = root.querySelector<HTMLElement>('[data-empty]')!.textContent ?? ''
  const unconnected = root.querySelector<HTMLElement>('[data-unconnected]')!
  const conversationList = root.querySelector<HTMLElement>('[data-list]')!
  const listToggle = root.querySelector<HTMLButtonElement>('[data-toggle-list]')!
  const newChat = root.querySelector<HTMLButtonElement>('[data-new-chat]')!
  const pane = root.querySelector<HTMLElement>('[data-conversation-pane]')!
  const urls = (id: string, template: string | undefined) => (template ?? '').replace('__id__', id)

  // Only an id, since it goes into a URL: storage is anyone's to write.
  const storedConversation = stored(CONVERSATION_KEY)
  let conversation = CONVERSATION_PATTERN.test(storedConversation ?? '') ? storedConversation : null
  let loaded = false
  let streaming = false
  const decisions = new Map<string, boolean>()
  const pending = new Set<string>()
  const transcriptElement = root.querySelector<HTMLElement>('[data-transcript]')!
  const transcript = new Transcript(transcriptElement, (toolId, approved) => decide(toolId, approved))

  // The newest line stays in view as it streams, until the person scrolls up to read.
  let followsEnd = true
  const NEAR_END = 40
  transcriptElement.addEventListener('scroll', () => {
    followsEnd = transcriptElement.scrollHeight - transcriptElement.scrollTop - transcriptElement.clientHeight < NEAR_END
  })
  new MutationObserver(() => {
    if (followsEnd) {
      transcriptElement.scrollTop = transcriptElement.scrollHeight
    }
  }).observe(transcriptElement, { childList: true, subtree: true, characterData: true })

  function busy(working: boolean): void {
    send.disabled = working
    message.disabled = working
  }

  // Switching conversation mid-answer would draw the answer in the wrong one.
  function setStreaming(running: boolean): void {
    streaming = running
    newChat.disabled = running

    for (const item of list.querySelectorAll<HTMLButtonElement>('[data-conversation]')) {
      item.disabled = running
    }
  }

  function fail(text: string): void {
    failure.textContent = text
    failure.hidden = false
  }

  function select(id: string | null): void {
    conversation = id
    store(CONVERSATION_KEY, id)

    for (const item of list.querySelectorAll<HTMLElement>('[data-conversation]')) {
      if (item.dataset.conversation === id) {
        item.setAttribute('aria-current', 'true')
      } else {
        item.removeAttribute('aria-current')
      }
    }
  }

  function addToList(id: string, title: string): void {
    const item = document.createElement('li')
    item.className = 'border-t border-border'
    const button = document.createElement('button')
    button.type = 'button'
    button.dataset.conversation = id
    button.className = 'block w-full truncate px-5 py-3 text-left text-sm hover:bg-surface focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent aria-[current=true]:bg-surface aria-[current=true]:font-medium disabled:opacity-50'
    button.textContent = title
    item.append(button)
    list.prepend(item)
    root.querySelector<HTMLElement>('[data-no-conversations]')!.hidden = true
  }

  function remember(id: string, title: string): void {
    if (list.querySelector(`[data-conversation="${id}"]`) === null) {
      addToList(id, title)
    }

    select(id)
  }

  // A turn that failed before anything was stored leaves no conversation behind for the id the response named.
  function forget(id: string): void {
    select(null)
    list.querySelector(`[data-conversation="${id}"]`)?.parentElement?.remove()
  }

  async function stream(response: Response, title: string): Promise<void> {
    if (!response.ok || response.body === null) {
      fail(((await response.json().catch(() => null)) as { message?: string } | null)?.message ?? `The request failed (${response.status}).`)

      return
    }

    const id = response.headers.get(CONVERSATION_HEADER)

    if (id !== null) {
      remember(id, title)
    }

    for await (const event of readEvents(response.body)) {
      switch (event.type) {
        case 'text_delta':
          transcript.append((event as { delta: string }).delta)
          break
        case 'tool_call': {
          const call = event as { tool_id: string; tool_name: string; arguments: Record<string, unknown> }
          transcript.toolCall(call.tool_id, call.tool_name, call.arguments)
          break
        }
        case 'tool_result': {
          const result = event as { tool_id: string; result: string; denied?: boolean }
          transcript.toolResult(result.tool_id, result.result, result.denied)
          break
        }
        case 'tool_approval_request':
          for (const approval of (event as { approvals: { id: string; tool: string; arguments: Record<string, unknown> }[] }).approvals) {
            pending.add(approval.id)
            transcript.needsApproval(approval.id, approval.tool, approval.arguments)
          }
          break
        case 'error': {
          const failure = event as { message: string; conversation_kept: boolean }
          fail(failure.message)

          if (id !== null && !failure.conversation_kept) {
            forget(id)
          }

          break
        }
      }
    }
  }

  async function post(url: string, body: unknown): Promise<Response> {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'X-CSRF-TOKEN': csrfToken() },
      body: JSON.stringify(body),
    })
  }

  async function run(request: () => Promise<Response>, title: string): Promise<void> {
    failure.hidden = true
    busy(true)
    setStreaming(true)

    try {
      await stream(await request(), title)
    } catch (error) {
      fail(error instanceof Error ? error.message : 'The connection broke.')
    } finally {
      setStreaming(false)
      busy(transcript.waiting)
    }
  }

  // The model's run resumes once every call it paused on has an answer.
  function decide(toolId: string, approved: boolean): void {
    decisions.set(toolId, approved)
    transcript.decided(toolId, approved)

    if (![...pending].every((id) => decisions.has(id))) {
      return
    }

    const answers = Object.fromEntries(decisions)
    decisions.clear()
    pending.clear()
    void run(() => post(urls(conversation!, root.dataset.decide), { decisions: answers }), '')
  }

  function show(stored: StoredMessage[]): void {
    for (const entry of stored) {
      if (entry.role === 'user') {
        transcript.user(entry.content ?? '')
        continue
      }

      for (const call of entry.tool_calls) {
        transcript.toolCall(call.id, call.name, call.arguments)

        if (call.result !== undefined) {
          transcript.toolResult(call.id, call.result, call.denied)
        } else if (call.approval_reason !== undefined) {
          pending.add(call.id)
          transcript.needsApproval(call.id, call.name, call.arguments)
        }
      }

      transcript.append(entry.content ?? '')
    }
  }

  composer.addEventListener('submit', (event) => {
    event.preventDefault()
    const text = message.value.trim()

    if (text === '') {
      return
    }

    root.querySelector('[data-empty]')?.remove()
    transcript.user(text)
    message.value = ''
    void run(() => post(root.dataset.send ?? '', { message: text, conversation }), text)
  })

  message.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
      composer.requestSubmit()
    }
  })

  function reset(): void {
    followsEnd = true
    transcript.clear()
    pending.clear()
    decisions.clear()
    failure.hidden = true
    busy(false)
  }

  async function open(id: string): Promise<void> {
    reset()
    select(id)
    conversationList.removeAttribute('data-open')

    try {
      const response = await fetch(urls(id, root.dataset.show), { headers: { Accept: 'application/json' } })

      if (!response.ok) {
        throw new Error('That conversation is not there.')
      }

      show(((await response.json()) as { messages: StoredMessage[] }).messages)
      busy(transcript.waiting)
    } catch (error) {
      select(null)
      fail(error instanceof Error ? error.message : 'The connection broke.')
    }
  }

  function startNew(): void {
    reset()
    select(null)
    conversationList.removeAttribute('data-open')
    const hint = document.createElement('p')
    hint.dataset.empty = ''
    hint.className = 'text-sm text-muted'
    hint.textContent = emptyText
    root.querySelector('[data-transcript]')!.append(hint)
    message.focus()
  }

  async function load(): Promise<void> {
    loaded = true

    try {
      const response = await fetch(root.dataset.conversationsUrl ?? '', { headers: { Accept: 'application/json' } })

      if (!response.ok) {
        throw new Error(`The conversations did not load (${response.status}).`)
      }

      const listing = (await response.json()) as { ready: boolean; conversations: { id: string; title: string }[] }
      unconnected.hidden = listing.ready
      pane.hidden = !listing.ready
      conversationList.hidden = !listing.ready
      root.querySelector<HTMLElement>('[data-no-conversations]')!.hidden = listing.conversations.length > 0

      for (const { id, title } of listing.conversations.reverse()) {
        addToList(id, title)
      }

      if (conversation !== null && listing.ready) {
        await open(conversation)
      }
    } catch (error) {
      loaded = false
      fail(error instanceof Error ? error.message : 'The connection broke.')
    }
  }

  // Whatever opens the dialog -- its button, the command center, the shortcut -- ends here.
  function opened(): void {
    store(OPEN_KEY, '1')

    if (!loaded) {
      void load()
    }

    message.focus()
  }

  list.addEventListener('click', (event) => {
    const id = (event.target as Element).closest<HTMLElement>('[data-conversation]')?.dataset.conversation

    if (id !== undefined && !streaming) {
      void open(id)
    }
  })

  newChat.addEventListener('click', startNew)

  listToggle.addEventListener('click', () => {
    listToggle.setAttribute('aria-expanded', String(conversationList.toggleAttribute('data-open')))
  })

  new MutationObserver(() => (dialog.open ? opened() : store(OPEN_KEY, null))).observe(dialog, { attributes: true, attributeFilter: ['open'] })

  const opener = document.querySelector<HTMLElement>('[data-chat-open]')

  if (opener !== null && /Mac|iPhone|iPad/.test(navigator.userAgent)) {
    opener.title = opener.title.replace('Ctrl', '⌘')
  }

  // Ctrl or Command and a full stop: free in browsers, and not the command center's K.
  document.addEventListener('keydown', (event) => {
    if (event.key === '.' && (event.metaKey || event.ctrlKey)) {
      event.preventDefault()

      if (dialog.open) {
        dialog.close()
      } else {
        dialog.showModal()
      }
    }
  })

  if (stored(OPEN_KEY) === '1') {
    dialog.showModal()
  }
}

const dialog = document.querySelector<HTMLDialogElement>('#chat-dialog')
const root = dialog?.querySelector<HTMLElement>('[data-chat]')

if (dialog && root) {
  mountChat(dialog, root)
}
