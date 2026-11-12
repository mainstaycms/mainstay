import { fold, sentences, words } from './text'
import { SLUG_SHORT_WORDS } from './thresholds'
import type { LanguageData } from './types'

/*
 | The keyphrase as the checks look for it. Its content words are what must be
 | found -- the stop words around them are not -- unless it holds nothing else.
 */
export function contentWords(keyphrase: string, locale: string, language: LanguageData): string[] {
  const all = words(keyphrase, locale)
  const content = all.filter((word) => !language.stopWords.has(word))

  return content.length > 0 ? content : all
}

function found(tokens: string[], keyword: string, language: LanguageData): boolean {
  return tokens.some((token) => language.matches(token, keyword))
}

/* Whether every content word of the keyphrase is in one sentence of the text. */
export function inOneSentence(text: string, keyphrase: string, locale: string, language: LanguageData): boolean {
  return sentences(text, locale).some((sentence) => containsKeyphrase(sentence, keyphrase, locale, language))
}

/* How many sentences of the text hold every content word of the keyphrase. */
export function sentencesWithKeyphrase(text: string, keyphrase: string, locale: string, language: LanguageData): number {
  return sentences(text, locale).filter((sentence) => containsKeyphrase(sentence, keyphrase, locale, language)).length
}

/* Whether every content word of the keyphrase is somewhere in the text, in any order. */
export function containsKeyphrase(text: string, keyphrase: string, locale: string, language: LanguageData): boolean {
  const keywords = contentWords(keyphrase, locale, language)
  const tokens = words(text, locale)

  return keywords.length > 0 && keywords.every((keyword) => found(tokens, keyword, language))
}

/* How many of the keyphrase's content words the text holds, and how many it has. */
export function foundWords(text: string, keyphrase: string, locale: string, language: LanguageData): [number, number] {
  const keywords = contentWords(keyphrase, locale, language)
  const tokens = words(text, locale)

  return [keywords.filter((keyword) => found(tokens, keyword, language)).length, keywords.length]
}

/* Whether the text opens with the keyphrase's words, in order. */
export function startsWithKeyphrase(text: string, keyphrase: string, locale: string, language: LanguageData): boolean {
  const keywords = words(keyphrase, locale)
  const tokens = words(text, locale)

  return keywords.length > 0 && keywords.every((keyword, index) => tokens[index] !== undefined && language.matches(tokens[index], keyword))
}

/* How often the keyphrase stands in the text as a phrase: its words one after another. */
export function countKeyphrase(tokens: string[], keyphrase: string, locale: string, language: LanguageData): number {
  const keywords = words(keyphrase, locale)
  let count = 0

  for (let start = 0; start + keywords.length <= tokens.length; start++) {
    if (keywords.length > 0 && keywords.every((keyword, offset) => language.matches(tokens[start + offset] ?? '', keyword))) {
      count++
      start += keywords.length - 1
    }
  }

  return count
}

/* Whether a slug holds the keyphrase's content words: all of a short keyphrase's, more than half of a longer one's. */
export function slugHasKeyphrase(slug: string, keyphrase: string, locale: string, language: LanguageData): boolean {
  const parts = fold(slug).split(/[^\p{L}\p{N}]+/u).filter(Boolean)
  const keywords = contentWords(keyphrase, locale, language)
  const present = keywords.filter((keyword) => parts.some((part) => language.matches(part, keyword))).length

  return keywords.length > 0 && (keywords.length > SLUG_SHORT_WORDS ? present * 2 > keywords.length : present === keywords.length)
}
