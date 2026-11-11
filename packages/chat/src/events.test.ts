import { expect, test } from 'vitest'
import { readEvents, type StreamEvent } from './events'

function streamOf(...chunks: string[]): ReadableStream<Uint8Array> {
  const encoder = new TextEncoder()

  return new ReadableStream({
    start(controller) {
      chunks.forEach((chunk) => controller.enqueue(encoder.encode(chunk)))
      controller.close()
    },
  })
}

async function collect(body: ReadableStream<Uint8Array>): Promise<StreamEvent[]> {
  const events: StreamEvent[] = []

  for await (const event of readEvents(body)) {
    events.push(event)
  }

  return events
}

test('reads events split across chunks and stops at DONE', async () => {
  const events = await collect(streamOf('data: {"type":"text_delta","delta":"He', 'llo"}\n\ndata: {"type":"stream_end"}\n\n', 'data: [DONE]\n\n', 'data: {"type":"late"}\n\n'))

  expect(events).toEqual([{ type: 'text_delta', delta: 'Hello' }, { type: 'stream_end' }])
})

test('ends when the body does, without DONE', async () => {
  expect(await collect(streamOf('data: {"type":"stream_end"}\n\n'))).toEqual([{ type: 'stream_end' }])
})
