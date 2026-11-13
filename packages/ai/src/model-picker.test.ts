import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mountModelPickers, optionsFor } from './model-picker'

const CUSTOM = ' custom'

function page(pickable: boolean, saved = '', pin = ''): void {
  document.body.innerHTML = `
    <select id="default-text"><option value="">Config</option><option value="openai">OpenAI</option><option value="">None</option></select>
    <label for="model-text">Model</label>
    <div data-model-picker data-custom-label="Custom..." data-blank-default="gpt-6" data-pin-provider="${pin}" data-hint-base="Use a model id." data-hint="model-text-hint" data-provider-select="default-text" data-endpoint="/admin/ai/models" data-use="text">
      <select id="model-text-list" hidden data-model-list data-pickable="${pickable}">
        <option value="">Default: gpt-6</option><option value="gpt-6-luna">gpt-6-luna</option><option value="${CUSTOM}">Custom...</option>
      </select>
      <input id="model-text" name="models[text]" value="${saved}">
      <p data-model-note></p>
      <p data-model-pin hidden>Saving a model also sets the provider to OpenAI for this use.</p>
    </div>
    <p id="model-text-hint">Use a model id. Default now: gpt-6, for the provider saved.</p>`
}

const picker = () => ({
  select: document.querySelector<HTMLSelectElement>('select[data-model-list]')!,
  input: document.querySelector<HTMLInputElement>('input')!,
  note: document.querySelector<HTMLElement>('[data-model-note]')!,
  provider: document.querySelector<HTMLSelectElement>('#default-text')!,
  pinLine: document.querySelector<HTMLElement>('[data-model-pin]')!,
})

describe('optionsFor', () => {
  it('puts the default first, Custom last, and a saved id the list lacks in between', () => {
    const options = optionsFor(['a', 'b'], 'mine', 'Default', 'Custom...')
    expect(options.map((option) => option.value)).toEqual(['', 'mine', 'a', 'b', CUSTOM])
  })

  it('does not repeat a saved id the list has', () => {
    expect(optionsFor(['a'], 'a', 'Default', 'Custom...').map((option) => option.value)).toEqual(['', 'a', CUSTOM])
  })
})

