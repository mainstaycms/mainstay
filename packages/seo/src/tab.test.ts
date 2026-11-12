import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { mountSeoTab, renderChecks, writtenTitle } from './tab'

const DOCUMENT = JSON.stringify({
  type: 'doc',
  content: [
    { type: 'paragraph', content: [{ type: 'text', text: 'Red shoes keep your feet dry on a rainy day. '.repeat(8) }] },
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Why red shoes' }] },
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Care' }] },
    { type: 'paragraph', content: [{ type: 'text', text: 'See the sale', marks: [{ type: 'link', attrs: { href: '/sale' } }] }] },
  ],
})

function markup(): string {
  return `
  <form id="entry-form">
    <input type="hidden" name="_token" value="tok">
    <input name="title" value="Red shoes">
    <input name="slug" value="red-shoes">
    <input type="hidden" name="content" value='${DOCUMENT.replaceAll("'", '&#39;')}'>
    <div id="details"></div>
    <button id="seo-tab" type="button"></button>
    <div data-seo data-field="seo" data-endpoint="/admin/post/1/seo-preview" data-locale="en" data-template="%title% | %site%" data-site="Acme" data-origin="https://example.com" data-default-description="" data-content-field="content" data-slug-field="slug">
      <input data-seo-input="keyphrase" name="seo[keyphrase]" value="red shoes">
      <input data-seo-input="title" name="seo[title]" value="">
      <textarea data-seo-input="description" name="seo[description]"></textarea>
      <input data-seo-input="social_title" name="seo[social_title]" value="">
      <textarea data-seo-input="social_description" name="seo[social_description]"></textarea>
      <span data-seo-counter="title"></span><span data-seo-counter="description"></span><span data-seo-template></span>
      <p data-seo-note></p>
      <p data-seo-snippet="url"></p><p data-seo-snippet="title"></p><p data-seo-snippet="description"></p>
      <div data-seo-card><img data-seo-card-image hidden><p data-seo-card-host></p><p data-seo-card-title></p><p data-seo-card-description></p></div>
      <p data-seo-summary="seo"></p><ul data-seo-checks="seo"></ul>
      <p data-seo-summary="readability"></p><ul data-seo-checks="readability"></ul>
    </div>
  </form>`
}

function status(id: string): string | undefined {
  return document.querySelector<HTMLElement>(`[data-check="${id}"]`)?.dataset.status
}

beforeEach(() => {
  vi.useFakeTimers()
  document.body.innerHTML = markup()
})

afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

test('draws the snippet and the checks at once from what the form holds', () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)

  expect(document.querySelector('[data-seo-snippet="title"]')?.textContent).toBe('Red shoes | Acme')
  expect(document.querySelector('[data-seo-snippet="url"]')?.textContent).toBe('example.com › red-shoes')
  expect(document.querySelector('[data-seo-card-title]')?.textContent).toBe('Red shoes | Acme')
  expect(status('keyphrase-in-title')).toBe('good')
  expect(status('keyphrase-in-slug')).toBe('good')
  expect(status('keyphrase-in-intro')).toBe('good')
  expect(status('keyphrase-in-headings')).toBe('good')
  expect(status('internal-links')).toBe('good')
  expect(document.querySelector('[data-check="title-width"] span.block')?.textContent).toBeTruthy()
  expect(document.querySelector('[data-seo-summary="seo"]')?.textContent).toMatch(/\d+ good, \d+ to improve, \d+ problems?/)
})

test('every status has an icon and a word, not colour alone', () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)

  for (const item of document.querySelectorAll<HTMLElement>('[data-seo-checks] li')) {
    expect(item.querySelector('svg[aria-hidden="true"]')).not.toBeNull()
    expect(['Good', 'Could be better', 'Problem']).toContain(item.querySelector('span.block')?.textContent)
  }
})

test('redraws when the form is edited', () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)

  const keyphrase = document.querySelector<HTMLInputElement>('[data-seo-input="keyphrase"]')!
  keyphrase.value = 'blue boots'
  keyphrase.dispatchEvent(new Event('input', { bubbles: true }))
  vi.advanceTimersByTime(200)

  expect(status('keyphrase-in-title')).toBe('bad')

  const description = document.querySelector<HTMLTextAreaElement>('[data-seo-input="description"]')!
  description.value = 'x'.repeat(130)
  description.dispatchEvent(new Event('input', { bubbles: true }))
  vi.advanceTimersByTime(200)

  expect(status('description-length')).toBe('good')
  expect(document.querySelector('[data-seo-counter="description"]')?.textContent).toBe('130 of 156')
})

