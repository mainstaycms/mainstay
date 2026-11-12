/*
 | Every number the checks judge by.
 |
 | Each is marked as one of:
 |   VERIFIED: checked against Yoast's own scoring documents, yoastseo's
 |     `SCORING SEO.md` and `SCORING READABILITY.md` (packages/yoastseo/src/scoring/
 |     assessments in Yoast/wordpress-seo, trunk, read 2026-11-12).
 |   FROM MEMORY: Yoast's, as the author remembers them; not in those documents.
 |   MINE: not Yoast's at all.
 */

/* VERIFIED. Meta description, in characters: green above MIN and under MAX; orange at or under MIN (too short) and at or over MAX (cut off); red when empty. */
export const DESCRIPTION_SHORT = 120
export const DESCRIPTION_LONG = 157

/*
 | VERIFIED, in two places that differ. SEO title width at 20px Arial:
 |   - yoastseo@3.6.0's build/scoring/assessments/seo/PageTitleWidthAssessment.js
 |     (calculateScore, defaults minLength 400, maxLength 600): 1 to 400px is
 |     "too short" (orange) unless allowShortTitle, which defaults to false;
 |     401 to 600px is green; over 600px is red; none is red.
 |   - its `SCORING SEO.md` (same package, build/scoring/assessments) says green
 |     for 1 to 600px, which the code does not do. The code is followed.
 | What is measured: the same document says the assessment "checks the SEO title
 | as it appears in the snippet preview ... However, we exclude the separator and
 | the site title replacement variables from the calculation". So the page's own
 | title is measured, and not the site name or separator the template adds. The
 | code only receives a precomputed `titleWidth`, so the document is the one
 | source for this.
 */
export const TITLE_MAX_WIDTH = 600
export const TITLE_SHORT_WIDTH = 400

/* VERIFIED. Keyphrase length in words: green up to GOOD, orange up to OK, red above. */
export const KEYPHRASE_WORDS_GOOD = 4
export const KEYPHRASE_WORDS_OK = 8

/* VERIFIED. Density as a percentage of the words, for a text of DENSITY_LONG_TEXT words or more: red under MIN and over MAX. */
export const DENSITY_MIN = 0.5
export const DENSITY_MAX = 3
export const DENSITY_LONG_TEXT = 100

/* VERIFIED. Shorter texts are judged by count: from 51 words 1-2 uses are green and 3 or more red; under 51, 1 is green and 2 or more red. */
export const DENSITY_MEDIUM_TEXT = 51
export const DENSITY_MEDIUM_MAX_COUNT = 2
export const DENSITY_SHORT_MAX_COUNT = 1

/* VERIFIED. Text length in words: green from GOOD, orange from OK, red below. */
export const TEXT_LENGTH_GOOD = 300
export const TEXT_LENGTH_OK = 250

/* VERIFIED. The keyphrase in H2 and H3: green between MIN and MAX of them, red under or over; green for a text of SECTION_WORDS_GOOD words or fewer with none. */
export const SUBHEADING_SHARE_MIN = 0.3
export const SUBHEADING_SHARE_MAX = 0.75

/* VERIFIED. A keyphrase of more than SLUG_SHORT_WORDS content words needs more than half of them in the slug; a shorter one needs all. */
export const SLUG_SHORT_WORDS = 2

/* VERIFIED. The keyphrase in the description is green for 1-2 sentences holding it, red for more. */
export const DESCRIPTION_KEYPHRASE_MAX = 2

/* VERIFIED. Readability: the share of sentences over LONG_SENTENCE_WORDS words is green up to GOOD, orange up to OK, red above. */
export const LONG_SENTENCE_WORDS = 20
export const LONG_SENTENCES_GOOD = 25
export const LONG_SENTENCES_OK = 30

/* VERIFIED. Paragraph length in words: green up to GOOD, orange up to OK, red above. */
export const PARAGRAPH_WORDS_GOOD = 150
export const PARAGRAPH_WORDS_OK = 200

/* VERIFIED. Words between subheadings: green under GOOD, orange from GOOD to OK, red above; a text with no subheading and GOOD words or fewer is green. */
export const SECTION_WORDS_GOOD = 300
export const SECTION_WORDS_OK = 350

/* VERIFIED. Passive sentences as a percentage: green up to GOOD, orange up to OK, red above. */
export const PASSIVE_GOOD = 10
export const PASSIVE_OK = 15

/* VERIFIED. Sentences with a transition word: green from GOOD, orange from OK, red below; judged only in a text of more than TRANSITION_MIN_WORDS words. */
export const TRANSITION_GOOD = 30
export const TRANSITION_OK = 20
export const TRANSITION_MIN_WORDS = 200

/* VERIFIED. Red when this many sentences in a row start with the same word. */
export const CONSECUTIVE_SENTENCES = 3

/* FROM MEMORY, and not in the scoring documents read: Flesch reading ease, green from GOOD, orange from OK, red below. */
export const EASE_GOOD = 60
export const EASE_OK = 50

/* MINE. Under this many words the readability checks say there is too little to judge (Yoast's text presence is 50 characters). */
export const READABILITY_MIN_WORDS = 50

/* MINE. A Dutch keyphrase word shorter than this is matched as a whole word, not as part of a compound. */
export const COMPOUND_MIN_LENGTH = 4
