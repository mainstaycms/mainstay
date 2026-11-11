import { expect, test } from 'vitest'
import { commandCenters } from './command-center'

test('a command naming a dialog opens it instead of navigating', () => {
  document.body.innerHTML = `
    <div data-command-center>
      <input data-command-field>
      <ul data-command-list id="list"></ul>
      <p data-command-empty></p>
      <template data-command-option><li><span data-command-label></span><span data-command-group hidden></span></li></template>
      <script type="application/json" data-commands>[{"id":"open-chat","label":"Chat","group":"Open","dialog":"chat-dialog"}]</script>
    </div>
    <dialog id="chat-dialog"></dialog>`
  const dialog = document.querySelector('dialog')!
  dialog.showModal = function () {
    this.setAttribute('open', '')
  }
  /* jsdom has no layout to scroll. */
  Element.prototype.scrollIntoView = () => {}
  commandCenters()

  const field = document.querySelector<HTMLInputElement>('[data-command-field]')!
  field.dispatchEvent(new Event('focus'))
  field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', cancelable: true }))

  expect(dialog.open).toBe(true)
})
