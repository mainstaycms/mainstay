import { containsKeyphrase, contentWords, countKeyphrase, foundWords, inOneSentence, sentencesWithKeyphrase, slugHasKeyphrase, startsWithKeyphrase } from './keyphrase'
import { languageOf } from './languages'
import { titleWidth } from './snippet'
import { words } from './text'
import {
  DENSITY_LONG_TEXT,
  DENSITY_MAX,
  DENSITY_MEDIUM_MAX_COUNT,
  DENSITY_MEDIUM_TEXT,
  DENSITY_MIN,
  DENSITY_SHORT_MAX_COUNT,
  DESCRIPTION_KEYPHRASE_MAX,
  DESCRIPTION_LONG,
  DESCRIPTION_SHORT,
  KEYPHRASE_WORDS_GOOD,
  KEYPHRASE_WORDS_OK,
  SECTION_WORDS_GOOD,
  SUBHEADING_SHARE_MAX,
  SUBHEADING_SHARE_MIN,
  TEXT_LENGTH_GOOD,
  TEXT_LENGTH_OK,
  TITLE_MAX_WIDTH,
  TITLE_SHORT_WIDTH,
} from './thresholds'
import type { Check, Page, Status } from './types'

const check = (id: string, status: Status, message: string): Check => ({ id, group: 'seo', status, message })

const plural = (count: number, noun: string): string => `${count} ${noun}${count === 1 ? '' : 's'}`

/* Whether a link leaves the site: it names another host. A path, a fragment, mail and phone are not. */
function isOutbound(href: string, host: string): boolean {
  if (!/^(https?:)?\/\//i.test(href)) {
    return false
  }

  try {
    return new URL(href, `https://${host || 'localhost'}`).hostname.toLowerCase() !== host.toLowerCase().split(':')[0]
  } catch {
    return false
  }
}

function isInternal(href: string, host: string): boolean {
  if (href.trim() === '' || href.startsWith('#') || (/^[a-z][a-z0-9+.-]*:/i.test(href) && !/^https?:/i.test(href))) {
    return false
  }

  return !isOutbound(href, host)
}

export function seoChecks(page: Page): Check[] {
  const keyphrase = page.keyphrase.trim()
  const tokens = words(page.paragraphs.join(' '), page.locale)
  const checks: Check[] = [...snippetChecks(page)]

  if (keyphrase === '') {
    checks.push(check('keyphrase-set', 'bad', 'No focus keyphrase is set. Set one to check the page is about it.'))
  } else {
    checks.push(...keyphraseChecks(page, keyphrase, tokens))
  }

  checks.push(lengthCheck(tokens.length), ...linkChecks(page), imageCheck(page))

  return checks
}

function snippetChecks(page: Page): Check[] {
  const width = titleWidth(page.title)
  const length = [...page.description.trim()].length
  const title =
    page.title.trim() === ''
      ? check('title-width', 'bad', 'The page has no title.')
      : width > TITLE_MAX_WIDTH
        ? check('title-width', 'bad', `The title is about ${width}px wide, over the ${TITLE_MAX_WIDTH}px a search result shows. It will be cut off.`)
        : width <= TITLE_SHORT_WIDTH
          ? check('title-width', 'ok', `The title is about ${width}px wide. Up to ${TITLE_MAX_WIDTH}px is shown, so there is room to say more.`)
          : check('title-width', 'good', `The title is about ${width}px wide, within the ${TITLE_MAX_WIDTH}px a search result shows.`)
  const description =
    length === 0
      ? check('description-length', 'bad', 'There is no meta description. A search result will show whatever text it picks.')
      : length >= DESCRIPTION_LONG
        ? check('description-length', 'ok', `The meta description is ${length} characters. A search result shows up to ${DESCRIPTION_LONG - 1}; shorten it so all of it is seen.`)
        : length <= DESCRIPTION_SHORT
          ? check('description-length', 'ok', `The meta description is ${length} characters. Up to ${DESCRIPTION_LONG - 1} are shown, so there is room to say more.`)
          : check('description-length', 'good', `The meta description is ${length} characters, between ${DESCRIPTION_SHORT + 1} and ${DESCRIPTION_LONG - 1}.`)

  return [title, description]
}

