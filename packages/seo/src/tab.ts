import {
  analyse,
  emptyContent,
  fromDocument,
  titleWidth,
  type Check,
  type Content,
  type Page,
  type Status,
} from './analysis/analyse'
import { DESCRIPTION_LONG, TITLE_MAX_WIDTH } from './analysis/thresholds'

/* The most characters of a description a search result shows. */
const DESCRIPTION_SHOWN = DESCRIPTION_LONG - 1

/*
 | The SEO tab's behaviour. On every edit the checks run at once on what the
 | form holds -- the title, the slug, the rich text's document -- and, a moment
 | after the last edit, the server is asked for the page as a visitor sees it,
 | whose real headings, paragraphs, links and images then replace the form's.
 | Without that answer, or where the type has no page, the checks still run on
 | the form alone.
 */

const INSTANT_DELAY = 150
const SERVER_DELAY = 900
const WATCH_INTERVAL = 1000

const LABELS: Record<Status, string> = { good: 'Good', ok: 'Could be better', bad: 'Problem' }

const SVG = 'http://www.w3.org/2000/svg'

const ICONS: Record<Status, string[]> = {
  good: ['M5 8.5l2 2 4-4.5'],
  ok: ['M8 4.5v4', 'M8 11h.01'],
  bad: ['M5.5 5.5l5 5', 'M10.5 5.5l-5 5'],
}

function icon(status: Status): SVGElement {
  const svg = document.createElementNS(SVG, 'svg')
  svg.setAttribute('viewBox', '0 0 16 16')
  svg.setAttribute('aria-hidden', 'true')
  svg.setAttribute('fill', 'none')
  svg.setAttribute('stroke', 'currentColor')
  svg.setAttribute('stroke-width', '1.5')
  svg.setAttribute('stroke-linecap', 'round')
  svg.setAttribute('stroke-linejoin', 'round')
  svg.setAttribute('class', 'size-4 shrink-0')

  const outline = document.createElementNS(SVG, status === 'ok' ? 'path' : 'circle')

  if (status === 'ok') {
    outline.setAttribute('d', 'M8 1.75l6.5 11.5h-13z')
  } else {
    outline.setAttribute('cx', '8')
    outline.setAttribute('cy', '8')
    outline.setAttribute('r', '6.25')
  }

  svg.append(outline)

  for (const d of ICONS[status]) {
    const path = document.createElementNS(SVG, 'path')
    path.setAttribute('d', d)
    svg.append(path)
  }

  return svg
}

function query<T extends HTMLElement>(root: ParentNode, selector: string): T | null {
  return root.querySelector<T>(selector)
}

export function renderChecks(list: HTMLElement, summary: HTMLElement | null, checks: Check[]): void {
  list.replaceChildren(
    ...checks.map((check) => {
      const item = document.createElement('li')
      item.dataset.status = check.status
      item.dataset.check = check.id
      item.className = 'flex items-start gap-2.5 px-5 py-2.5 text-sm'

      const mark = document.createElement('span')
      mark.className = 'seo-status mt-0.5'
      mark.append(icon(check.status))

      const text = document.createElement('span')
      text.className = 'min-w-0 flex-1'
      const label = document.createElement('span')
      label.className = 'block text-xs font-medium seo-status'
      label.textContent = LABELS[check.status]
      const message = document.createElement('span')
      message.textContent = check.message
      text.append(label, message)

      item.append(mark, text)

      return item
    }),
  )

  if (summary !== null) {
    const count = (status: Status) => checks.filter((check) => check.status === status).length
    summary.textContent = checks.length === 0 ? '' : `${count('good')} good, ${count('ok')} to improve, ${count('bad')} ${count('bad') === 1 ? 'problem' : 'problems'}`
  }
}

/* The host of the page's origin, or none for an origin that is not a URL. */
function hostOf(origin: string): string {
  try {
    return origin === '' ? '' : new URL(origin).host
  } catch {
    return ''
  }
}

/* A search result's address line: the host and the page's path as breadcrumbs. */
function addressLine(origin: string, slug: string): string {
  const host = hostOf(origin)
  const parts = slug.split('/').filter(Boolean)

  return [host, ...parts].filter(Boolean).join(' › ')
}

function truncate(text: string, limit: number): string {
  const characters = [...text]

  return characters.length > limit ? `${characters.slice(0, limit).join('').trimEnd()}…` : text
}

/* The title as the head will write it: the SEO title, else the entry's, in the locale's template. */
export function writtenTitle(template: string, site: string, own: string, entry: string): string {
  const page = own.trim() !== '' ? own.trim() : entry.trim()
  const filled = template.replaceAll('%title%', page).replaceAll('%site%', site)

  return site === '' ? filled.replace(/^[\s|\-–—·•:]+|[\s|\-–—·•:]+$/gu, '') : filled
}

