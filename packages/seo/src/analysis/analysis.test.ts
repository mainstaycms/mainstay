import { describe, expect, test, vi } from 'vitest'
import { analyse, emptyContent, fromDocument, titleWidth, type Check, type Page } from './analyse'
import { contentWords, countKeyphrase, slugHasKeyphrase } from './keyphrase'
import { languageOf } from './languages'
import { fold, sentences, words } from './text'

const EN_INTRO = 'Red shoes are the best shoes for a rainy day. Because they keep your feet dry, many people wear them.'

function page(over: Partial<Page> = {}): Page {
  return { ...emptyContent(), locale: 'en', title: '', description: '', slug: '', keyphrase: '', host: 'example.com', ...over }
}

function make(paragraphs: string[], headings: { level: number; text: string }[] = [], order?: ('heading' | 'paragraph')[]): Partial<Page> {
  return { paragraphs, headings, order: order ?? [...headings.map(() => 'heading' as const), ...paragraphs.map(() => 'paragraph' as const)] }
}

function byId(checks: Check[], id: string): Check {
  const found = checks.find((each) => each.id === id)

  if (found === undefined) {
    throw new Error(`No check ${id} in ${checks.map((each) => each.id).join(', ')}`)
  }

  return found
}

const run = (over: Partial<Page>, id: string): Check => byId(analyse(page(over)), id)

describe('text', () => {
  test('folds accents and case, in composed and decomposed forms', () => {
    expect(fold('CAFÉ')).toBe('cafe')
    expect(fold('Café')).toBe('cafe')
  })

  test('words and sentences follow the locale', () => {
    expect(words("Don't stop, 3.5 times!", 'en')).toEqual(["don't", 'stop', '3.5', 'times'])
    expect(sentences('One. Two! Three? ', 'en')).toEqual(['One.', 'Two!', 'Three?'])
    expect(words('', 'en')).toEqual([])
    expect(sentences('   ', 'en')).toEqual([])
  })

  test('keeps non-latin words', () => {
    expect(words('Привет мир', 'ru')).toEqual(['привет', 'мир'])
    expect(words('日本語のテキスト', 'ja').length).toBeGreaterThan(0)
  })
})

describe('locales', () => {
  test('an underscore locale is read as its hyphenated tag', () => {
    expect(words('Olá mundo', 'pt_BR')).toEqual(['ola', 'mundo'])
    expect(sentences('Um. Dois.', 'en_US')).toEqual(['Um.', 'Dois.'])
  })

  test('the hyphenated tag is what the segmenter is asked for, not the underscore one (ICU gives the same rules as the English fallback for these, so only the call shows it)', () => {
    const real = Intl.Segmenter
    const asked: unknown[] = []

    vi.spyOn(Intl, 'Segmenter').mockImplementation(function (locale, options) {
      asked.push(locale)

      return new real(locale, options)
    })

    words('Hallo wereld', 'nl_NL')
    vi.restoreAllMocks()

    expect(asked).toEqual(['nl-NL'])
  })

  test('a locale Intl.Segmenter refuses is segmented as English, and the analysis runs', () => {
    expect(words('Hello world', 'not a locale!!')).toEqual(['hello', 'world'])
    expect(sentences('One. Two.', '')).toEqual(['One.', 'Two.'])
    expect(() => analyse(page({ locale: 'zz__??', keyphrase: 'hello', title: 'Hello', ...make([EN_INTRO]) }))).not.toThrow()
    expect(() => analyse(page({ locale: 'pt_BR', keyphrase: 'sapatos', ...make(['Sapatos vermelhos para a chuva.']) }))).not.toThrow()
  })
})

