import { expect, test } from 'vitest'
import bundle from '../dist/chat.js?raw'
import markdownSource from './markdown.ts?raw'
import transcriptSource from './transcript.ts?raw'
import { renderMarkdown } from './markdown'

const ALLOWED = new Set(['P', 'BR', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'STRONG', 'EM', 'CODE', 'PRE', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'HR', 'A', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'DIV'])
const ALLOWED_ATTRIBUTES = new Set(['class', 'href', 'target', 'rel', 'start'])

function render(source: string): HTMLElement {
  const root = document.createElement('div')
  renderMarkdown(source, root)

  return root
}

// What the renderer must never produce, whatever it is given.
function expectSafe(root: HTMLElement): void {
  for (const created of Array.from(root.querySelectorAll('*'))) {
    expect(ALLOWED.has(created.tagName), created.tagName).toBe(true)

    for (const attribute of Array.from(created.attributes)) {
      expect(ALLOWED_ATTRIBUTES.has(attribute.name), attribute.name).toBe(true)
    }
  }

  for (const anchor of Array.from(root.querySelectorAll('a'))) {
    expect(anchor.href).toMatch(/^(https?|mailto):/)
    expect(anchor.rel).toBe('noopener noreferrer')
    expect(anchor.target).toBe('_blank')
  }
}

test('paragraphs and line breaks', () => {
  const root = render('One\ntwo\n\nThree')

  expect(Array.from(root.querySelectorAll('p')).map((paragraph) => paragraph.innerHTML)).toEqual(['One<br>two', 'Three'])
})

test('headings of every level stay small headings', () => {
  const root = render('# One\n###### Six\n####### Seven')

  expect(root.querySelector('h1')!.textContent).toBe('One')
  expect(root.querySelector('h6')!.textContent).toBe('Six')
  expect(root.querySelectorAll('p')[0]!.textContent).toBe('####### Seven')
})

test('bold, italic and inline code', () => {
  const root = render('**bold** and *italic* and __b__ and _i_ and `a <b> c` and ***both***')

  expect(root.querySelectorAll('strong')[0]!.textContent).toBe('bold')
  expect(root.querySelectorAll('em')[0]!.textContent).toBe('italic')
  expect(root.querySelector('code')!.textContent).toBe('a <b> c')
  expect(root.querySelector('strong > em')!.textContent).toBe('both')
  expect(root.querySelectorAll('strong')).toHaveLength(3)
  expect(root.querySelectorAll('em')).toHaveLength(3)
})

test('snake_case and a lone asterisk stay text', () => {
  expect(render('use snake_case_names and 2 * 3').textContent).toBe('use snake_case_names and 2 * 3')
})

test('a fenced code block keeps its text verbatim, even when it is not closed yet', () => {
  expect(render('```ts\nconst a = <b>1</b>\n  indented\n```\nafter').querySelector('pre > code')!.textContent).toBe('const a = <b>1</b>\n  indented')
  expect(render('before\n\n```\n**not bold**\n# not a heading').querySelector('pre > code')!.textContent).toBe('**not bold**\n# not a heading')
  expect(render('```\nx\n```\nafter').querySelector('p')!.textContent).toBe('after')
})

test('blockquotes nest and hold blocks', () => {
  const root = render('> quoted **text**\n> > deeper\n>\n> - item')

  expect(root.querySelector('blockquote > p > strong')!.textContent).toBe('text')
  expect(root.querySelector('blockquote blockquote')!.textContent).toBe('deeper')
  expect(root.querySelector('blockquote > ul > li')!.textContent).toBe('item')
})

test('unordered and ordered lists, nested', () => {
  const root = render('- one\n- two\n  - inner a\n  - inner b\n- three\n\n3. third\n4. fourth')

  expect(Array.from(root.querySelectorAll(':scope > ul > li')).map((item) => item.firstElementChild!.textContent)).toEqual(['one', 'two', 'three'])
  expect(Array.from(root.querySelectorAll('ul ul > li')).map((item) => item.textContent)).toEqual(['inner a', 'inner b'])
  expect(root.querySelector('ol')!.getAttribute('start')).toBe('3')
  expect(root.querySelectorAll('ol > li')).toHaveLength(2)
})

test('an ordered list holds a nested bullet list and a continued paragraph', () => {
  const root = render('1. first\n   - sub\n2. second\n\n   more of second')

  expect(root.querySelectorAll('ol > li')).toHaveLength(2)
  expect(root.querySelector('ol > li ul > li')!.textContent).toBe('sub')
  expect(root.querySelectorAll('ol > li')[1]!.querySelectorAll('p')).toHaveLength(2)
})

test('horizontal rules', () => {
  expect(render('a\n\n---\n\n* * *\n\n___').querySelectorAll('hr')).toHaveLength(3)
})

test('links: http, https and mailto only, opened safely', () => {
  const root = render('[a](https://example.com/x?y=1) [b](http://example.com) [c](mailto:me@example.com)')

  expect(Array.from(root.querySelectorAll('a')).map((anchor) => anchor.getAttribute('href'))).toEqual(['https://example.com/x?y=1', 'http://example.com/', 'mailto:me@example.com'])
  expectSafe(root)
})

test('the two largest headings are set larger than the rest', () => {
  const root = render('# One\n## Two\n### Three\n#### Four')
  const larger = (tag: string) => root.querySelector(tag)!.classList.contains('text-base')

  expect([larger('h1'), larger('h2'), larger('h3'), larger('h4')]).toEqual([true, true, false, false])
})

test('an escaped pipe stays inside its table cell', () => {
  const root = render('| Name | Value |\n| --- | --- |\n| a \\| b | c |')

  expect(Array.from(root.querySelectorAll('td')).map((cell) => cell.textContent)).toEqual(['a | b', 'c'])
})

