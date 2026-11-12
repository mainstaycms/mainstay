import { COMPOUND_MIN_LENGTH } from '../thresholds'
import type { LanguageData } from '../types'

/*
 | Dutch. As English: the lists and heuristics are the author's, from memory,
 | and were not checked against Yoast's.
 |
 | Compounds are matched on purpose by substring: Dutch writes "zonnepaneel"
 | where English writes "solar panel", so a keyphrase word of COMPOUND_MIN_LENGTH
 | letters or more counts as found inside a longer word ("paneel" in
 | "zonnepaneel"). It also finds "zeer" inside "zeereis"; that is the price.
 */

const stopWords = new Set(
  'aan af al als altijd andere ben bij daar dan dat de der deze die dit doch doen door dus een en er ge geen geweest haar had heb hebben heeft hem het hier hij hoe hun iemand iets ik in is ja je kan kon kunnen maar me meer men met mij mijn moet na naar nog nu of om omdat onder ons ook op over reeds te tegen toch toen tot u uit uw van veel voor want waren was wat we wel werd wezen wie wij wil worden zal ze zelf zich zij zijn zo zonder zou'.split(' '),
)

const transitionWords = [
  'aldus', 'bijvoorbeeld', 'bovendien', 'daarom', 'daardoor', 'daarna', 'daarnaast', 'dus', 'echter', 'evenals', 'eveneens', 'hierdoor', 'hoewel', 'immers', 'kortom', 'maar', 'namelijk', 'omdat', 'ook', 'tenslotte', 'terwijl', 'toch', 'tevens', 'vervolgens', 'voorts', 'want', 'zodat', 'bovendien', 'eerst', 'daarbij', 'dankzij', 'ondanks', 'tegelijkertijd', 'uiteindelijk', 'vooral', 'wel', 'zowel',
  'ten eerste', 'ten tweede', 'ten slotte', 'met andere woorden', 'aan de andere kant', 'in plaats van', 'in ieder geval', 'in feite', 'in tegenstelling tot', 'om die reden', 'op die manier', 'tot slot', 'met name', 'daarentegen',
]

/* A Dutch participle: ge- and -d/-t/-en ("gemaakt", "gedaan", "gebouwd"), or a
   prefixed verb that takes no ge- ("verkocht", "bekend"). Words like "gewoon" or
   "genoeg" fit it and are left in: a known miss of the heuristic. */
function isParticiple(word: string): boolean {
  return (word.length > 5 && /^ge.+(d|t|en)$/.test(word)) || (word.length > 6 && /^(ver|be|ont|her|er).+(d|t)$/.test(word))
}

/* A word's ending taken off for matching, so "panelen" and "paneel" meet. */
function stem(word: string): string {
  const base = word.length > 5 && word.endsWith('en') ? word.slice(0, -2) : word.length > 4 && word.endsWith('s') ? word.slice(0, -1) : word

  return base.replace(/(.)\1(?=[^aeiou]?$)/, '$1')
}

/* Vowel groups, a diaeresis ("coördinatie") starting a syllable of its own. */
function syllables(word: string): number {
  const groups = word.replace(/[ëïöü]/g, '-a').match(/[aeiouyáéíóúàèìòùâêîôû]+/g)?.length ?? 0

  return Math.max(1, groups)
}

export const nl: LanguageData = {
  stopWords,
  transitionWords,
  passiveAuxiliaries: new Set(['wordt', 'worden', 'werd', 'werden', 'geworden', 'word']),
  isParticiple,
  /* The participle ends the clause: "Het huis wordt in de zomer door hem gebouwd". */
  participleReach: 6,
  syllables,
  /* Douma's reading ease, Flesch's for Dutch. */
  readingEase: (wordsPerSentence, syllablesPerWord) => 206.835 - 0.93 * wordsPerSentence - 77 * syllablesPerWord,
  matches: (word, keyword) => {
    const [w, k] = [stem(word), stem(keyword)]

    return w === k || (k.length >= COMPOUND_MIN_LENGTH && w.includes(k))
  },
}