test('asks the server a moment after the last edit and reads the page it answers', async () => {
  const fetcher = vi.fn(async (_url: string, _init: RequestInit) => ({
    json: async () => ({ rendered: true, headings: [{ level: 2, text: 'Blue boots' }], paragraphs: ['Blue boots only.'], order: ['heading', 'paragraph'], links: [], images: [{ alt: null }] }),
  }))
  vi.stubGlobal('fetch', fetcher)
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)

  expect(fetcher).not.toHaveBeenCalled()
  await vi.advanceTimersByTimeAsync(1000)

  expect(fetcher).toHaveBeenCalledOnce()
  const [url, init] = fetcher.mock.calls[0]!
  expect(url).toBe('/admin/post/1/seo-preview')
  expect((init.body as FormData).get('_token')).toBe('tok')
  expect((init.body as FormData).get('title')).toBe('Red shoes')
  expect(document.querySelector('[data-seo-note]')?.textContent).toBe('The checks read the page as a visitor sees it.')
  expect(status('image-alt')).toBe('bad')
  expect(status('keyphrase-in-intro')).toBe('bad')
})

test('a page that cannot be drawn falls back to the form and says why', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => ({ json: async () => ({ rendered: false, reason: 'This type has no page of its own to read.' }) })))
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)
  await vi.advanceTimersByTimeAsync(1000)

  expect(document.querySelector('[data-seo-note]')?.textContent).toContain('This type has no page of its own to read.')
  expect(status('keyphrase-in-intro')).toBe('good')
})

test('a failing request falls back to the form', async () => {
  vi.stubGlobal('fetch', vi.fn(async () => {
    throw new TypeError('offline')
  }))
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)
  await vi.advanceTimersByTimeAsync(1000)

  expect(document.querySelector('[data-seo-note]')?.textContent).toContain('could not be fetched')
})

test('the title the head will write: the SEO title wins, a missing site drops its separator', () => {
  expect(writtenTitle('%title% | %site%', 'Acme', 'Custom', 'Entry')).toBe('Custom | Acme')
  expect(writtenTitle('%title% | %site%', 'Acme', '  ', 'Entry')).toBe('Entry | Acme')
  expect(writtenTitle('%title% | %site%', '', '', 'Entry')).toBe('Entry')
})

test('renderChecks says how many of each', () => {
  const list = document.createElement('ul')
  const summary = document.createElement('p')
  renderChecks(list, summary, [
    { id: 'a', group: 'seo', status: 'good', message: 'Fine' },
    { id: 'b', group: 'seo', status: 'bad', message: 'Not fine' },
  ])

  expect(summary.textContent).toBe('1 good, 0 to improve, 1 problem')
  expect(list.children).toHaveLength(2)
  renderChecks(list, summary, [])
  expect(summary.textContent).toBe('')
})

test('a save refused only in the SEO tab opens the tab', () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  document.querySelector('#details')!.innerHTML = '<div data-seo-details-errors><p class="text-danger">Too long</p></div>'
  const opened = vi.fn()
  document.querySelector('#seo-tab')!.addEventListener('click', opened)
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)

  expect(opened).toHaveBeenCalledOnce()

  document.querySelector('#details')!.innerHTML += '<p class="text-danger">Another field</p>'
  document.body.innerHTML = document.body.innerHTML
  const again = vi.fn()
  document.querySelector('#seo-tab')!.addEventListener('click', again)
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)

  expect(again).not.toHaveBeenCalled()
})

test('the title is measured without the site name and separator', () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  document.querySelector<HTMLInputElement>('[name="title"]')!.value = 'c'.repeat(60)
  mountSeoTab(document.querySelector<HTMLElement>('[data-seo]')!)

  expect(document.querySelector('[data-seo-snippet="title"]')?.textContent).toBe(`${'c'.repeat(60)} | Acme`)
  expect(status('title-width')).toBe('good')
  expect(document.querySelector('[data-seo-counter="title"]')?.textContent).toBe('600px of 600px, title only')
})

test('an unreadable locale or origin never stops the tab drawing', () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  const root = document.querySelector<HTMLElement>('[data-seo]')!
  root.dataset.locale = 'pt_BR!!'
  root.dataset.origin = 'not a url'
  mountSeoTab(root)

  expect(document.querySelector('[data-seo-snippet="title"]')?.textContent).toBe('Red shoes | Acme')
  expect(document.querySelector('[data-seo-checks="seo"]')?.children.length).toBeGreaterThan(5)
  expect(document.querySelector('[data-seo-snippet="url"]')?.textContent).toBe('red-shoes')
})

test('says so when the checks cannot run, and still draws the preview', () => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})))
  vi.spyOn(Intl, 'Segmenter').mockImplementation(() => {
    throw new TypeError('no segmenter')
  })
  const root = document.querySelector<HTMLElement>('[data-seo]')!
  root.dataset.locale = 'sv'
  mountSeoTab(root)

  expect(document.querySelector('[data-seo-note]')?.textContent).toContain('The checks could not be run on this page.')
  expect(document.querySelector('[data-seo-snippet="title"]')?.textContent).toBe('Red shoes | Acme')
  vi.restoreAllMocks()
})
