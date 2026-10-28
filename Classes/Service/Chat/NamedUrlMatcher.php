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

class NamedUrlMatcher
{
    private const URL_PATTERN = '~(?:https?://)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}(?::\d{1,5})?(?:[/?][^\s<>"\'`]*)?~iu';

    /**
     * @param list<string> $editorTexts
     */
    public function isNamed(string $url, array $editorTexts): bool
    {
        $wanted = $this->normalize($url, true);
        if (null === $wanted) {
            return false;
        }

        foreach ($editorTexts as $text) {
            if (false === preg_match_all(self::URL_PATTERN, $text, $matches)) {
                continue;
            }
            foreach ($matches[0] as $candidate) {
                if ($wanted === $this->normalize(rtrim($candidate, '.,;:!?)]}'), false)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(string $url, bool $requireScheme): ?string
    {
        $url = trim($url);
        if (1 !== preg_match('~^https?://~i', $url)) {
            if ($requireScheme) {
                return null;
            }
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $host.$port.$path.$query;
    }
}
