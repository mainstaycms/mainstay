import type { Plugin } from 'vite'

type Node = { head: string; body: Node[] | string | null }

// Minified CSS as a tree: an at-rule or style rule is a head and a body, a
// body with no braces inside is a leaf of declarations, and `@layer a, b;` is
// a head with no body. It reads Tailwind's minified output, which has no
// comment inside a rule and no escaped quote, so it stops loudly on either
// rather than misreading where a rule ends.
function parse(css: string): Node[] {
  const nodes: Node[] = []
  let start = 0
  let depth = 0
  let quote = ''
  let bodyStart = 0

  for (let i = 0; i < css.length; i++) {
    const char = css[i]!

    if (char === '\\' && (css[i + 1] === '"' || css[i + 1] === "'")) {
      throw new Error(`omitRulesOf cannot read an escaped quote: ${css.slice(Math.max(0, i - 40), i + 10)}`)
    }

    if (quote === '' && char === '/' && css[i + 1] === '*') {
      if (depth > 0) {
        throw new Error(`omitRulesOf cannot read a comment inside a rule: ${css.slice(i, i + 40)}`)
      }

      const end = css.indexOf('*/', i) + 2
      nodes.push({ head: css.slice(i, end), body: null })
      start = end
      i = end - 1
    } else if (quote !== '') {
      quote = char === quote ? '' : quote
    } else if (char === '"' || char === "'") {
      quote = char
    } else if (char === '{' && depth++ === 0) {
      bodyStart = i + 1
    } else if (char === '}' && --depth === 0) {
      const inner = css.slice(bodyStart, i)
      nodes.push({ head: css.slice(start, bodyStart - 1), body: inner.includes('{') ? parse(inner) : inner })
      start = i + 1
    } else if (char === ';' && depth === 0) {
      nodes.push({ head: css.slice(start, i + 1), body: null })
      start = i + 1
    }
  }

  return nodes
}

function print(nodes: Node[]): string {
  return nodes.map(({ head, body }) => (body === null ? head : `${head}{${typeof body === 'string' ? body : print(body)}}`)).join('')
}

// A plugin only `@reference`s the theme, so Tailwind writes its theme
// variables with a fallback, `var(--spacing,.25rem)`, where the admin's own
// stylesheet, which defines them, writes `var(--spacing)`. Both mean the same.
function key(path: string, head: string, body: string): string {
  return `${path}${head}{${body.replace(/var\((--[\w-]+),[^()]*\)/g, 'var($1)')}}`
}

function keysOf(nodes: Node[], path = ''): Set<string> {
  return new Set(nodes.flatMap(({ head, body }) => (typeof body === 'string' ? [key(path, head, body)] : Array.isArray(body) ? [...keysOf(body, `${path}${head}{`)] : [])))
}

function without(nodes: Node[], shared: Set<string>, path = ''): Node[] {
  return nodes
    .map((node) => (Array.isArray(node.body) ? { ...node, body: without(node.body, shared, `${path}${node.head}{`) } : node))
    .filter(({ head, body }) => !(typeof body === 'string' && shared.has(key(path, head, body))) && !(Array.isArray(body) && body.length === 0))
}

// The CSS left once every rule that `sharedCss` already has, in the same
// layer and the same media query, is taken out.
export function omitSharedRules(css: string, sharedCss: string): string {
  return print(without(parse(css), keysOf(parse(sharedCss))))
}

/*
 | A plugin's stylesheet loads after the admin's, and Tailwind gives both the
 | same utilities. A rule the plugin repeats lands later in the cascade and
 | beats the admin's own responsive variant of it (`.flex-col` over
 | `md:flex-row`) on the admin's elements. The plugin keeps only the rules the
 | admin's stylesheet lacks, so the order of the two sheets decides nothing
 | but the plugin's own elements. Build the admin first: this reads its dist.
 */
export function omitRulesOf(sharedCss: URL): Plugin {
  // Decoded rather than taken from .pathname, which keeps a space as %20. node:url's
  // fileURLToPath would do it, but the plugin packages' typecheck has no node types.
  const sharedCssPath = decodeURIComponent(sharedCss.pathname)

  return {
    name: 'omit-shared-rules',
    enforce: 'post',
    buildStart() {
      this.addWatchFile(sharedCssPath)
    },
    async generateBundle(_, bundle) {
      const shared = await this.fs.readFile(sharedCssPath, { encoding: 'utf8' })

      for (const asset of Object.values(bundle)) {
        if (asset.type === 'asset' && asset.fileName.endsWith('.css')) {
          asset.source = omitSharedRules(String(asset.source), shared)
        }
      }
    },
  }
}
