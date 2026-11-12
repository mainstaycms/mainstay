import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { expect, test } from 'vitest'
import { omitSharedRules } from './omitSharedRules'

const dist = (path: string) => readFileSync(join(import.meta.dirname, '../../..', path), 'utf8')

test('a rule the admin has is dropped, with the media query and layer it leaves empty', () => {
  const admin = '@layer utilities{.flex-col{flex-direction:column}@media (min-width:48rem){.md\\:flex-row{flex-direction:row}}}'
  const plugin = '@layer utilities{.flex-col{flex-direction:column}.hidden{display:none}@media (min-width:48rem){.md\\:flex-row{flex-direction:row}}}'

  expect(omitSharedRules(plugin, admin)).toBe('@layer utilities{.hidden{display:none}}')
})

test('the same selector with other declarations, or in another media query, stays', () => {
  const admin = '.a{color:red}@media (min-width:48rem){.b{color:red}}'
  const plugin = '.a{color:blue}@media (min-width:64rem){.b{color:red}}'

  expect(omitSharedRules(plugin, admin)).toBe(plugin)
})

test('a theme variable written with a fallback is the same rule as without', () => {
  expect(omitSharedRules('.px-3{padding-inline:calc(var(--spacing,.25rem) * 3)}', '.px-3{padding-inline:calc(var(--spacing) * 3)}')).toBe('')
})

test('the same rule in another layer stays: it is not the same rule', () => {
  const admin = '@layer utilities{.a{color:red}}'
  const plugin = '@layer components{.a{color:red}}'

  expect(omitSharedRules(plugin, admin)).toBe(plugin)
})

test('a comment inside a rule, or an escaped quote, is refused rather than misread', () => {
  expect(() => omitSharedRules('.a{color:red/* } */}', '')).toThrow('comment inside a rule')
  expect(() => omitSharedRules('.a{content:"\\""}', '')).toThrow('escaped quote')
})

test('the banner comment before the first rule is kept', () => {
  expect(omitSharedRules('/*! tailwindcss | MIT */.a{color:red}.b{color:blue}', '.a{color:red}')).toBe('/*! tailwindcss | MIT */.b{color:blue}')
})

test('a quoted brace does not end its rule', () => {
  expect(omitSharedRules('.a{content:"}"}.b{color:red}', '.b{color:red}')).toBe('.a{content:"}"}')
})

// The regression this exists for: a utility both sheets carry, loaded later,
// beat the admin's responsive variant of it on the admin's own elements.
test.each(['chat/dist/chat.css', 'page-builder/dist/page-builder.css'])('%s repeats no rule of the admin stylesheet', (path) => {
  const admin = dist('cms/dist/mainstay.css')
  const plugin = dist(path)

  expect(omitSharedRules(plugin, admin)).toBe(plugin)
})
