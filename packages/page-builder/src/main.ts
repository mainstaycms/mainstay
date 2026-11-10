import { editors } from '@mainstay/editor'
import { mount } from '@mainstay/ui/behaviour'
import { canvases } from './canvas'
import './index.css'

/*
 | The Content tab, loaded after the admin's own script: a site block's
 | settings are given the design system's behaviour and the rich text editor
 | once the server draws them.
 */
canvases(document, (root) => {
  mount(root)
  editors(root)
})
