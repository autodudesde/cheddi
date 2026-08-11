<?php

declare(strict_types=1);

/*
 *
 * This file is part of the "cheddi" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *
 */

namespace AutoDudes\Cheddi\Service\Chat;

class HtmlTextExtractor
{
    /**
     * @var list<string>
     */
    private const DROP_ELEMENTS = ['script', 'style', 'noscript', 'svg', 'iframe', 'template'];

    /**
     * @var list<string>
     */
    private const CONTENT_ROOTS = ['main', 'article', 'body'];

    private const BLOCK_ELEMENTS = 'address|article|aside|blockquote|br|dd|div|dl|dt|fieldset|figcaption|'
        .'figure|footer|form|h1|h2|h3|h4|h5|h6|header|hr|li|main|nav|ol|p|pre|section|table|tbody|'
        .'td|tfoot|th|thead|tr|ul';

    public function extractTitle(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        $dom = $this->parse($html);
        if (null === $dom) {
            return '';
        }

        $title = $dom->getElementsByTagName('title')->item(0)?->textContent ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }

    public function extract(string $html, int $maxChars): string
    {
        $html = trim($html);
        if ('' === $html) {
            return '';
        }

        $text = $this->toText($this->cleanedMarkup($html));
        if ('' === $text) {
            return '';
        }

        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars)."\n\n[Truncated at {$maxChars} characters.]";
        }

        return $text;
    }

    private function parse(string $html): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Without the charset hint libxml assumes ISO-8859-1 and mangles every non-ASCII character.
        $loaded = $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom : null;
    }

    private function cleanedMarkup(string $html): string
    {
        $dom = $this->parse($html);
        if (null === $dom) {
            return $html;
        }

        $xpath = new \DOMXPath($dom);
        foreach (self::DROP_ELEMENTS as $tag) {
            /** @var \DOMNodeList<\DOMNode> $nodes */
            $nodes = $xpath->query('//'.$tag);
            foreach (iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (self::CONTENT_ROOTS as $tag) {
            $node = $dom->getElementsByTagName($tag)->item(0);
            if (null !== $node) {
                return (string) $dom->saveHTML($node);
            }
        }

        return (string) $dom->saveHTML();
    }

    private function toText(string $markup): string
    {
        $withBreaks = (string) preg_replace('#</?(?:'.self::BLOCK_ELEMENTS.')\b[^>]*>#i', "\n", $markup);
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