describe('keyphrase', () => {
  const en = languageOf('en')
  const nl = languageOf('nl')

  test('content words leave the stop words out unless nothing else is left', () => {
    expect(contentWords('the red shoes', 'en', en)).toEqual(['red', 'shoes'])
    expect(contentWords('to be', 'en', en)).toEqual(['to', 'be'])
  })

  test('counts the phrase, its words one after another, and a plural is the same word', () => {
    const tokens = words('Red shoe here, red shoes there, a red hat and shoes', 'en')

    expect(countKeyphrase(tokens, 'red shoes', 'en', en)).toBe(2)
    expect(countKeyphrase([], 'red shoes', 'en', en)).toBe(0)
    expect(countKeyphrase(tokens, '', 'en', en)).toBe(0)
  })

  test('the slug holds the content words', () => {
    expect(slugHasKeyphrase('red-shoes-sale', 'the red shoes', 'en', en)).toBe(true)
    expect(slugHasKeyphrase('blue-shoes', 'red shoes', 'en', en)).toBe(false)
    expect(slugHasKeyphrase('', 'red shoes', 'en', en)).toBe(false)
  })

  test('Dutch matches a compound by substring, on purpose', () => {
    expect(nl.matches('zonnepanelen', 'paneel')).toBe(true)
    expect(nl.matches('zonnepaneel', 'paneel')).toBe(true)
    expect(nl.matches('fietsen', 'fiets')).toBe(true)
    expect(nl.matches('hond', 'hon')).toBe(false)
    expect(en.matches('zonnepaneel', 'paneel')).toBe(false)
  })
})

describe('snippet', () => {
  test('the width is Arial at 20px, an approximation', () => {
    expect(titleWidth('')).toBe(0)
    expect(titleWidth('iii')).toBeLessThan(titleWidth('WWW'))
    expect(titleWidth('Hello')).toBe(Math.round(((722 + 556 + 222 + 222 + 556) * 20) / 1000))
    expect(titleWidth('日本')).toBe(40)
  })
})

const fill = (count: number, word = 'lorem'): string => Array.from({ length: count }, () => word).join(' ')

/* A text of `total` words holding the keyphrase `times` times. */
const withPhrase = (times: number, total: number): Partial<Page> => make([`${'red shoes '.repeat(times)}${fill(total - 2 * times)}`])

