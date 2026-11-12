/* Text as the checks see it: folded, and cut into words and sentences by the language's own rules. */

/* Lowercase, with accents taken off: "Café" and "cafe" are one word. Normalised
   to NFD first so a composed é and an e with a combining mark fold alike. */
export function fold(text: string): string {
  return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
}

const FALLBACK_LOCALE = 'en'

const segmenters = new Map<string, Intl.Segmenter>()

/*
 | A segmenter for a locale. Core accepts any string as a content locale, so
 | `pt_BR` (underscore) or a made-up one reaches here, and Intl.Segmenter
 | throws a RangeError for a tag it cannot read: underscores are hyphens, and
 | what is still refused is segmented as English.
 */
function segmenter(locale: string, granularity: 'word' | 'sentence'): Intl.Segmenter {
  const key = `${locale}|${granularity}`
  const kept = segmenters.get(key)

  if (kept !== undefined) {
    return kept
  }

  let made: Intl.Segmenter

  try {
    made = new Intl.Segmenter(locale.replaceAll('_', '-'), { granularity })
  } catch (error) {
    if (!(error instanceof RangeError)) {
      throw error
    }

    made = new Intl.Segmenter(FALLBACK_LOCALE, { granularity })
  }

  segmenters.set(key, made)

  return made
}

export function words(text: string, locale: string): string[] {
  const found: string[] = []

  for (const part of segmenter(locale, 'word').segment(text)) {
    if (part.isWordLike) {
      found.push(fold(part.segment))
    }
  }

  return found
}

/* Unfolded, for reading a sentence back to a person. */
export function sentences(text: string, locale: string): string[] {
  return [...segmenter(locale, 'sentence').segment(text)].map((part) => part.segment.trim()).filter((sentence) => /\p{L}/u.test(sentence))
}

export function wordCount(text: string, locale: string): number {
  return words(text, locale).length
}