test('a table renders its header and rows', () => {
  const root = render('| Name | Count |\n| --- | ---: |\n| **a** | 1 |\n| b | 2 | extra |\n\nafter')

  expect(Array.from(root.querySelectorAll('th')).map((cell) => cell.textContent)).toEqual(['Name', 'Count'])
  expect(Array.from(root.querySelectorAll('tbody tr')).map((tableRow) => tableRow.children.length)).toEqual([2, 2])
  expect(root.querySelector('td strong')!.textContent).toBe('a')
  expect(root.querySelector('p')!.textContent).toBe('after')
})

test('a pipe line without a rule row is plain text', () => {
  expect(render('a | b\nc | d').querySelector('table')).toBeNull()
})

test('half-written markdown mid-stream shows as the text typed so far', () => {
  expect(render('some **bol').textContent).toBe('some **bol')
  expect(render('some `cod').textContent).toBe('some `cod')
  expect(render('see [the docs](http://exa').textContent).toBe('see [the docs](http://exa')
  expect(render('| a | b |\n| --- |').querySelector('table')).toBeNull()
  expect(render('**').textContent).toBe('**')
  expect(render('**bold** and *it').querySelector('strong')!.textContent).toBe('bold')
})

test('re-rendering replaces what was shown', () => {
  const root = document.createElement('div')
  renderMarkdown('one', root)
  renderMarkdown('two', root)

  expect(root.textContent).toBe('two')
})

const HOSTILE = [
  '<script>alert(1)</script>',
  '<img src=x onerror=alert(1)>',
  '[x](javascript:alert(1))',
  '[x](JaVaScRiPt:alert(1))',
  '[x]( javascript:alert(1))',
  '[x](java\tscript:alert(1))',
  '[x](data:text/html,<script>alert(1)</script>)',
  '[x](vbscript:alert(1))',
  '[x](//evil.example/path)',
  '[x](/relative)',
  '![a](http://x/y.png)',
  '![a](javascript:alert(1))',
  '![<img src=x onerror=alert(1)>](data:image/png;base64,AAAA)',
  '[<img src=x onerror=alert(1)>](https://example.com)',
  '[**<b onclick=alert(1)>x</b>**](https://example.com)',
  '[[nested](https://a.example)](https://b.example)',
  '**<u>**' + '*'.repeat(50),
  '`<script>`' + '```' + '<script>',
  '| <img src=x onerror=alert(1)> | b |\n| --- | --- |\n| <svg onload=alert(1)> | [x](javascript:alert(1)) |',
  '> <iframe src="javascript:alert(1)"></iframe>',
  '- <a href="javascript:alert(1)">x</a>\n  - [y](data:text/html,x)',
  '# <script>alert(1)</script>',
  '&lt;script&gt; &#60;script&#62;',
  '\\<script>',
  '<a href="https://example.com" onclick="alert(1)">x</a>',
  '*'.repeat(500) + '_'.repeat(500) + '['.repeat(500) + '`'.repeat(500),
  '>'.repeat(200) + ' deep',
  '- a\n'.repeat(1) + '  '.repeat(100) + '- deep',
]

test.each(HOSTILE)('hostile input creates only allowed nodes: %s', (source) => {
  const root = render(source)

  expectSafe(root)
  expect(root.querySelector('script, img, iframe, svg, style, u, b')).toBeNull()
})

test('raw html shows as literal text', () => {
  expect(render('<script>alert(1)</script>').textContent).toBe('<script>alert(1)</script>')
  expect(render('[x](https://example.com)').textContent).toBe('x')
  expect(render('[<b>x</b>](https://example.com)').querySelector('a')!.textContent).toBe('<b>x</b>')
})

test('an unsafe link keeps its label as text and makes no anchor', () => {
  const root = render('[click me](javascript:alert(1)) and [data](data:text/html,x) and [rel](/x)')

  expect(root.querySelector('a')).toBeNull()
  expect(root.textContent).toBe('click me and data and rel')
})

test('an image becomes a plain link to its url, never an img', () => {
  const root = render('![a chart](http://x/y.png) ![](https://x/z.png) ![bad](javascript:1)')

  expect(root.querySelector('img')).toBeNull()
  expect(Array.from(root.querySelectorAll('a')).map((anchor) => [anchor.textContent, anchor.getAttribute('href')])).toEqual([
    ['a chart', 'http://x/y.png'],
    ['https://x/z.png', 'https://x/z.png'],
  ])
  expect(root.textContent).toBe('a chart https://x/z.png bad')
})

test('a link does not nest in a link', () => {
  expect(render('[[a](https://a.example)](https://b.example)').querySelectorAll('a')).toHaveLength(1)
})

test('huge and pathological input finishes quickly and stays safe', { timeout: 30_000 }, () => {
  const sources = ['**a '.repeat(50_000), '*a '.repeat(50_000), '`a '.repeat(50_000), '[a '.repeat(50_000), '> '.repeat(50_000) + 'x', '- a\n'.repeat(50_000), '| a |\n| - |\n'.repeat(20_000), 'word '.repeat(200_000)]
  const started = performance.now()

  for (const source of sources) {
    expectSafe(render(source))
  }

  expect(performance.now() - started).toBeLessThan(10_000)
})

// The guarantee that no answer can inject markup rests on never parsing html at all.
test.each([
  ['markdown.ts', markdownSource],
  ['transcript.ts', transcriptSource],
  ['the built bundle', bundle],
])('%s never parses markup', (_name, source) => {
  expect(source).not.toMatch(/innerHTML|outerHTML|insertAdjacentHTML|DOMParser|createContextualFragment|document\.write/)
})