describe('seo checks', () => {
  test('title width: none, 1 to 400px short, to 600px good, above bad (Arial 20px, a "c" is 10px)', () => {
    expect(run({ title: '' }, 'title-width').status).toBe('bad')
    expect(run({ title: 'c' }, 'title-width').status).toBe('ok')
    expect(run({ title: 'c'.repeat(40) }, 'title-width').status).toBe('ok')
    expect(run({ title: 'c'.repeat(41) }, 'title-width').status).toBe('good')
    expect(run({ title: 'c'.repeat(60) }, 'title-width').status).toBe('good')
    expect(run({ title: 'c'.repeat(61) }, 'title-width').status).toBe('bad')
  })

  test('description length: none, 120 or fewer, 121 to 156, 157 or more, in characters', () => {
    expect(run({}, 'description-length').status).toBe('bad')
    expect(run({ description: 'x'.repeat(1) }, 'description-length').status).toBe('ok')
    expect(run({ description: 'x'.repeat(120) }, 'description-length').status).toBe('ok')
    expect(run({ description: 'x'.repeat(121) }, 'description-length').status).toBe('good')
    expect(run({ description: 'x'.repeat(156) }, 'description-length').status).toBe('good')
    expect(run({ description: 'x'.repeat(157) }, 'description-length').status).toBe('ok')
    expect(run({ description: 'é'.repeat(156) }, 'description-length').status).toBe('good')
    expect(run({ description: '😀'.repeat(157) }, 'description-length').status).toBe('ok')
  })

  test('without a keyphrase it is one problem and the rest are left out', () => {
    const ids = analyse(page(make([EN_INTRO]))).map((check) => check.id)

    expect(ids).toContain('keyphrase-set')
    expect(ids.filter((id) => id.startsWith('keyphrase-') && id !== 'keyphrase-set')).toEqual([])
  })

  test('keyphrase length in content words: 4 good, 5 to 8 ok, 9 or more bad', () => {
    const phrase = (count: number) => ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota'].slice(0, count).join(' ')

    expect(run({ keyphrase: phrase(1) }, 'keyphrase-length').status).toBe('good')
    expect(run({ keyphrase: phrase(4) }, 'keyphrase-length').status).toBe('good')
    expect(run({ keyphrase: phrase(5) }, 'keyphrase-length').status).toBe('ok')
    expect(run({ keyphrase: phrase(8) }, 'keyphrase-length').status).toBe('ok')
    expect(run({ keyphrase: phrase(9) }, 'keyphrase-length').status).toBe('bad')
    expect(run({ keyphrase: 'the best of the red shoes' }, 'keyphrase-length').message).toContain('3 content words')
  })

  test('keyphrase in the title: at the start, elsewhere, absent', () => {
    expect(run({ keyphrase: 'red shoes', title: 'Red shoes for rain' }, 'keyphrase-in-title').status).toBe('good')
    expect(run({ keyphrase: 'red shoes', title: 'Buy our red shoes' }, 'keyphrase-in-title').status).toBe('ok')
    expect(run({ keyphrase: 'red shoes', title: 'Boots' }, 'keyphrase-in-title').status).toBe('bad')
  })

  test('keyphrase in the description: none, one or two sentences, three', () => {
    const description = (count: number) => Array.from({ length: count }, () => 'Red shoes are great.').join(' ')

    expect(run({ keyphrase: 'red shoes', description: 'About boots.' }, 'keyphrase-in-description').status).toBe('bad')
    expect(run({ keyphrase: 'red shoes', description: description(1) }, 'keyphrase-in-description').status).toBe('good')
    expect(run({ keyphrase: 'red shoes', description: description(2) }, 'keyphrase-in-description').status).toBe('good')
    expect(run({ keyphrase: 'red shoes', description: description(3) }, 'keyphrase-in-description').status).toBe('bad')
  })

  test('keyphrase in the first paragraph: one sentence, several, none', () => {
    const intro = (text: string) => ({ keyphrase: 'red shoes', ...make([text]) })

    expect(run(intro('Red shoes are fun. Everyone agrees.'), 'keyphrase-in-intro').status).toBe('good')
    expect(run(intro('Shoes are fun. Red is a colour.'), 'keyphrase-in-intro').status).toBe('ok')
    expect(run(intro('Boots are fun.'), 'keyphrase-in-intro').status).toBe('bad')
    expect(run({ keyphrase: 'red shoes', ...make(['Nothing here.', EN_INTRO]) }, 'keyphrase-in-intro').status).toBe('bad')
    expect(run({ keyphrase: 'red shoes', ...make([]) }, 'keyphrase-in-intro').status).toBe('bad')
  })

  test('keyphrase in the slug: all of a short keyphrase, more than half of a longer one', () => {
    const slug = (keyphrase: string, value: string) => run({ keyphrase, slug: value }, 'keyphrase-in-slug').status

    expect(slug('red shoes', 'red-shoes')).toBe('good')
    expect(slug('red shoes', 'red-boots')).toBe('bad')
    expect(slug('shoes', 'shoes')).toBe('good')
    expect(slug('alpha beta gamma', 'alpha-beta')).toBe('good')
    expect(slug('alpha beta gamma', 'alpha')).toBe('bad')
    expect(slug('alpha beta gamma delta', 'alpha-beta-gamma')).toBe('good')
    expect(slug('alpha beta gamma delta', 'alpha-beta')).toBe('bad')
    expect(slug('red shoes', '')).toBe('bad')
  })

  test('keyphrase in subheadings: 30 to 75% of H2 and H3 good, under or over bad', () => {
    const subheadings = (reflecting: number, total: number) => ({ keyphrase: 'red shoes', ...make([EN_INTRO], Array.from({ length: total }, (_, index) => ({ level: index % 2 === 0 ? 2 : 3, text: index < reflecting ? 'Red shoes' : 'Care' }))) })
    const status = (reflecting: number, total: number) => run(subheadings(reflecting, total), 'keyphrase-in-headings').status

    expect(status(3, 10)).toBe('good')
    expect(status(2, 10)).toBe('bad')
    expect(status(0, 10)).toBe('bad')
    expect(status(3, 4)).toBe('good')
    expect(status(4, 4)).toBe('bad')
    expect(status(8, 10)).toBe('bad')
    expect(status(7, 10)).toBe('good')
    expect(status(1, 1)).toBe('good')
    expect(status(0, 1)).toBe('bad')
    expect(status(1, 2)).toBe('good')
    expect(status(2, 2)).toBe('bad')
    expect(status(0, 2)).toBe('bad')
    expect(run({ keyphrase: 'red shoes', ...make([EN_INTRO], [{ level: 1, text: 'Red shoes' }]) }, 'keyphrase-in-headings').message).toContain('no subheadings')
  })

  test('a subheading reflects the topic with half of the keyphrase words', () => {
    const one = (keyphrase: string, heading: string) => run({ keyphrase, ...make([EN_INTRO], [{ level: 2, text: heading }]) }, 'keyphrase-in-headings').status

    expect(one('alpha beta gamma delta', 'Alpha beta')).toBe('good')
    expect(one('alpha beta gamma delta', 'Alpha')).toBe('bad')
    expect(one('alpha beta', 'Alpha')).toBe('good')
    expect(one('alpha beta', 'Other')).toBe('bad')
    expect(run({ keyphrase: 'alpha beta gamma delta', ...make([EN_INTRO], [{ level: 2, text: 'Alpha beta' }, { level: 2, text: 'Other' }]) }, 'keyphrase-in-headings').status).toBe('good')
  })

  test('no subheadings: fine for 300 words or fewer, a problem above', () => {
    expect(run({ keyphrase: 'red shoes', ...make([fill(300)]) }, 'keyphrase-in-headings').status).toBe('good')
    expect(run({ keyphrase: 'red shoes', ...make([fill(301)]) }, 'keyphrase-in-headings').status).toBe('bad')
  })

  test('keyphrase density in a text of 100 words or more: 0.5 to 3 percent', () => {
    const status = (times: number, total: number) => run({ keyphrase: 'red shoes', ...withPhrase(times, total) }, 'keyphrase-density').status

    expect(status(0, 200)).toBe('bad')
    expect(status(1, 200)).toBe('good')
    expect(status(1, 201)).toBe('bad')
    expect(status(6, 200)).toBe('good')
    expect(status(7, 200)).toBe('bad')
    expect(status(1, 100)).toBe('good')
    expect(status(3, 100)).toBe('good')
    expect(status(4, 100)).toBe('bad')
    expect(status(60, 2000)).toBe('good')
    expect(status(61, 2000)).toBe('bad')
    expect(status(10, 2000)).toBe('good')
    expect(status(9, 2000)).toBe('bad')
  })

  test('keyphrase density in a shorter text is a count: 51 to 99 words up to 2, fewer up to 1', () => {
    const status = (times: number, total: number) => run({ keyphrase: 'red shoes', ...withPhrase(times, total) }, 'keyphrase-density').status

    expect(status(2, 99)).toBe('good')
    expect(status(3, 99)).toBe('bad')
    expect(status(2, 51)).toBe('good')
    expect(status(3, 51)).toBe('bad')
    expect(status(1, 50)).toBe('good')
    expect(status(2, 50)).toBe('bad')
    expect(status(0, 50)).toBe('bad')
    expect(run({ keyphrase: 'red shoes' }, 'keyphrase-density').status).toBe('bad')
  })

  test('text length: under 250 bad, 250 to 299 ok, 300 or more good', () => {
    const text = (count: number) => make([fill(count)])

    expect(run(text(0), 'text-length').status).toBe('bad')
    expect(run(text(249), 'text-length').status).toBe('bad')
    expect(run(text(250), 'text-length').status).toBe('ok')
    expect(run(text(299), 'text-length').status).toBe('ok')
    expect(run(text(300), 'text-length').status).toBe('good')
  })

  test('links: internal, outbound, neither is a problem', () => {
    const links = (...hrefs: string[]) => ({ links: hrefs.map((href) => ({ href, text: 'x' })) })
    const checks = analyse(page(links('/about', 'https://example.com/team', '#top', 'mailto:a@b.c', 'tel:123', 'https://other.org/x', '//cdn.other.org/y', 'javascript:void(0)')))

    expect(byId(checks, 'internal-links').message).toContain('2 pages')
    expect(byId(checks, 'outbound-links').message).toContain('2 other sites')
    expect(run(links(), 'internal-links').status).toBe('bad')
    expect(run(links(), 'outbound-links').status).toBe('bad')
    expect(run(links('https://EXAMPLE.com/up'), 'internal-links').status).toBe('good')
    expect(run(links('http://[bad'), 'outbound-links').status).toBe('bad')
  })

  test('image alt text: no images is a problem, all with alt good, empty alt counts as present', () => {
    expect(run({}, 'image-alt').status).toBe('bad')
    expect(run({ images: [{ alt: 'A' }, { alt: '' }] }, 'image-alt').status).toBe('good')
    expect(run({ images: [{ alt: 'A' }, { alt: null }] }, 'image-alt').message).toBe('1 of 2 images have no alt text.')
  })

  test('Dutch: the keyphrase is found in a compound', () => {
    const nl = { locale: 'nl', keyphrase: 'zonnepaneel', title: 'Zonnepanelen kopen', ...make(['Wij verkopen zonnepanelen voor uw dak.']) }

    expect(run(nl, 'keyphrase-in-title').status).toBe('good')
    expect(run(nl, 'keyphrase-in-intro').status).toBe('good')
  })
})

