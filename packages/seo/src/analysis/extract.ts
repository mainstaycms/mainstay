import type { Content } from './types'

interface Node {
  type: string
  text?: string
  attrs?: { level?: number; href?: string }
  marks?: { type: string; attrs?: { href?: string } }[]
  content?: Node[]
}

export function emptyContent(): Content {
  return { headings: [], paragraphs: [], links: [], images: [], order: [] }
}

/*
 | The content a form holds, read from the rich text's ProseMirror document.
 | It has the text the editor has -- headings, paragraphs and links -- and not
 | what a site's blocks draw, which only the page itself says; that is what
 | the server's preview is for.
 */
export function fromDocument(json: string): Content {
  const content = emptyContent()

  try {
    walk(JSON.parse(json) as Node, content)
  } catch {
    return emptyContent()
  }

  return content
}

function walk(node: Node, into: Content): void {
  if (node.type === 'block') {
    return
  }

  if (node.type === 'heading' || node.type === 'paragraph') {
    const text = textOf(node, into)

    if (node.type === 'heading') {
      into.headings.push({ level: node.attrs?.level ?? 2, text })
      into.order.push('heading')
    } else if (text !== '') {
      into.paragraphs.push(text)
      into.order.push('paragraph')
    }

    return
  }

  node.content?.forEach((child) => walk(child, into))
}

/* The inline text, a hard break a space, collecting each link on the way. */
function textOf(node: Node, into: Content): string {
  const parts = (node.content ?? []).map((child) => {
    if (child.type === 'hard_break') {
      return ' '
    }

    const href = child.marks?.find((mark) => mark.type === 'link')?.attrs?.href

    if (href !== undefined && child.text !== undefined) {
      into.links.push({ href, text: child.text })
    }

    return child.text ?? ''
  })

  return parts.join('').replace(/\s+/g, ' ').trim()
}
