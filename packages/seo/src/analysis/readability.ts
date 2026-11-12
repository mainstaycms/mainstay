import { languageOf } from './languages'
import { sentences, words } from './text'
import {
  CONSECUTIVE_SENTENCES,
  EASE_GOOD,
  EASE_OK,
  LONG_SENTENCES_GOOD,
  LONG_SENTENCES_OK,
  LONG_SENTENCE_WORDS,
  PASSIVE_GOOD,
  PASSIVE_OK,
  READABILITY_MIN_WORDS,
  PARAGRAPH_WORDS_GOOD,
  PARAGRAPH_WORDS_OK,
  SECTION_WORDS_GOOD,
  SECTION_WORDS_OK,
  TRANSITION_GOOD,
  TRANSITION_MIN_WORDS,
  TRANSITION_OK,
} from './thresholds'
import type { Check, Content, LanguageData, Status } from './types'

const check = (id: string, status: Status, message: string): Check => ({ id, group: 'readability', status, message })

const percent = (part: number, whole: number): number => (whole === 0 ? 0 : (part * 100) / whole)

const plural = (count: number, noun: string): string => `${count} ${noun}${count === 1 ? '' : 's'}`

export function readabilityChecks(content: Content, locale: string): Check[] {
  const language = languageOf(locale)
  const split = content.paragraphs.map((paragraph) => sentences(paragraph, locale).map((sentence) => words(sentence, locale)))
  const sentenceWords = split.flat()
  const total = sentenceWords.reduce((sum, tokens) => sum + tokens.length, 0)

  if (total < READABILITY_MIN_WORDS) {
    return [check('readability-text', 'ok', `There are ${plural(total, 'word')}, too few to judge how readable the text is. At least ${READABILITY_MIN_WORDS} are needed.`)]
  }

  return [
    sentenceLength(sentenceWords),
    paragraphLength(split.map((paragraph) => paragraph.reduce((sum, tokens) => sum + tokens.length, 0))),
    subheadings(content, split, total),
    passiveVoice(sentenceWords, language),
    transitionWords(sentenceWords, total, language),
    consecutive(sentenceWords),
    reading(sentenceWords, total, language),
  ]
}

function sentenceLength(sentenceWords: string[][]): Check {
  const long = sentenceWords.filter((tokens) => tokens.length > LONG_SENTENCE_WORDS).length
  const share = percent(long, sentenceWords.length)
  const said = `${Math.round(share)}% of the sentences (${long} of ${sentenceWords.length}) have more than ${LONG_SENTENCE_WORDS} words`

  return share <= LONG_SENTENCES_GOOD
    ? check('sentence-length', 'good', `${said}, at most ${LONG_SENTENCES_GOOD}%.`)
    : check('sentence-length', share <= LONG_SENTENCES_OK ? 'ok' : 'bad', `${said}. Keep it to ${LONG_SENTENCES_GOOD}% or less.`)
}

function paragraphLength(lengths: number[]): Check {
  const longest = Math.max(0, ...lengths)
  const over = (limit: number) => lengths.filter((length) => length > limit).length
  const long = over(PARAGRAPH_WORDS_GOOD)

  return long === 0
    ? check('paragraph-length', 'good', `No paragraph has more than ${PARAGRAPH_WORDS_GOOD} words. The longest has ${longest}.`)
    : check('paragraph-length', longest > PARAGRAPH_WORDS_OK ? 'bad' : 'ok', `${plural(long, 'paragraph')} ${long === 1 ? 'has' : 'have'} more than ${PARAGRAPH_WORDS_GOOD} words, up to ${longest}. Shorten ${long === 1 ? 'it' : 'them'}.`)
}

