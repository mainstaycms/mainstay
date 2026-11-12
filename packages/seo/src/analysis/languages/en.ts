import type { LanguageData } from '../types'

/*
 | English. Every list and heuristic here is the author's own, from memory of
 | what Yoast ships, and shorter than Yoast's; none was checked against it.
 */

const stopWords = new Set(
  'a about above after again against all am an and any are as at be because been before being below between both but by can did do does doing down during each few for from further had has have having he her here hers herself him himself his how i if in into is it its itself just me more most my myself no nor not now of off on once only or other our ours ourselves out over own same she should so some such than that the their theirs them themselves then there these they this those through to too under until up very was we were what when where which while who whom why will with would you your yours yourself yourselves'.split(' '),
)

const transitionWords = [
  'accordingly', 'additionally', 'afterward', 'afterwards', 'also', 'although', 'because', 'before', 'besides', 'but', 'consequently', 'conversely', 'finally', 'first', 'firstly', 'furthermore', 'hence', 'however', 'indeed', 'instead', 'likewise', 'meanwhile', 'moreover', 'nevertheless', 'next', 'nonetheless', 'otherwise', 'second', 'secondly', 'similarly', 'since', 'so', 'still', 'subsequently', 'then', 'therefore', 'third', 'thus', 'unless', 'until', 'whereas', 'while', 'yet',
  'as a result', 'for example', 'for instance', 'in addition', 'in conclusion', 'in contrast', 'in fact', 'in other words', 'in short', 'in summary', 'on the other hand', 'on the contrary', 'as well as', 'even though', 'after all', 'above all', 'at last', 'in the end', 'to sum up', 'for this reason', 'in particular', 'of course',
]

const irregularParticiples = new Set(
  'been begun bitten blown broken brought built bought caught chosen done drawn driven eaten fallen felt found forgotten given gone grown held hidden hit hurt kept known laid led left lost made met paid put read run said seen sent set shown shut sold spoken spent stolen taught taken told thought thrown understood won worn written'.split(' '),
)

export const en: LanguageData = {
  stopWords,
  transitionWords,
  passiveAuxiliaries: new Set(['am', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'get', 'gets', 'got', 'getting']),
  /* A regular participle ends in -ed; the irregular ones are listed. Words
     like "red" or "need" end in -ed and pass: a known miss of the heuristic. */
  isParticiple: (word) => irregularParticiples.has(word) || (word.length > 4 && word.endsWith('ed')),
  participleReach: 1,
  /* Vowel groups, less a silent final e ("make", not "table"). */
  syllables: (word) => {
    const groups = word.match(/[aeiouy]+/g)?.length ?? 0
    const silentE = /[^aeiouy]e$/.test(word) && !/[^aeiouy]le$/.test(word) ? 1 : 0

    return Math.max(1, groups - silentE)
  },
  /* Flesch reading ease. */
  readingEase: (wordsPerSentence, syllablesPerWord) => 206.835 - 1.015 * wordsPerSentence - 84.6 * syllablesPerWord,
  /* A plural or a possessive is the same word: "dogs" matches "dog". */
  matches: (word, keyword) => stem(word) === stem(keyword),
}

function stem(word: string): string {
  return word.length > 3 && word.endsWith('s') && !word.endsWith('ss') ? word.slice(0, -1) : word
}

