export type Status = 'good' | 'ok' | 'bad'

export type Group = 'seo' | 'readability'

export interface Check {
  id: string
  group: Group
  status: Status
  message: string
}

export interface Heading {
  level: number
  text: string
}

export interface Link {
  href: string
  text: string
}

/* What the checks read from a page, however it was got: the form's own
   content, or the page as a visitor sees it. */
export interface Content {
  headings: Heading[]
  paragraphs: string[]
  /* The headings and paragraphs in the order the page has them, for the checks of how text is divided. */
  order: ('heading' | 'paragraph')[]
  links: Link[]
  /* null: the <img> has no alt attribute at all. An empty one is decorative. */
  images: { alt: string | null }[]
}

export interface Page extends Content {
  locale: string
  /* The page's own title, the SEO title or else the entry's, without the site's template: its width is judged without the site name and separator. */
  title: string
  description: string
  slug: string
  keyphrase: string
  /* The host the page is served on, to tell an internal link from an outbound one. */
  host: string
}

export interface LanguageData {
  stopWords: ReadonlySet<string>
  transitionWords: readonly string[]
  passiveAuxiliaries: ReadonlySet<string>
  /* Whether a word after an auxiliary reads as a participle. */
  isParticiple: (word: string) => boolean
  /* How many words after an auxiliary a participle may stand. */
  participleReach: number
  syllables: (word: string) => number
  /* Reading ease on the language's own scale, 0 (hardest) to about 100. */
  readingEase: (wordsPerSentence: number, syllablesPerWord: number) => number
  /* A word of the text matched to one word of the keyphrase, both folded. */
  matches: (word: string, keyword: string) => boolean
}
