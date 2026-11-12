<?php

namespace Mainstay\Seo;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/*
 | What the content checks read from a page as a visitor gets it: its
 | headings, paragraphs, links and images, without the site's chrome. The
 | content is the page's <main>, else its <article>, else its <body>, less its
 | navigation, header, footer and asides.
 */
class PageText
{
    private const CHROME = ['nav', 'header', 'footer', 'aside', 'script', 'style', 'template', 'noscript'];

    /**
     * @return array{headings: list<array{level: int, text: string}>, paragraphs: list<string>, order: list<string>, links: list<array{href: string, text: string}>, images: list<array{alt: ?string}>}
     */
    public function from(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $root = $xpath->query('//main')->item(0) ?? $xpath->query('//article')->item(0) ?? $xpath->query('//body')->item(0);

        if ($root === null) {
            return ['headings' => [], 'paragraphs' => [], 'order' => [], 'links' => [], 'images' => []];
        }

        foreach (iterator_to_array($xpath->query('.//*['.implode(' or ', array_map(fn (string $tag) => "self::{$tag}", self::CHROME)).']', $root)) as $chrome) {
            $chrome->parentNode?->removeChild($chrome);
        }

        [$headings, $paragraphs, $order] = $this->flow($xpath, $root);

        return [
            'headings' => $headings,
            'paragraphs' => $paragraphs,
            'order' => $order,
            'links' => $this->each($xpath, './/a[@href]', $root, fn (DOMElement $node) => ['href' => $node->getAttribute('href'), 'text' => $this->text($node)]),
            'images' => $this->each($xpath, './/img', $root, fn (DOMElement $node) => ['alt' => $node->hasAttribute('alt') ? $node->getAttribute('alt') : null]),
        ];
    }

    /*
     | Headings and paragraphs in the order the page has them, which the checks
     | of how text is divided need: the parts between one heading and the next.
     |
     | @return array{0: list<array{level: int, text: string}>, 1: list<string>, 2: list<string>}
     */
    private function flow(DOMXPath $xpath, DOMNode $root): array
    {
        $headings = $paragraphs = $order = [];

        foreach ($xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::p]', $root) as $node) {
            $text = $this->text($node);

            if ($node->nodeName === 'p' && $text !== '') {
                $paragraphs[] = $text;
                $order[] = 'paragraph';
            } elseif ($node->nodeName !== 'p') {
                $headings[] = ['level' => (int) substr($node->nodeName, 1), 'text' => $text];
                $order[] = 'heading';
            }
        }

        return [$headings, $paragraphs, $order];
    }

    private function each(DOMXPath $xpath, string $query, DOMNode $root, callable $read): array
    {
        $found = [];

        foreach ($xpath->query($query, $root) as $node) {
            $found[] = $read($node);
        }

        return $found;
    }

    private function text(DOMNode $node): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
