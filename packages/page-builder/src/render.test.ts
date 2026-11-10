import { contentSchema } from '@mainstay/editor'
import { Node } from 'prosemirror-model'
import { expect, test } from 'vitest'
import html from '../../cms/tests/Fixtures/document.html?raw'
import document from '../../cms/tests/Fixtures/document.json'
import { render } from './canvas'

// The HTML the PHP renderer draws from the fixture, which the Content tab
// draws too, both read back through the DOM: they escape differently, and
// mean the same.
test('draws the fixture as the PHP renderer does', () => {
  const read = (markup: string) => Object.assign(window.document.createElement('template'), { innerHTML: markup }).innerHTML
  const drawn = window.document.createElement('div')

  drawn.append(render(Node.fromJSON(contentSchema, document), new Map(), window.document))

  expect(drawn.innerHTML).toBe(read(html.trimEnd()))
})
