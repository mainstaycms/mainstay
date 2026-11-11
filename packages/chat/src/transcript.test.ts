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
