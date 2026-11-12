import type { LanguageData } from '../types'
import { en } from './en'
import { nl } from './nl'

const languages: Record<string, LanguageData> = { en, nl }

/* The data for a locale such as `nl` or `nl-BE`; English for one with none of its own. */
export function languageOf(locale: string): LanguageData {
  return languages[locale.toLowerCase().split(/[-_]/)[0] ?? ''] ?? en
}
