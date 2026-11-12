/*
 | The width a title takes in a search result. Search engines cut it by pixels,
 | not characters. This is a table of Arial's advance widths (per 1000 em,
 | from memory) at the 20px Yoast measures a title at, not canvas measureText:
 | a table answers the same in every browser and in a test, with no font loaded.
 | It is an approximation: kerning is ignored and a character outside the table
 | counts as an average one.
 */

const TITLE_FONT_SIZE = 20

const AVERAGE = 556

const ARIAL: Record<string, number> = {
  ' ': 278, '!': 278, '"': 355, '#': 556, $: 556, '%': 889, '&': 667, "'": 191, '(': 333, ')': 333, '*': 389, '+': 584, ',': 278, '-': 333, '.': 278, '/': 278,
  '0': 556, '1': 556, '2': 556, '3': 556, '4': 556, '5': 556, '6': 556, '7': 556, '8': 556, '9': 556,
  ':': 278, ';': 278, '<': 584, '=': 584, '>': 584, '?': 556, '@': 1015,
  A: 667, B: 667, C: 722, D: 722, E: 667, F: 611, G: 778, H: 722, I: 278, J: 500, K: 667, L: 556, M: 833, N: 722, O: 778, P: 667, Q: 778, R: 722, S: 667, T: 611, U: 722, V: 667, W: 944, X: 667, Y: 667, Z: 611,
  '[': 278, '\\': 278, ']': 278, '^': 469, _: 556, '`': 333,
  a: 556, b: 556, c: 500, d: 556, e: 556, f: 278, g: 556, h: 556, i: 222, j: 222, k: 500, l: 222, m: 833, n: 556, o: 556, p: 556, q: 556, r: 333, s: 500, t: 278, u: 556, v: 500, w: 722, x: 500, y: 500, z: 500,
  '{': 334, '|': 260, '}': 334, '~': 584,
}

/* Characters set full-width: ideographs, kana and hangul. */
const WIDE = /[ᄀ-ᇿ⺀-鿿가-힯＀-￯]/u

export function titleWidth(title: string): number {
  let units = 0

  for (const character of title) {
    units += ARIAL[character] ?? (WIDE.test(character) ? 1000 : AVERAGE)
  }

  return Math.round((units * TITLE_FONT_SIZE) / 1000)
}
