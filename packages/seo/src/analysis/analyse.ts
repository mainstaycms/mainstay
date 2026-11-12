import { readabilityChecks } from './readability'
import { seoChecks } from './seo'
import type { Check, Page } from './types'

/* Every check of a page: the SEO ones, then readability. Pure: the page in, the verdicts out. */
export function analyse(page: Page): Check[] {
  return [...seoChecks(page), ...readabilityChecks(page, page.locale)]
}

export type { Check, Content, Group, Heading, Link, Page, Status } from './types'
export { fromDocument, emptyContent } from './extract'
export { titleWidth } from './snippet'
