import { expect, test, vi } from 'vitest'
import { Transcript } from './transcript'

function open() {
  const root = document.createElement('div')
  const decide = vi.fn()

  return { root, decide, transcript: new Transcript(root, decide) }
}

test('a call that needs approval shows what it writes and reports the click', () => {
  const { root, decide, transcript } = open()

  transcript.needsApproval('call_1', 'update-entry', { type: 'essay', id: 7, data: { title: '<b>New</b>' } })

  expect(root.textContent).toContain('essay #7')
  expect(root.textContent).toContain('"title": "<b>New</b>"')
  expect(root.querySelector('b')).toBeNull()
  expect(transcript.waiting).toBe(true)

  Array.from(root.querySelectorAll('button')).find((button) => button.textContent === 'Approve')!.click()
  expect(decide).toHaveBeenCalledWith('call_1', true)

  Array.from(root.querySelectorAll('button')).find((button) => button.textContent === 'Reject')!.click()
  expect(decide).toHaveBeenLastCalledWith('call_1', false)
})

test('a decided call has no buttons left and a result settles its row', () => {
  const { root, transcript } = open()
  transcript.needsApproval('call_1', 'trash-entry', { type: 'essay', id: 7 })

  transcript.decided('call_1', false)
  expect(transcript.waiting).toBe(false)
  expect(root.textContent).toContain('rejected')

  transcript.toolResult('call_1', 'Error: not allowed')
  expect(root.querySelector('summary')!.textContent).toContain('refused')
})

test('text streams into one paragraph until a tool call interrupts it', () => {
  const { root, transcript } = open()

  transcript.append('Hel')
  transcript.append('lo')
  transcript.toolCall('call_2', 'list-types', {})
  transcript.append('Done')

  expect(Array.from(root.querySelectorAll('p')).map((paragraph) => paragraph.textContent)).toEqual(['Hello', 'Done'])
})

test('an answer is rendered as markdown again on each delta, half a marker showing as typed', () => {
  const { root, transcript } = open()

  transcript.append('Some **bo')
  expect(root.textContent).toBe('Some **bo')
  expect(root.querySelector('strong')).toBeNull()

  transcript.append('ld** text\n\n```\nco')
  expect(root.querySelector('strong')!.textContent).toBe('bold')
  expect(root.querySelector('pre code')!.textContent).toBe('co')

  transcript.append('de\n```')
  expect(root.querySelector('pre code')!.textContent).toBe('code')
  expect(root.children).toHaveLength(1)
})

test('what a user wrote stays plain text', () => {
  const { root, transcript } = open()

  transcript.user('**not bold** <b>x</b>')

  expect(root.textContent).toBe('**not bold** <b>x</b>')
  expect(root.querySelector('strong, b')).toBeNull()
})

test('a long unbroken word in what a user wrote wraps instead of clipping', () => {
  const { root, transcript } = open()

  transcript.user('x'.repeat(500))

  expect(root.querySelector('p')!.classList.contains('break-words')).toBe(true)
})

test('a tool call ends the answer, and the next text renders on its own', () => {
  const { root, transcript } = open()

  transcript.append('**one**')
  transcript.toolCall('call_3', 'list-types', {})
  transcript.append('**two**')

  expect(Array.from(root.querySelectorAll('strong')).map((strong) => strong.textContent)).toEqual(['one', 'two'])
})

test('a hand-over is one line saying so, with no row to open', () => {
  const { root, transcript } = open()

  transcript.toolCall('call_3', 'hand-over', {})
  transcript.toolResult('call_3', 'Handed over. A stronger model continues this request from here: carry on with it.')

  expect(root.textContent).toBe('Handed over to the stronger model')
  expect(root.querySelector('details')).toBeNull()
  expect(root.querySelector('[data-hand-over]')).not.toBeNull()
})
