/*
 | The model field of the Connectors screen and of the chat's settings: the
 | server draws a text input, the field that posts, and beside it a hidden
 | select of the models the provider lists. Here the select is shown and
 | what it picks is copied into the input, which comes back for "Custom...".
 | Without this script the input is the whole field.
 */

/* Not a model id, which has no space in it, so no model can be mistaken for it. */
const CUSTOM = ' custom'

const BLANK_NOTE = 'Choose a provider to pick a model.'

interface Listing {
  models: string[]
  note: string | null
  default: string | null
}

interface Picker {
  root: HTMLElement
  select: HTMLSelectElement
  input: HTMLInputElement
  note: HTMLElement
  hint: HTMLElement | null
  providers: HTMLSelectElement | null
  pinLine: HTMLElement | null
  pinProvider: string
  id: string
  load: AbortController | null
}

export function optionsFor(models: string[], saved: string, defaultLabel: string, customLabel: string): HTMLOptionElement[] {
  const ids = saved !== '' && !models.includes(saved) ? [saved, ...models] : models
  const entries: [string, string][] = [['', defaultLabel], ...ids.map((id): [string, string] => [id, id]), [CUSTOM, customLabel]]

  return entries.map(([value, label]) => {
    const option = document.createElement('option')
    option.value = value
    option.textContent = label
    return option
  })
}

/* The id is the field's label's target: whichever control is showing has it. */
function show(picker: Picker, control: 'list' | 'input'): void {
  const listing = control === 'list'
  picker.select.hidden = !listing
  picker.input.hidden = listing && picker.select.value !== CUSTOM
  const shown = listing ? picker.select : picker.input
  const other = shown === picker.select ? picker.input : picker.select
  other.removeAttribute('id')
  shown.id = picker.id
}

function selectCurrent(picker: Picker): void {
  const value = picker.input.value
  const known = Array.from(picker.select.options).some((option) => option.value === value && option.value !== CUSTOM)
  picker.select.value = known ? value : CUSTOM
}

function render(picker: Picker, listing: Listing, saved: string): void {
  picker.select.replaceChildren(...optionsFor(listing.models, saved, defaultLabel(listing.default), picker.root.dataset.customLabel ?? 'Custom...'))
  picker.note.textContent = listing.note ?? ''
  picker.input.placeholder = listing.default ?? 'Model id'
  rewriteHint(picker, listing.default)

  if (listing.models.length === 0) {
    show(picker, 'input')
    return
  }

  selectCurrent(picker)
  show(picker, 'list')
}

function defaultLabel(model: string | null): string {
  return model ? `Default: ${model}` : 'Default'
}

/* The page's hint was written for the provider saved; it is the new one's now. */
function rewriteHint(picker: Picker, model: string | null): void {
  const base = picker.root.dataset.hintBase

  if (picker.hint && base) {
    picker.hint.textContent = model ? `${base} Default: ${model}.` : base
  }
}

function setDisabled(picker: Picker, disabled: boolean): void {
  picker.select.disabled = disabled
  picker.input.disabled = disabled
}

/* A use left to the site's config with no provider to offer: nothing to pick from. */
function disableBlank(picker: Picker): void {
  picker.load?.abort()
  picker.input.value = ''
  const fallback = picker.root.dataset.blankDefault || null
  picker.select.replaceChildren(...optionsFor([], '', defaultLabel(fallback), picker.root.dataset.customLabel ?? 'Custom...'))
  picker.select.value = ''
  picker.input.placeholder = fallback ?? 'Model id'
  picker.note.textContent = BLANK_NOTE
  rewriteHint(picker, fallback)
  setDisabled(picker, true)
  show(picker, picker.select.hidden ? 'input' : 'list')
}

function showPin(picker: Picker, visible: boolean): void {
  if (picker.pinLine) {
    picker.pinLine.hidden = !visible
  }
}

/* The provider select follows the provider chosen as the provider's own: it is the one saved with the model. */
function providerChanged(picker: Picker): void {
  const chosen = picker.providers?.value ?? ''
  const pinned = chosen === '' ? picker.pinProvider : ''

  showPin(picker, pinned !== '')

  if (chosen !== '') {
    void load(picker, chosen)
  } else if (pinned !== '') {
    void load(picker, pinned)
  } else {
    disableBlank(picker)
  }
}

/* Picking a model for a use left to the site's config sets the provider the config names, as saving will. */
function modelPicked(picker: Picker): void {
  const providers = picker.providers

  if (!providers || providers.value !== '' || picker.pinProvider === '' || picker.input.value.trim() === '') {
    return
  }

  if (Array.from(providers.options).some((option) => option.value === picker.pinProvider)) {
    providers.value = picker.pinProvider
    showPin(picker, false)
  }
}

async function load(picker: Picker, provider: string): Promise<void> {
  picker.load?.abort()
  picker.input.value = ''
  setDisabled(picker, false)
  const controller = new AbortController()
  picker.load = controller
  picker.root.setAttribute('aria-busy', 'true')
  picker.note.textContent = 'Loading models...'

  try {
    const query = new URLSearchParams({ provider, use: picker.root.dataset.use ?? '' })
    const response = await fetch(`${picker.root.dataset.endpoint}?${query}`, { headers: { Accept: 'application/json' }, signal: controller.signal })

    if (!response.ok) {
      throw new Error(String(response.status))
    }

    render(picker, (await response.json()) as Listing, '')
  } catch {
    if (controller.signal.aborted) {
      return
    }

    picker.note.textContent = 'Could not fetch the list: type the model id.'
    rewriteHint(picker, null)
    show(picker, 'input')
  } finally {
    if (picker.load === controller) {
      picker.root.removeAttribute('aria-busy')
    }
  }
}

function mount(root: HTMLElement): void {
  const select = root.querySelector<HTMLSelectElement>('select[data-model-list]')
  const input = root.querySelector<HTMLInputElement>('input')
  const note = root.querySelector<HTMLElement>('[data-model-note]')

  if (!select || !input || !note) {
    return
  }

  const hint = root.dataset.hint ? document.getElementById(root.dataset.hint) : null
  const providers = root.dataset.providerSelect ? document.getElementById(root.dataset.providerSelect) : null
  const picker: Picker = { root, select, input, note, hint, providers: providers as HTMLSelectElement | null, pinLine: root.querySelector<HTMLElement>('[data-model-pin]'), pinProvider: root.dataset.pinProvider ?? '', id: input.id, load: null }

  select.addEventListener('change', () => {
    if (select.value === CUSTOM) {
      show(picker, 'list')
      input.focus()
      return
    }

    input.value = select.value
    show(picker, 'list')
    modelPicked(picker)
  })

  input.addEventListener('input', () => modelPicked(picker))

  providers?.addEventListener('change', () => providerChanged(picker))

  if (select.dataset.pickable === 'true') {
    selectCurrent(picker)
    show(picker, 'list')
  }
}

export function mountModelPickers(scope: ParentNode = document): void {
  scope.querySelectorAll<HTMLElement>('[data-model-picker]').forEach(mount)
}