function keyphraseChecks(page: Page, keyphrase: string, tokens: string[]): Check[] {
  const language = languageOf(page.locale)
  const has = (text: string) => containsKeyphrase(text, keyphrase, page.locale, language)

  return [
    lengthOfKeyphrase(contentWords(keyphrase, page.locale, language).length),
    titleCheck(page, keyphrase),
    descriptionCheck(page, keyphrase),
    subheadingCheck(page, keyphrase, tokens.length),
    introCheck(page, keyphrase, has),
    slugHasKeyphrase(page.slug, keyphrase, page.locale, language)
      ? check('keyphrase-in-slug', 'good', 'The keyphrase is in the address.')
      : check('keyphrase-in-slug', 'bad', 'The keyphrase, or too much of it, is not in the address.'),
    densityCheck(keyphrase, tokens, page),
  ]
}

function lengthOfKeyphrase(count: number): Check {
  const said = `The keyphrase is ${plural(count, 'content word')}`

  return count <= KEYPHRASE_WORDS_GOOD
    ? check('keyphrase-length', 'good', `${said}, at most ${KEYPHRASE_WORDS_GOOD}.`)
    : check('keyphrase-length', count <= KEYPHRASE_WORDS_OK ? 'ok' : 'bad', `${said}. More than ${KEYPHRASE_WORDS_GOOD} is too specific to be searched for; make it shorter.`)
}

function titleCheck(page: Page, keyphrase: string): Check {
  const language = languageOf(page.locale)

  return !containsKeyphrase(page.title, keyphrase, page.locale, language)
    ? check('keyphrase-in-title', 'bad', 'The keyphrase is not in the title.')
    : startsWithKeyphrase(page.title, keyphrase, page.locale, language)
      ? check('keyphrase-in-title', 'good', 'The title starts with the keyphrase.')
      : check('keyphrase-in-title', 'ok', 'The keyphrase is in the title, but not at its start, where it counts most.')
}

function descriptionCheck(page: Page, keyphrase: string): Check {
  const matches = sentencesWithKeyphrase(page.description, keyphrase, page.locale, languageOf(page.locale))

  return matches === 0
    ? check('keyphrase-in-description', 'bad', 'The keyphrase is not in the meta description.')
    : matches > DESCRIPTION_KEYPHRASE_MAX
      ? check('keyphrase-in-description', 'bad', `The keyphrase is in ${matches} sentences of the meta description, over the advised ${DESCRIPTION_KEYPHRASE_MAX}.`)
      : check('keyphrase-in-description', 'good', 'The keyphrase is in the meta description.')
}

/* In the first paragraph: all of it in one sentence, or spread over several, or not there. */
function introCheck(page: Page, keyphrase: string, has: (text: string) => boolean): Check {
  const intro = page.paragraphs[0] ?? ''

  return inOneSentence(intro, keyphrase, page.locale, languageOf(page.locale))
    ? check('keyphrase-in-intro', 'good', 'The keyphrase is in the first paragraph.')
    : has(intro)
      ? check('keyphrase-in-intro', 'ok', 'The keyphrase is in the first paragraph, but not within one sentence.')
      : check('keyphrase-in-intro', 'bad', 'The keyphrase is not in the first paragraph. Say what the page is about at once.')
}