/* The words between one heading and the next, the text before the first heading as one. A text of 300 words or fewer needs no heading. */
function subheadings(content: Content, split: string[][][], total: number): Check {
  const sections: number[] = [0]
  let paragraph = 0

  for (const kind of content.order) {
    if (kind === 'heading') {
      sections.push(0)
    } else {
      sections[sections.length - 1] = (sections[sections.length - 1] ?? 0) + (split[paragraph] ?? []).reduce((sum, tokens) => sum + tokens.length, 0)
      paragraph++
    }
  }

  if (content.headings.length === 0) {
    return total > SECTION_WORDS_GOOD
      ? check('subheading-distribution', 'bad', `The text has ${total} words and no subheading. Add one at least every ${SECTION_WORDS_GOOD} words.`)
      : check('subheading-distribution', 'good', `The text is ${SECTION_WORDS_GOOD} words or fewer, so it needs no subheadings.`)
  }

  const longest = Math.max(...sections)
  const long = sections.filter((section) => section >= SECTION_WORDS_GOOD).length

  return long === 0
    ? check('subheading-distribution', 'good', `Every part between subheadings has under ${SECTION_WORDS_GOOD} words.`)
    : check('subheading-distribution', longest > SECTION_WORDS_OK ? 'bad' : 'ok', `${long === 1 ? 'A part' : `${long} parts`} of the text, up to ${longest} words, ${long === 1 ? 'has' : 'have'} at least ${SECTION_WORDS_GOOD} words without a subheading. Add one.`)
}

function passiveVoice(sentenceWords: string[][], language: LanguageData): Check {
  const passive = sentenceWords.filter((tokens) =>
    tokens.some((token, index) => language.passiveAuxiliaries.has(token) && tokens.slice(index + 1, index + 1 + language.participleReach).some(language.isParticiple)),
  ).length
  const share = percent(passive, sentenceWords.length)
  const said = `${Math.round(share)}% of the sentences (${passive} of ${sentenceWords.length}) are passive`

  return share <= PASSIVE_GOOD
    ? check('passive-voice', 'good', `${said}, at most ${PASSIVE_GOOD}%.`)
    : check('passive-voice', share <= PASSIVE_OK ? 'ok' : 'bad', `${said}. Use the active voice, to keep it to ${PASSIVE_GOOD}% or less.`)
}

/* Only a text of more than 200 words is judged: a short one needs few. */
function transitionWords(sentenceWords: string[][], total: number, language: LanguageData): Check {
  const transitions = sentenceWords.filter((tokens) => {
    const padded = ` ${tokens.join(' ')} `

    return language.transitionWords.some((phrase) => padded.includes(` ${phrase} `))
  }).length
  const share = percent(transitions, sentenceWords.length)
  const said = `${Math.round(share)}% of the sentences (${transitions} of ${sentenceWords.length}) have a transition word`

  if (total <= TRANSITION_MIN_WORDS) {
    return check('transition-words', 'good', `The text is ${TRANSITION_MIN_WORDS} words or fewer, short enough to need few transition words (${transitions} of ${sentenceWords.length} sentences have one).`)
  }

  return share >= TRANSITION_GOOD
    ? check('transition-words', 'good', `${said}, at least ${TRANSITION_GOOD}%.`)
    : check('transition-words', share >= TRANSITION_OK ? 'ok' : 'bad', `${said}. Use more of them, to link ideas: ${TRANSITION_GOOD}% or more.`)
}

/* The longest run of sentences starting on the same word. */
function consecutive(sentenceWords: string[][]): Check {
  let longest = 0
  let run = 0
  let previous: string | undefined

  for (const tokens of sentenceWords) {
    const first = tokens[0]
    run = first !== undefined && first === previous ? run + 1 : 1
    previous = first
    longest = Math.max(longest, run)
  }

  return longest >= CONSECUTIVE_SENTENCES
    ? check('consecutive-sentences', 'bad', `${longest} sentences in a row start with the same word. Vary how they begin.`)
    : check('consecutive-sentences', 'good', 'No three sentences in a row start with the same word.')
}

function reading(sentenceWords: string[][], total: number, language: LanguageData): Check {
  const syllables = sentenceWords.flat().reduce((sum, word) => sum + language.syllables(word), 0)
  const ease = Math.round(language.readingEase(total / sentenceWords.length, syllables / total))
  const said = `The reading ease is ${ease}`

  return ease >= EASE_GOOD
    ? check('reading-ease', 'good', `${said}, ${EASE_GOOD} or more: easy to read.`)
    : check('reading-ease', ease >= EASE_OK ? 'ok' : 'bad', `${said}. Shorter sentences and simpler words bring it to ${EASE_GOOD} or more.`)
}