export function mountSeoTab(root: HTMLElement): void {
  const form = root.closest('form')

  if (form === null) {
    return
  }

  const field = root.dataset.field ?? 'seo'
  const origin = root.dataset.origin ?? ''
  const input = (name: string) => query<HTMLInputElement | HTMLTextAreaElement>(root, `[data-seo-input="${name}"]`)
  const read = (name: string) => input(name)?.value ?? ''
  const formValue = (name: string | undefined) => (name === undefined ? '' : (query<HTMLInputElement>(form, `[name="${name.replaceAll('"', '\\"')}"]`)?.value ?? ''))
  const set = (selector: string, text: string) => {
    const element = query(root, selector)

    if (element !== null) {
      element.textContent = text
    }
  }

  let served: Content | null = null
  let note = ''
  let instant: number | undefined
  let server: number | undefined
  let asking: AbortController | undefined
  let lastSeen = ''
  let lastSent = ''

  const snapshot = (): string => JSON.stringify([...new FormData(form)].map(([name, value]) => [name, typeof value === 'string' ? value : value.name]))
  const withoutSeo = (): string => JSON.stringify([...new FormData(form)].filter(([name]) => !name.startsWith(`${field}[`) && name !== '_token').map(([name, value]) => [name, typeof value === 'string' ? value : value.name]))

  const page = (): Page => {
    const title = formValue('title')
    const content = served ?? (root.dataset.contentField === undefined ? emptyContent() : fromDocument(formValue(root.dataset.contentField)))
    const description = read('description').trim() !== '' ? read('description') : (root.dataset.defaultDescription ?? '')

    return {
      ...content,
      locale: root.dataset.locale ?? 'en',
      title: read('title').trim() !== '' ? read('title').trim() : title.trim(),
      description,
      slug: formValue(root.dataset.slugField),
      keyphrase: read('keyphrase'),
      host: hostOf(origin),
    }
  }

  const draw = (): void => {
    const current = page()
    let checks: Check[] = []
    let failure = ''

    try {
      checks = analyse(current)
    } catch {
      failure = 'The checks could not be run on this page.'
    }

    const width = titleWidth(current.title)
    const length = [...read('description')].length

    set('[data-seo-template]', root.dataset.template ?? '')
    set('[data-seo-counter="title"]', `${width}px of ${TITLE_MAX_WIDTH}px, title only`)
    set('[data-seo-counter="description"]', `${length} of ${DESCRIPTION_SHOWN}`)
    set('[data-seo-snippet="url"]', addressLine(origin, current.slug))
    const written = writtenTitle(root.dataset.template ?? '%title%', root.dataset.site ?? '', read('title'), current.title)

    set('[data-seo-snippet="title"]', written)
    set('[data-seo-snippet="description"]', truncate(current.description, DESCRIPTION_SHOWN))
    set('[data-seo-note]', [failure, note].filter(Boolean).join(' '))

    const socialTitle = read('social_title').trim() !== '' ? read('social_title') : written
    const socialDescription = read('social_description').trim() !== '' ? read('social_description') : current.description
    set('[data-seo-card-host]', hostOf(origin))
    set('[data-seo-card-title]', socialTitle)
    set('[data-seo-card-description]', truncate(socialDescription, 200))

    const picked = query<HTMLImageElement>(root, '[data-seo-image] img[src]:not([src="about:blank"])')
    const card = query<HTMLImageElement>(root, '[data-seo-card-image]')

    if (card !== null) {
      card.hidden = picked === null
      card.src = picked?.src ?? ''
    }

    for (const group of ['seo', 'readability'] as const) {
      const list = query(root, `[data-seo-checks="${group}"]`)

      if (list !== null) {
        renderChecks(list, query(root, `[data-seo-summary="${group}"]`), checks.filter((check) => check.group === group))
      }
    }
  }

  const ask = async (): Promise<void> => {
    const sent = withoutSeo()

    if (sent === lastSent || root.dataset.endpoint === undefined) {
      return
    }

    lastSent = sent
    asking?.abort()
    asking = new AbortController()

    try {
      const body = new FormData(form)
      body.set('_token', query<HTMLInputElement>(form, 'input[name="_token"]')?.value ?? '')
      const response = await fetch(root.dataset.endpoint, { method: 'POST', body, signal: asking.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      const answer = (await response.json()) as { rendered?: boolean; reason?: string } & Partial<Content>

      if (answer.rendered === true) {
        served = { headings: answer.headings ?? [], paragraphs: answer.paragraphs ?? [], order: answer.order ?? [], links: answer.links ?? [], images: answer.images ?? [] }
        note = 'The checks read the page as a visitor sees it.'
      } else {
        served = null
        note = `The checks read the text in this form only. ${answer.reason ?? 'The page could not be drawn.'}`
      }
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        return
      }

      served = null
      note = 'The checks read the text in this form only. The page could not be fetched.'
    }

    draw()
  }

  const changed = (): void => {
    window.clearTimeout(instant)
    window.clearTimeout(server)
    instant = window.setTimeout(draw, INSTANT_DELAY)
    server = window.setTimeout(() => void ask(), SERVER_DELAY)
  }

  form.addEventListener('input', changed)
  form.addEventListener('change', changed)

  /* The rich text writes its document into a hidden input without an event. */
  window.setInterval(() => {
    const now = snapshot()

    if (now !== lastSeen && root.closest('[hidden]') === null) {
      lastSeen = now
      changed()
    }
  }, WATCH_INTERVAL)

  opensOnItsOwnErrors(form)
  lastSeen = snapshot()
  draw()
  server = window.setTimeout(() => void ask(), SERVER_DELAY)
}

/* A refused save opens on Details. When the only thing it refused is in the SEO tab, the tab opens instead. */
function opensOnItsOwnErrors(form: HTMLFormElement): void {
  const details = form.querySelector('#details')
  const mine = details?.querySelector('[data-seo-details-errors]')

  if (details === null || mine === null || mine === undefined) {
    return
  }

  const all = [...details.querySelectorAll('.text-danger')]

  if (all.every((message) => mine.contains(message))) {
    form.querySelector<HTMLElement>('#seo-tab')?.click()
  }
}

export function mountAll(scope: ParentNode = document): void {
  scope.querySelectorAll<HTMLElement>('[data-seo]').forEach(mountSeoTab)
}