const long = (sentence: string, times: number): string => Array.from({ length: times }, () => sentence).join(' ')

/* `first` sentences of one kind, then `second` of another, as one paragraph. */
const mixed = (first: [string, number], second: [string, number]): Partial<Page> => make([`${long(first[0], first[1])} ${long(second[0], second[1])}`])

describe('readability', () => {
  const easy = 'The cat sat on the mat. Then it ran away. However, the dog stayed home. Also, we ate a good meal. So we went to bed. We woke up late. Finally, the day began.'

  test('too little text is said so: 49 words, and 50 are judged', () => {
    const ids = (count: number) => analyse(page(make([fill(count)]))).filter((check) => check.group === 'readability').map((check) => check.id)

    expect(ids(49)).toEqual(['readability-text'])
    expect(ids(0)).toEqual(['readability-text'])
    expect(ids(50)).toContain('reading-ease')
  })

  test('easy short text passes every check', () => {
    const checks = analyse(page(make([easy, easy]))).filter((check) => check.group === 'readability')

    expect(checks.map((check) => `${check.id}:${check.status}`)).toEqual([
      'sentence-length:good',
      'paragraph-length:good',
      'subheading-distribution:good',
      'passive-voice:good',
      'transition-words:good',
      'consecutive-sentences:good',
      'reading-ease:good',
    ])
  })

  test('sentence length: the share over 20 words, 25 and 30 percent', () => {
    const longOne = `Alpha ${fill(20, 'beta')}.`
    const shortOne = 'Gamma delta here now.'
    const share = (longCount: number) => run(mixed([longOne, longCount], [shortOne, 20 - longCount]), 'sentence-length').status

    expect(share(5)).toBe('good')
    expect(share(6)).toBe('ok')
    expect(share(7)).toBe('bad')
    const of100 = (longCount: number) => run(mixed([longOne, longCount], [shortOne, 100 - longCount]), 'sentence-length').status

    expect(of100(25)).toBe('good')
    expect(run(mixed([longOne, 3], [shortOne, 7]), 'sentence-length').status).toBe('ok')
    expect(of100(26)).toBe('ok')
    expect(of100(30)).toBe('ok')
    expect(of100(31)).toBe('bad')
    expect(run(make([long(`Alpha ${fill(19, 'beta')}.`, 10)]), 'sentence-length').status).toBe('good')
    expect(run(make([long(longOne, 10)]), 'sentence-length').status).toBe('bad')
  })

  test('paragraph length: 150 good, to 200 ok, above bad', () => {
    const status = (count: number) => run(make([fill(count)]), 'paragraph-length').status

    expect(status(150)).toBe('good')
    expect(status(151)).toBe('ok')
    expect(status(200)).toBe('ok')
    expect(status(201)).toBe('bad')
  })

  test('subheading distribution: 300 words need none; between headings under 300 good, 300 to 350 ok, above bad', () => {
    /* The paragraphs with a heading between each two. */
    const status = (paragraphs: number[]) =>
      run(make(paragraphs.map((count) => fill(count)), paragraphs.slice(1).map(() => ({ level: 2, text: 'Middle' })), paragraphs.flatMap((_, index) => (index === 0 ? ['paragraph' as const] : ['heading' as const, 'paragraph' as const]))), 'subheading-distribution').status

    expect(status([300])).toBe('good')
    expect(status([301])).toBe('bad')
    expect(status([299, 299])).toBe('good')
    expect(status([300, 299])).toBe('ok')
    expect(status([300, 300])).toBe('ok')
    expect(status([60, 301])).toBe('ok')
    expect(status([60, 350])).toBe('ok')
    expect(status([60, 351])).toBe('bad')
    expect(status([351, 60])).toBe('bad')
  })

  test('passive voice, English: 10 and 15 percent', () => {
    const passive = 'The house was built here.'
    const active = 'We walk to the park.'
    const status = (count: number) => run(mixed([passive, count], [active, 20 - count]), 'passive-voice').status

    expect(status(0)).toBe('good')
    expect(status(2)).toBe('good')
    expect(status(3)).toBe('ok')
    expect(status(4)).toBe('bad')
    expect(run(mixed([passive, 6], [active, 34]), 'passive-voice').status).toBe('ok')
    expect(run(mixed([passive, 7], [active, 33]), 'passive-voice').status).toBe('bad')

    const of100 = (count: number) => run(mixed([passive, count], [active, 100 - count]), 'passive-voice').status

    expect(of100(10)).toBe('good')
    expect(of100(11)).toBe('ok')
    expect(of100(15)).toBe('ok')
    expect(of100(16)).toBe('bad')
  })

  test('passive voice, Dutch: the participle ends the clause', () => {
    const passive = 'Het huis wordt in de zomer door hem gebouwd.'
    const active = 'Wij bouwen elke dag met veel plezier een huis.'
    const status = (count: number) => byId(analyse(page({ locale: 'nl', ...mixed([passive, count], [active, 20 - count]) })), 'passive-voice').status

    expect(status(2)).toBe('good')
    expect(status(3)).toBe('ok')
    expect(status(4)).toBe('bad')
  })

  test('transition words: only a text of more than 200 words is judged, then 20 and 30 percent', () => {
    const linked = 'However we walk to the park every single day now.'
    const plain = 'Birds sing in the tall green trees every morning too.'
    const status = (linkedCount: number, total: number) => run(mixed([linked, linkedCount], [plain, total - linkedCount]), 'transition-words').status

    expect(status(0, 20)).toBe('good')
    expect(status(0, 21)).toBe('bad')
    expect(status(9, 30)).toBe('good')
    expect(status(8, 30)).toBe('ok')
    expect(status(6, 30)).toBe('ok')
    expect(status(5, 30)).toBe('bad')
    expect(status(30, 100)).toBe('good')
    expect(status(29, 100)).toBe('ok')
    expect(status(20, 100)).toBe('ok')
    expect(status(19, 100)).toBe('bad')
  })

  test('transition words, Dutch', () => {
    const checks = analyse(page({ locale: 'nl', ...make([long('Echter, de hond blaft elke nacht tegen de maan in de tuin. Ten eerste is dat lawaai. ', 12)]) }))

    expect(byId(checks, 'transition-words').status).toBe('good')
  })

  test('consecutive sentences starting on the same word: three in a row', () => {
    const pad = 'Birds sing early. Rivers run cold. Hills stand tall. Clouds drift by. Stars shine bright. Winds blow soft. Rain falls here. Snow melts fast. Fields grow green. Fish swim deep. '
    const two = 'We went home. We ate dinner. Then it rained. The sky cleared. '
    const three = 'We went home. We ate dinner. We watched television. Then it rained. '

    expect(run(make([two + pad + pad]), 'consecutive-sentences').status).toBe('good')
    expect(run(make([three + pad + pad]), 'consecutive-sentences').status).toBe('bad')
    expect(run(make([two, two + pad]), 'consecutive-sentences').status).toBe('good')
  })

  test('reading ease, English (Flesch): 60 good, 50 ok, under bad', () => {
    const status = (count: number, locale = 'en') => byId(analyse(page({ locale, ...make([`${fill(count, locale === 'en' ? 'cat' : 'kat')}.`]) })), 'reading-ease').status

    expect(status(61)).toBe('good')
    expect(status(62)).toBe('ok')
    expect(status(71)).toBe('ok')
    expect(status(72)).toBe('bad')
  })

  test('reading ease, Dutch (Douma)', () => {
    const status = (count: number) => byId(analyse(page({ locale: 'nl', ...make([`${fill(count, 'kat')}.`]) })), 'reading-ease').status

    expect(status(75)).toBe('good')
    expect(status(76)).toBe('ok')
    expect(status(86)).toBe('ok')
    expect(status(87)).toBe('bad')
  })

  test('syllables are counted by each language', () => {
    expect(languageOf('en').syllables('table')).toBe(2)
    expect(languageOf('en').syllables('make')).toBe(1)
    expect(languageOf('en').syllables('a')).toBe(1)
    expect(languageOf('nl').syllables('boom')).toBe(1)
    expect(languageOf('nl').syllables('coördinatie')).toBeGreaterThanOrEqual(4)
    expect(languageOf('nl-BE')).toBe(languageOf('nl'))
    expect(languageOf('fr')).toBe(languageOf('en'))
  })

  test('a sentence starting in lowercase is not split off, which Intl.Segmenter decides', () => {
    expect(sentences('one two. three four. Five six.', 'en')).toEqual(['one two. three four.', 'Five six.'])
  })
})