/* A subheading reflects the topic when at least half of the keyphrase's content words are in it. */
function subheadingCheck(page: Page, keyphrase: string, textWords: number): Check {
  const language = languageOf(page.locale)
  const subheadings = page.headings.filter((heading) => heading.level === 2 || heading.level === 3)
  const reflecting = subheadings.filter((heading) => {
    const [present, total] = foundWords(heading.text, keyphrase, page.locale, language)

    return total > 0 && present * 2 >= total
  }).length

  if (subheadings.length === 0) {
    return textWords > SECTION_WORDS_GOOD
      ? check('keyphrase-in-headings', 'bad', 'The text has no subheadings (H2 or H3) to put the keyphrase in. Add some.')
      : check('keyphrase-in-headings', 'good', `The page has no subheadings, but its text is ${SECTION_WORDS_GOOD} words or fewer and needs none.`)
  }

  /* The only subheading reflecting the topic is as good as can be: a share of it means nothing. */
  if (subheadings.length === 1) {
    return reflecting === 1
      ? check('keyphrase-in-headings', 'good', 'Your only subheading reflects the keyphrase.')
      : check('keyphrase-in-headings', 'bad', 'Your only subheading does not reflect the keyphrase. Use it there.')
  }

  const share = reflecting / subheadings.length
  const said = `${reflecting} of ${plural(subheadings.length, 'subheading')} reflect${reflecting === 1 ? 's' : ''} the keyphrase`

  return share < SUBHEADING_SHARE_MIN
    ? check('keyphrase-in-headings', 'bad', `${said}, under ${SUBHEADING_SHARE_MIN * 100}%. Use it in more of them.`)
    : share > SUBHEADING_SHARE_MAX
      ? check('keyphrase-in-headings', 'bad', `${said}, over ${SUBHEADING_SHARE_MAX * 100}%. That is too much: use it in fewer.`)
      : check('keyphrase-in-headings', 'good', `${said}.`)
}

/* By share of the words in a text of 100 or more, and by count in a shorter one, where a share means little. */
function densityCheck(keyphrase: string, tokens: string[], page: Page): Check {
  const count = countKeyphrase(tokens, keyphrase, page.locale, languageOf(page.locale))
  const density = tokens.length === 0 ? 0 : (count * 100) / tokens.length
  const said = `The keyphrase is ${plural(count, 'time')} in the text`

  if (count === 0) {
    return check('keyphrase-density', 'bad', 'The keyphrase is not in the text.')
  }

  if (tokens.length < DENSITY_LONG_TEXT) {
    const most = tokens.length >= DENSITY_MEDIUM_TEXT ? DENSITY_MEDIUM_MAX_COUNT : DENSITY_SHORT_MAX_COUNT

    return count <= most
      ? check('keyphrase-density', 'good', `${said}. For a text this short, up to ${most} is right.`)
      : check('keyphrase-density', 'bad', `${said}. For a text this short, more than ${most} reads as stuffed.`)
  }

  const shown = `${said}, ${density.toFixed(1)}% of it`

  return density > DENSITY_MAX
    ? check('keyphrase-density', 'bad', `${shown}. Over ${DENSITY_MAX}% reads as stuffed.`)
    : density < DENSITY_MIN
      ? check('keyphrase-density', 'bad', `${shown}. Under ${DENSITY_MIN}% is too thin.`)
      : check('keyphrase-density', 'good', `${shown}.`)
}

function lengthCheck(count: number): Check {
  return count >= TEXT_LENGTH_GOOD
    ? check('text-length', 'good', `The text is ${plural(count, 'word')}, at least ${TEXT_LENGTH_GOOD}.`)
    : count >= TEXT_LENGTH_OK
      ? check('text-length', 'ok', `The text is ${count} words, slightly under ${TEXT_LENGTH_GOOD}.`)
      : check('text-length', 'bad', `The text is ${plural(count, 'word')}, under ${TEXT_LENGTH_OK}. Write more.`)
}

function linkChecks(page: Page): Check[] {
  const internal = page.links.filter((link) => isInternal(link.href, page.host)).length
  const outbound = page.links.filter((link) => isOutbound(link.href, page.host)).length

  return [
    internal > 0 ? check('internal-links', 'good', `The page links to ${plural(internal, 'page')} on this site.`) : check('internal-links', 'bad', 'The page links to nothing else on this site. Add some links.'),
    outbound > 0 ? check('outbound-links', 'good', `The page links to ${plural(outbound, 'other site')}.`) : check('outbound-links', 'bad', 'The page links to no other site. Add some links.'),
  ]
}

function imageCheck(page: Page): Check {
  if (page.images.length === 0) {
    return check('image-alt', 'bad', 'The page has no images. Add some.')
  }

  const missing = page.images.filter((image) => image.alt === null).length

  return missing === 0
    ? check('image-alt', 'good', `All ${page.images.length} image${page.images.length === 1 ? ' has' : 's have'} alt text.`)
    : check('image-alt', 'bad', `${missing} of ${page.images.length} image${page.images.length === 1 ? ' has' : 's have'} no alt text.`)
}
