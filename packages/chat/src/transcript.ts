import { renderMarkdown } from './markdown'

export type Decide = (toolId: string, approved: boolean) => void

type Arguments = Record<string, unknown>

const ERROR_PREFIX = 'Error: '

function element<K extends keyof HTMLElementTagNameMap>(tag: K, className: string, text = ''): HTMLElementTagNameMap[K] {
  const created = document.createElement(tag)
  created.className = className
  created.textContent = text

  return created
}

function pretty(value: unknown): string {
  return JSON.stringify(value, null, 2)
}

// What a call is about, in a few words: the type, and the entry or draft it names.
function summary(args: Arguments): string {
  const target = ['id', 'entry', 'draft'].find((key) => args[key] !== undefined)

  return [args.type, target === undefined ? undefined : `#${String(args[target])}`].filter((part) => part !== undefined).join(' ')
}

// The chat's messages, the model's tool calls as compact rows and the
// approvals it waits on. Every text goes in as text, never as markup: it is a
// model's, or a site's content. The model's answer is rendered as markdown by
// a renderer that builds only safe nodes.
export class Transcript {
  private text: HTMLElement | null = null
  private answer = ''
  private readonly tools = new Map<string, HTMLDetailsElement>()

  constructor(
    private readonly root: HTMLElement,
    private readonly decide: Decide,
  ) {}

  clear(): void {
    this.root.replaceChildren()
    this.tools.clear()
    this.text = null
  }

  user(text: string): void {
    this.text = null
    this.root.append(element('p', 'ml-auto max-w-[80%] whitespace-pre-wrap rounded-control bg-surface px-3 py-2 text-sm', text))
  }

  append(delta: string): void {
    if (this.text === null) {
      this.text = element('div', 'max-w-[80%] break-words text-sm')
      this.answer = ''
      this.root.append(this.text)
    }

    this.answer += delta
    renderMarkdown(this.answer, this.text)
  }

  toolCall(id: string, name: string, args: Arguments): void {
    this.text = null
    const row = element('details', 'max-w-[80%] rounded-control border border-border text-sm')
    const head = element('summary', 'flex cursor-pointer items-baseline gap-2 px-3 py-1.5')
    head.append(element('span', 'font-medium', name), element('span', 'min-w-0 truncate text-muted', summary(args)), element('span', 'ml-auto shrink-0 text-xs text-muted', 'running'))
    row.append(head, element('pre', 'overflow-x-auto border-t border-border px-3 py-2 text-xs', pretty(args)))
    row.dataset.tool = id
    this.tools.set(id, row)
    this.root.append(row)
  }

  toolResult(id: string, result: string, denied = false): void {
    const row = this.tools.get(id)

    if (row === undefined) {
      return
    }

    this.status(row, denied ? 'not approved' : result.startsWith(ERROR_PREFIX) ? 'refused' : 'done')
    row.querySelector('[data-approval]')?.remove()
    row.append(element('pre', 'overflow-x-auto border-t border-border px-3 py-2 text-xs text-muted', result))
  }

  // The call that will not run until the person says so, showing what it will write.
  needsApproval(id: string, name: string, args: Arguments): void {
    if (!this.tools.has(id)) {
      this.toolCall(id, name, args)
    }

    const row = this.tools.get(id)!
    this.status(row, 'waiting for approval')
    row.open = true

    const approval = element('div', 'space-y-2 border-t border-border px-3 py-2')
    approval.dataset.approval = ''
    approval.append(element('p', 'text-xs font-medium', 'This goes live or deletes. Approve it to run it.'))

    for (const [label, value] of Object.entries(args)) {
      approval.append(element('p', 'text-xs text-muted', label), element('pre', 'overflow-x-auto text-xs', typeof value === 'object' ? pretty(value) : String(value)))
    }

    const buttons = element('div', 'flex gap-2')
    buttons.append(this.button('Approve', 'bg-accent text-accent-ink', id, true), this.button('Reject', 'border border-border bg-surface text-ink', id, false))
    approval.append(buttons)
    row.append(approval)
  }

  decided(id: string, approved: boolean): void {
    const row = this.tools.get(id)

    if (row !== undefined) {
      row.querySelector('[data-approval]')?.remove()
      this.status(row, approved ? 'approved' : 'rejected')
    }
  }

  // Whether any call is still waiting for the person.
  get waiting(): boolean {
    return this.root.querySelector('[data-approval]') !== null
  }

  private status(row: HTMLElement, text: string): void {
    row.querySelector('summary span:last-child')!.textContent = text
  }

  private button(label: string, classes: string, id: string, approved: boolean): HTMLButtonElement {
    const button = element('button', `rounded-control px-3 py-1 text-xs font-medium hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ${classes}`, label)
    button.type = 'button'
    button.addEventListener('click', () => this.decide(id, approved))

    return button
  }
}