describe('extract', () => {
  test('reads headings, paragraphs and links from a document, and leaves site blocks to the page', () => {
    const doc = {
      type: 'doc',
      content: [
        { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'Title' }] },
        { type: 'paragraph', content: [{ type: 'text', text: 'See ' }, { type: 'text', text: 'this', marks: [{ type: 'link', attrs: { href: '/x' } }] }, { type: 'hard_break' }, { type: 'text', text: 'now' }] },
        { type: 'block', attrs: { id: 'a', type: 'hero', data: {} }, content: [{ type: 'paragraph', content: [{ type: 'text', text: 'In a block' }] }] },
        { type: 'bullet_list', content: [{ type: 'list_item', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Item' }] }] }] },
        { type: 'paragraph' },
      ],
    }
    const content = fromDocument(JSON.stringify(doc))

    expect(content.headings).toEqual([{ level: 1, text: 'Title' }])
    expect(content.paragraphs).toEqual(['See this now', 'Item'])
    expect(content.order).toEqual(['heading', 'paragraph', 'paragraph'])
    expect(content.links).toEqual([{ href: '/x', text: 'this' }])
  })

  test('anything that is not a document is no content', () => {
    expect(fromDocument('')).toEqual(emptyContent())
    expect(fromDocument('not json')).toEqual(emptyContent())
    expect(fromDocument('null')).toEqual(emptyContent())
  })
})

describe('edge cases', () => {
  test('empty everything does not throw', () => {
    expect(() => analyse(page())).not.toThrow()
    expect(() => analyse(page({ keyphrase: 'x', ...make(['']) }))).not.toThrow()
  })

  test('unicode text: emoji, combining marks and mixed scripts', () => {
    const text = 'Café 😀 naïve résumé Привет 日本語. '.repeat(30)

    expect(() => analyse(page({ keyphrase: 'café', title: 'Café', ...make([text]) }))).not.toThrow()
    expect(run({ keyphrase: 'cafe', ...make([text]) }, 'keyphrase-in-intro').status).toBe('good')
  })

  test('a very large text is analysed in good time', () => {
    const paragraph = long('The quick brown fox jumps over the lazy dog, and then it rests. ', 120)
    const started = performance.now()
    const checks = analyse(page({ keyphrase: 'brown fox', ...make(Array.from({ length: 100 }, () => paragraph)) }))

    expect(checks.length).toBeGreaterThan(10)
    expect(performance.now() - started).toBeLessThan(5000)
  })
})
