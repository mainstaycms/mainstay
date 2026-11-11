// A small markdown renderer for the model's answers. The text is untrusted
// (a site's content can steer the model), so it only ever builds nodes from
// a fixed set of elements with createElement and createTextNode: raw html in
// the text stays literal text, images are never loaded and a link is only
// made for a url whose protocol is listed below.

const SAFE_PROTOCOLS = new Set(['http:', 'https:', 'mailto:'])

// Nesting (lists in quotes in lists, emphasis in emphasis) past this shows as text.
const MAX_DEPTH = 6

// How far ahead a closing marker is looked for. Bounds the cost of unclosed
// markers in a huge answer, which would otherwise rescan the rest of it each.
const MAX_SPAN = 1000

const FENCE = /^ {0,3}(`{3,}|~{3,})/
const HEADING = /^ {0,3}(#{1,6})\s+(.*)$/
const RULE = /^ {0,3}([-*_])(?: *\1){2,} *$/
const QUOTE = /^ {0,3}>/
const LIST_ITEM = /^( *)([-*+]|\d{1,9}[.)]) +(.*)$/
const TABLE_CELL_RULE = /^\s*:?-+:?\s*$/
const SPECIAL = /[\\`*_![]/g

// The admin's own look: the lists' hairline rules and muted small headers, its monospace at text-xs, one text size throughout.
const CLASSES = {
  blocks: 'space-y-3',
  items: 'space-y-1.5',
  heading: 'pt-1 font-medium',
  largeHeading: 'pt-1 text-base font-medium',
  unordered: 'list-disc space-y-1.5 pl-5 marker:text-muted',
  ordered: 'list-decimal space-y-1.5 pl-5 marker:text-muted',
  quote: 'space-y-3 border-l border-border pl-3 text-muted',
  rule: 'border-border',
  pre: 'overflow-x-auto rounded-control border border-border bg-surface px-3 py-2 font-mono text-xs',
  code: 'font-mono text-xs',
  link: 'text-accent underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
  tableScroll: 'overflow-x-auto',
  table: 'w-full text-sm',
  headerRow: 'text-left text-xs text-muted',
  header: 'border-b border-border px-3 py-2 font-medium first:pl-0 last:pr-0',
  cell: 'border-b border-border px-3 py-2 align-top first:pl-0 last:pr-0',
}

function make<K extends keyof HTMLElementTagNameMap>(tag: K, className = ''): HTMLElementTagNameMap[K] {
  const created = document.createElement(tag)

  if (className !== '') {
    created.className = className
  }

  return created
}

function safeUrl(target: string): string | null {
  try {
    const url = new URL(target)

    return SAFE_PROTOCOLS.has(url.protocol) ? url.href : null
  } catch {
    return null
  }
}

function link(href: string): HTMLAnchorElement {
  const anchor = make('a', CLASSES.link)
  anchor.href = href
  anchor.target = '_blank'
  anchor.rel = 'noopener noreferrer'

  return anchor
}

function isAlphanumeric(character: string | undefined): boolean {
  return character !== undefined && /[\p{L}\p{N}]/u.test(character)
}

function isBlank(character: string | undefined): boolean {
  return character === undefined || /\s/.test(character)
}

// Where the run of the same character starting at `from` ends.
function runEnd(text: string, from: number): number {
  let end = from

  while (text[end] === text[from]) {
    end++
  }

  return end
}

// The index of the closing run of exactly `length` backticks, or -1.
function closingTicks(text: string, from: number, length: number): number {
  const limit = Math.min(text.length, from + MAX_SPAN)

  for (let at = text.indexOf('`', from); at !== -1 && at < limit; at = text.indexOf('`', at)) {
    const end = runEnd(text, at)

    if (end - at === length) {
      return at
    }

    at = end
  }

  return -1
}

// The index of the marker that closes emphasis opened at `opener`, or -1.
function closingMarker(text: string, opener: number, marker: string): number {
  const limit = Math.min(text.length, opener + MAX_SPAN)
  const single = marker.length === 1

  for (let at = text.indexOf(marker, opener + marker.length); at !== -1 && at < limit; at = text.indexOf(marker, at + 1)) {
    const touchesOther = single && (text[at - 1] === marker || text[at + 1] === marker)
    const wordy = marker === '_' && isAlphanumeric(text[at + 1])

    if (!isBlank(text[at - 1]) && !touchesOther && !wordy) {
      return at
    }
  }

  return -1
}

type Link = { label: string; destination: string; end: number }

// `[label](destination)` starting at the bracket, or null.
function linkAt(text: string, start: number): Link | null {
  const limit = Math.min(text.length, start + MAX_SPAN)
  let depth = 0
  let close = -1

  for (let at = start; at < limit && close === -1; at++) {
    if (text[at] === '\\') {
      at++
    } else if (text[at] === '[') {
      depth++
    } else if (text[at] === ']' && --depth === 0) {
      close = at
    }
  }

  if (close === -1 || text[close + 1] !== '(') {
    return null
  }

  let parens = 0
  let end = -1

  for (let at = close + 1; at < limit && end === -1; at++) {
    if (text[at] === '(') {
      parens++
    } else if (text[at] === ')' && --parens === 0) {
      end = at
    }
  }

  if (end === -1) {
    return null
  }

  const destination = text
    .slice(close + 2, end)
    .trim()
    .split(/\s/)[0]!
    .replace(/^<|>$/g, '')

  return { label: text.slice(start + 1, close), destination, end: end + 1 }
}

type Inline = { depth: number; inLink: boolean }

function inline(text: string, into: Node, context: Inline): void {
  let plain = ''
  let at = 0

  const flush = (): void => {
    if (plain !== '') {
      into.appendChild(document.createTextNode(plain))
      plain = ''
    }
  }

  while (at < text.length) {
    SPECIAL.lastIndex = at
    const special = SPECIAL.exec(text)
    const next = special === null ? text.length : special.index
    plain += text.slice(at, next)
    at = next

    if (at >= text.length) {
      break
    }

    const rendered = context.depth < MAX_DEPTH ? span(text, at, context) : null

    if (rendered === null) {
      plain += text[at]
      at++
      continue
    }

    flush()
    into.appendChild(rendered.node)
    at = rendered.end
  }

  flush()
}

type Span = { node: Node; end: number }

// The inline construct that starts at `at`, or null when it is only a character.
function span(text: string, at: number, context: Inline): Span | null {
  const character = text[at]!
  const deeper = { ...context, depth: context.depth + 1 }

  if (character === '\\' && /[\\`*_![\](){}#+\-.>|~]/.test(text[at + 1] ?? '')) {
    return { node: document.createTextNode(text[at + 1]!), end: at + 2 }
  }

  if (character === '`') {
    const length = runEnd(text, at) - at
    const close = closingTicks(text, at + length, length)

    if (close === -1) {
      return null
    }

    const code = make('code', CLASSES.code)
    code.textContent = text.slice(at + length, close).replace(/^ (.*) $/, '$1')

    return { node: code, end: close + length }
  }

  if (character === '*' || character === '_') {
    return emphasis(text, at, deeper)
  }

  if (character === '[' && !context.inLink) {
    return anchor(text, at, deeper)
  }

  if (character === '!' && text[at + 1] === '[') {
    return image(text, at)
  }

  return null
}

function emphasis(text: string, at: number, context: Inline): Span | null {
  if (isBlank(text[at + 1]) || (text[at] === '_' && isAlphanumeric(text[at - 1]))) {
    return null
  }

  const run = runEnd(text, at) - at
  const attempts = run >= 3 ? [3, 2, 1] : run === 2 ? [2, 1] : [1]

  for (const length of attempts) {
    const marker = text[at]!.repeat(length)
    const close = closingMarker(text, at, marker)

    if (close === -1 || close === at + length) {
      continue
    }

    const inner = text.slice(at + length, close)
    const node = length === 1 ? make('em') : make('strong')
    inline(inner, length === 3 ? node.appendChild(make('em')) : node, context)

    return { node, end: close + length }
  }

  return null
}

// A link made only for a safe url. Otherwise its label is shown as plain text.
function anchor(text: string, at: number, context: Inline): Span | null {
  const found = linkAt(text, at)

  if (found === null) {
    return null
  }

  const href = safeUrl(found.destination)
  const node = href === null ? document.createDocumentFragment() : link(href)
  inline(found.label, node, { ...context, inLink: true })

  return { node, end: found.end }
}

// Images are never loaded: one becomes a link to its url, with its alt text for a label.
function image(text: string, at: number): Span | null {
  const found = linkAt(text, at + 1)
  const href = found === null ? null : safeUrl(found.destination)

  if (found === null) {
    return null
  }

  if (href === null) {
    return { node: document.createTextNode(found.label), end: found.end }
  }

  const node = link(href)
  node.textContent = found.label === '' ? href : found.label

  return { node, end: found.end }
}

function startsBlock(line: string): boolean {
  return FENCE.test(line) || HEADING.test(line) || RULE.test(line) || QUOTE.test(line) || LIST_ITEM.test(line)
}

function indentOf(line: string): number {
  return line.length - line.trimStart().length
}

function paragraph(lines: string[], into: HTMLElement): void {
  const block = make('p')

  lines.forEach((line, index) => {
    if (index > 0) {
      block.appendChild(make('br'))
    }

    inline(line.trim(), block, { depth: 0, inLink: false })
  })
  into.appendChild(block)
}

// An unterminated fence, as one is while its answer is still arriving, shows what it has so far.
function fencedCode(lines: string[], start: number, into: HTMLElement): number {
  const fence = FENCE.exec(lines[start]!)![1]!
  const closing = new RegExp(`^ {0,3}${fence[0] === '`' ? '`' : '~'}{${fence.length},}\\s*$`)
  const body: string[] = []
  let at = start + 1

  while (at < lines.length && !closing.test(lines[at]!)) {
    body.push(lines[at]!)
    at++
  }

  const pre = make('pre', CLASSES.pre)
  const code = make('code')
  code.textContent = body.join('\n')
  pre.appendChild(code)
  into.appendChild(pre)

  return at + 1
}

function heading(line: string, into: HTMLElement): void {
  const [, hashes, text] = HEADING.exec(line)!
  const block = make(`h${hashes!.length}` as 'h1', hashes!.length <= 2 ? CLASSES.largeHeading : CLASSES.heading)
  inline(text!.replace(/\s+#+\s*$/, '').trim(), block, { depth: 0, inLink: false })
  into.appendChild(block)
}

function quote(lines: string[], start: number, into: HTMLElement, depth: number): number {
  const inner: string[] = []
  let at = start

  while (at < lines.length && QUOTE.test(lines[at]!)) {
    inner.push(lines[at]!.replace(/^ {0,3}> ?/, ''))
    at++
  }

  const block = make('blockquote', CLASSES.quote)
  blocks(inner, block, depth + 1)
  into.appendChild(block)

  return at
}

function list(lines: string[], start: number, into: HTMLElement, depth: number): number {
  const first = LIST_ITEM.exec(lines[start]!)!
  const base = first[1]!.length
  const ordered = /\d/.test(first[2]!)
  const block = make(ordered ? 'ol' : 'ul', ordered ? CLASSES.ordered : CLASSES.unordered)
  let at = start

  if (block instanceof HTMLOListElement && parseInt(first[2]!, 10) !== 1) {
    block.start = parseInt(first[2]!, 10)
  }

  while (at < lines.length) {
    const item = LIST_ITEM.exec(lines[at]!)

    if (item === null || item[1]!.length > base || /\d/.test(item[2]!) !== ordered) {
      break
    }

    const content = [item[3]!]
    at++

    while (at < lines.length) {
      const line = lines[at]!

      if (!belongsToItem(lines, at, base)) {
        break
      }

      content.push(line.slice(Math.min(indentOf(line), base + 2)))
      at++
    }

    const entry = make('li', CLASSES.items)
    blocks(content, entry, depth + 1)
    block.appendChild(entry)
  }

  into.appendChild(block)

  return at
}

// A blank line stays inside the item when more indented text follows it; any other line when it is
// indented under the item or plain text continuing it.
function belongsToItem(lines: string[], at: number, base: number): boolean {
  const line = lines[at]!

  if (line.trim() !== '') {
    return indentOf(line) > base || !startsBlock(line)
  }

  const following = lines.slice(at + 1).find((candidate) => candidate.trim() !== '')

  return following !== undefined && indentOf(following) > base
}

function cells(line: string): string[] {
  return line
    .trim()
    .replace(/^\||\|$/g, '')
    .split(/(?<!\\)\|/)
    .map((cell) => cell.trim().replace(/\\\|/g, '|'))
}

function startsTable(lines: string[], at: number): boolean {
  const rule = lines[at + 1]

  if (rule === undefined || !lines[at]!.includes('|') || !rule.includes('|')) {
    return false
  }

  const widths = cells(rule)

  return widths.every((cell) => TABLE_CELL_RULE.test(cell)) && widths.length === cells(lines[at]!).length
}

function row(tag: 'th' | 'td', values: string[], width: number): HTMLTableRowElement {
  const created = make('tr', tag === 'th' ? CLASSES.headerRow : '')

  for (let column = 0; column < width; column++) {
    const cell = make(tag, tag === 'th' ? CLASSES.header : CLASSES.cell)
    inline(values[column] ?? '', cell, { depth: 0, inLink: false })
    created.appendChild(cell)
  }

  return created
}

function table(lines: string[], start: number, into: HTMLElement): number {
  const header = cells(lines[start]!)
  const block = make('table', CLASSES.table)
  const head = make('thead')
  const body = make('tbody')
  let at = start + 2

  head.appendChild(row('th', header, header.length))

  while (at < lines.length && lines[at]!.trim() !== '' && lines[at]!.includes('|')) {
    body.appendChild(row('td', cells(lines[at]!), header.length))
    at++
  }

  block.append(head, body)
  const scroll = make('div', CLASSES.tableScroll)
  scroll.appendChild(block)
  into.appendChild(scroll)

  return at
}

function blocks(lines: string[], into: HTMLElement, depth: number): void {
  if (depth >= MAX_DEPTH) {
    paragraph(lines.filter((line) => line.trim() !== ''), into)

    return
  }

  let at = 0

  while (at < lines.length) {
    const line = lines[at]!

    if (line.trim() === '') {
      at++
    } else if (FENCE.test(line)) {
      at = fencedCode(lines, at, into)
    } else if (HEADING.test(line)) {
      heading(line, into)
      at++
    } else if (RULE.test(line)) {
      into.appendChild(make('hr', CLASSES.rule))
      at++
    } else if (QUOTE.test(line)) {
      at = quote(lines, at, into, depth)
    } else if (LIST_ITEM.test(line)) {
      at = list(lines, at, into, depth)
    } else if (startsTable(lines, at)) {
      at = table(lines, at, into)
    } else {
      const end = lines.findIndex((candidate, index) => index > at && (candidate.trim() === '' || startsBlock(candidate)))
      const stop = end === -1 ? lines.length : end
      paragraph(lines.slice(at, stop), into)
      at = stop
    }
  }
}

// Replaces what `into` shows with `source` rendered. Built apart and swapped
// in one go, so an answer re-rendered on each streamed delta never shows half.
// ponytail: re-renders the whole answer per delta, cheap at chat lengths; diff by block if answers reach hundreds of kilobytes.
export function renderMarkdown(source: string, into: HTMLElement): void {
  const rendered = make('div')
  blocks(source.replace(/\r\n?/g, '\n').replace(/\t/g, '    ').split('\n'), rendered, 0)
  into.classList.add(CLASSES.blocks)
  into.replaceChildren(...rendered.childNodes)
}
