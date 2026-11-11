import { readEvents } from './events'
import { Transcript } from './transcript'
import './index.css'

type StoredToolCall = { id: string; name: string; arguments: Record<string, unknown>; result?: string; denied?: boolean; approval_reason?: string | null }
type StoredMessage = { role: string; content: string | null; tool_calls: StoredToolCall[] }

const CONVERSATION_HEADER = 'X-Conversation-Id'

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

// The chat screen: one conversation open at a time, its answer streamed in,
// and what the model asks to write held for the person's Approve or Reject.
function mountChat(root: HTMLElement): void {
  const composer = root.querySelector<HTMLFormElement>('[data-composer]')!
  const message = composer.querySelector<HTMLTextAreaElement>('textarea')!
  const send = composer.querySelector<HTMLButtonElement>('button')!
  const failure = root.querySelector<HTMLElement>('[data-error]')!
  const list = root.querySelector<HTMLElement>('[data-conversations]')!
  const empty = root.querySelector<HTMLElement>('[data-empty]')!
  const urls = (id: string, template: string | undefined) => (template ?? '').replace('__id__', id)

  // Only an id, since it goes into a URL: the query string is anyone's to write.
  let conversation = /^[0-9a-f-]{36}$/i.test(root.dataset.open ?? '') ? root.dataset.open! : null
  const decisions = new Map<string, boolean>()
  const pending = new Set<string>()
  const transcript = new Transcript(root.querySelector<HTMLElement>('[data-transcript]')!, (toolId, approved) => decide(toolId, approved))

  function busy(working: boolean): void {
    send.disabled = working
    message.disabled = working
  }

  function fail(text: string): void {
    failure.textContent = text
    failure.hidden = false
  }

  function remember(id: string, title: string): void {
    conversation = id
    history.replaceState(null, '', `?conversation=${id}`)

    if (list.querySelector(`[data-conversation="${id}"]`) === null) {
      const item = document.createElement('li')
      item.className = 'border-t border-border'
      const link = document.createElement('a')
      link.href = `?conversation=${id}`
      link.dataset.conversation = id
      link.className = 'block truncate px-5 py-3 text-sm hover:bg-surface aria-[current=true]:bg-surface aria-[current=true]:font-medium'
      link.ariaCurrent = 'true'
      link.textContent = title
      item.append(link)
      list.prepend(item)
    }
  }

  // A turn that failed before anything was stored leaves no conversation behind for the id the response named.
  function forget(id: string): void {
    conversation = null
    history.replaceState(null, '', location.pathname)
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

    try {
      await stream(await request(), title)
    } catch (error) {
      fail(error instanceof Error ? error.message : 'The connection broke.')
    } finally {
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

    empty.remove()
    transcript.user(text)
    message.value = ''
    void run(() => post(root.dataset.send ?? '', { message: text, conversation }), text)
  })

  message.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
      composer.requestSubmit()
    }
  })

  if (conversation !== null) {
    empty.remove()
    void fetch(urls(conversation, root.dataset.show), { headers: { Accept: 'application/json' } })
      .then((response) => (response.ok ? (response.json() as Promise<{ messages: StoredMessage[] }>) : Promise.reject(new Error('That conversation is not there.'))))
      .then((stored) => {
        show(stored.messages)
        busy(transcript.waiting)
      })
      .catch((error: Error) => fail(error.message))
  }
}

const root = document.querySelector<HTMLElement>('[data-chat]')

if (root !== null) {
  mountChat(root)
}