describe('the model picker', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('leaves the input alone when there is no list', () => {
    page(false, 'typed')
    mountModelPickers()
    const { select, input } = picker()
    expect(select.hidden).toBe(true)
    expect(input.hidden).toBe(false)
    expect(input.id).toBe('model-text')
  })

  describe('with a list', () => {
    beforeEach(() => {
      page(true, 'gpt-6-luna')
      mountModelPickers()
    })

    it('shows the list with the saved model picked, gives it the label\'s id and hides the input', () => {
      const { select, input } = picker()
      expect(select.hidden).toBe(false)
      expect(select.value).toBe('gpt-6-luna')
      expect(input.hidden).toBe(true)
      expect(select.id).toBe('model-text')
      expect(input.id).toBe('')
    })

    it('copies a pick into the input, which is what posts', () => {
      const { select, input } = picker()
      select.value = ''
      select.dispatchEvent(new Event('change'))
      expect(input.value).toBe('')
      select.value = 'gpt-6-luna'
      select.dispatchEvent(new Event('change'))
      expect(input.value).toBe('gpt-6-luna')
      expect(input.hidden).toBe(true)
    })

    it('brings the input back for Custom...', () => {
      const { select, input } = picker()
      select.value = CUSTOM
      select.dispatchEvent(new Event('change'))
      expect(input.hidden).toBe(false)
      expect(document.activeElement).toBe(input)
    })

    it('shows a saved custom id as Custom... when the list lacks it', () => {
      page(true, 'my-tune')
      mountModelPickers()
      const { select, input } = picker()
      expect(select.value).toBe(CUSTOM)
      expect(input.hidden).toBe(false)
      expect(input.value).toBe('my-tune')
    })

    it('fetches the new provider\'s models when the provider changes, and clears the old model', async () => {
      const fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ models: ['x-1', 'x-2'], note: null, default: 'x-1' }) })
      vi.stubGlobal('fetch', fetcher)
      const { select, input, provider, note } = picker()
      provider.value = 'openai'
      provider.dispatchEvent(new Event('change'))
      expect(note.textContent).toBe('Loading models...')
      await vi.waitFor(() => expect(select.options.length).toBe(4))
      expect(fetcher.mock.calls[0]?.[0]).toBe('/admin/ai/models?provider=openai&use=text')
      expect(input.value).toBe('')
      expect(Array.from(select.options).map((option) => option.textContent)).toEqual(['Default: x-1', 'x-1', 'x-2', 'Custom...'])
      expect(document.getElementById('model-text-hint')?.textContent).toBe('Use a model id. Default: x-1.')
      expect(note.textContent).toBe('')
      expect(select.hidden).toBe(false)
    })

    it('falls back to the input with a note when the fetch fails', async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
      const { select, input, provider, note } = picker()
      provider.value = 'openai'
      provider.dispatchEvent(new Event('change'))
      await vi.waitFor(() => expect(note.textContent).toBe('Could not fetch the list: type the model id.'))
      expect(select.hidden).toBe(true)
      expect(input.hidden).toBe(false)
    })

    it('shows the input and the provider\'s own note when it lists nothing', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ models: [], note: 'No key for X yet: type the model id.', default: null }) }))
      const { select, provider, note } = picker()
      provider.value = 'openai'
      provider.dispatchEvent(new Event('change'))
      await vi.waitFor(() => expect(note.textContent).toBe('No key for X yet: type the model id.'))
      expect(select.hidden).toBe(true)
    })

    it('asks nothing for a blank provider and disables both controls with the config default in the hint', () => {
      const fetcher = vi.fn()
      vi.stubGlobal('fetch', fetcher)
      const { provider, select, input, note } = picker()
      provider.selectedIndex = 0
      provider.dispatchEvent(new Event('change'))
      expect(fetcher).not.toHaveBeenCalled()
      expect(select.disabled).toBe(true)
      expect(input.disabled).toBe(true)
      expect(input.value).toBe('')
      expect(note.textContent).toBe('Choose a provider to pick a model.')
      expect(document.getElementById('model-text-hint')?.textContent).toBe('Use a model id. Default: gpt-6.')
    })

    it('enables both controls again once a provider is chosen', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ models: ['x-1'], note: null, default: null }) }))
      const { provider, select, input } = picker()
      provider.selectedIndex = 0
      provider.dispatchEvent(new Event('change'))
      provider.value = 'openai'
      provider.dispatchEvent(new Event('change'))
      expect(select.disabled).toBe(false)
      expect(input.disabled).toBe(false)
      await vi.waitFor(() => expect(select.options.length).toBe(3))
      expect(document.getElementById('model-text-hint')?.textContent).toBe('Use a model id.')
    })

    it('rewrites the hint to the plain one when the fetch fails', async () => {
      vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
      const { provider, note } = picker()
      provider.value = 'openai'
      provider.dispatchEvent(new Event('change'))
      await vi.waitFor(() => expect(note.textContent).toBe('Could not fetch the list: type the model id.'))
      expect(document.getElementById('model-text-hint')?.textContent).toBe('Use a model id.')
    })

    it('ignores an answer that arrives after a newer provider was chosen', async () => {
      let first: (value: unknown) => void = () => {}
      const fetcher = vi.fn()
        .mockReturnValueOnce(new Promise((resolve) => { first = resolve }))
        .mockResolvedValueOnce({ ok: true, json: async () => ({ models: ['new-1'], note: null, default: null }) })
      vi.stubGlobal('fetch', fetcher)
      const { select, provider } = picker()
      provider.value = 'openai'
      provider.dispatchEvent(new Event('change'))
      provider.dispatchEvent(new Event('change'))
      await vi.waitFor(() => expect(select.options[1]?.value).toBe('new-1'))
      first({ ok: true, json: async () => ({ models: ['old-1'], note: null, default: null }) })
      await Promise.resolve()
      expect(select.options[1]?.value).toBe('new-1')
    })
  })

  describe('left to the site\'s config with a provider to offer', () => {
    const blankWithPin = () => {
      page(true, '', 'openai')
      const { provider, pinLine } = picker()
      provider.selectedIndex = 0
      pinLine.hidden = false
      mountModelPickers()
    }

    it('fetches the config provider\'s models when the provider goes blank, enabled, with the pin line shown', async () => {
      const fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ models: ['c-1'], note: null, default: 'c-1' }) })
      vi.stubGlobal('fetch', fetcher)
      page(true, '', 'openai')
      mountModelPickers()
      const { provider, select, input, pinLine } = picker()
      provider.value = 'openai'
      provider.dispatchEvent(new Event('change'))
      await vi.waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1))
      pinLine.hidden = true
      provider.selectedIndex = 0
      provider.dispatchEvent(new Event('change'))
      expect(fetcher.mock.calls[1]?.[0]).toBe('/admin/ai/models?provider=openai&use=text')
      expect(select.disabled).toBe(false)
      expect(input.disabled).toBe(false)
      expect(pinLine.hidden).toBe(false)
    })

    it('switches the provider select to it when a model is picked, and hides the line', () => {
      blankWithPin()
      const { select, provider, pinLine } = picker()
      select.value = 'gpt-6-luna'
      select.dispatchEvent(new Event('change'))
      expect(provider.value).toBe('openai')
      expect(pinLine.hidden).toBe(true)
    })

    it('switches it when a model id is typed, and not for a blank one', () => {
      blankWithPin()
      const { input, provider } = picker()
      input.value = '  '
      input.dispatchEvent(new Event('input'))
      expect(provider.selectedIndex).toBe(0)
      input.value = 'my-tune'
      input.dispatchEvent(new Event('input'))
      expect(provider.value).toBe('openai')
    })

    it('does not switch for the default pick', () => {
      blankWithPin()
      const { select, provider } = picker()
      select.value = ''
      select.dispatchEvent(new Event('change'))
      expect(provider.selectedIndex).toBe(0)
    })
  })
})
