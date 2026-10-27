<?php

declare(strict_types=1);

namespace PublishGuard;

/**
 * The checks behind bin/publish-guard. Pure functions where possible, so they can be tested without a repository.
 *
 * A term entry is one line of the private list:
 *     word or phrase    matched case- and accent-insensitively; spaces match any whitespace
 *     /pattern/         a PCRE pattern, matched case-insensitively
 *     ~entry            a hard failure in your own material; only a warning on pages you link to
 *     # comment
 * Findings name an entry by its line number and never print the term itself.
 */
final class Guard
{
    /**
     * @return list<array{line: int, regex: string, soft: bool}>
     * @throws \InvalidArgumentException when an entry is not a valid pattern
     */
    public static function parseTerms(string $raw): array
    {
        $terms = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $soft = str_starts_with($line, '~');
            if ($soft) {
                $line = ltrim(substr($line, 1));
            }
            if (strlen($line) > 2 && $line[0] === '/' && str_ends_with($line, '/')) {
                $regex = '/' . substr($line, 1, -1) . '/iu';
            } else {
                $parts = preg_split('/\s+/', self::normalize($line)) ?: [];
                $regex = '/' . implode('\s+', array_map(static fn (string $p): string => preg_quote($p, '/'), $parts)) . '/u';
            }
            if (@preg_match($regex, '') === false) {
                throw new \InvalidArgumentException('entry on line ' . ($index + 1) . ' is not a valid pattern');
            }
            $terms[] = ['line' => $index + 1, 'regex' => $regex, 'soft' => $soft];
        }

        return $terms;
    }

    /** Lowercase, strip accents and invisible characters, decode HTML entities and %-escapes. */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = rawurldecode($text);
        $text = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text);
        if (class_exists(\Normalizer::class)) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_KD);
            $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
        } else {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            $text = $ascii === false ? $text : $ascii;
        }

        return mb_strtolower($text, 'UTF-8');
    }

    /** @return list<array{line: int, soft: bool}> the entries found in $text */
    public static function matches(string $text, array $terms): array
    {
        $found = [];
        $plain = self::normalize($text);
        foreach ($terms as $term) {
            if (preg_match($term['regex'], $plain) === 1 || preg_match($term['regex'], $text) === 1) {
                $found[] = ['line' => $term['line'], 'soft' => $term['soft']];
            }
        }

        return $found;
    }

    /** A location is printed only when it contains no entry itself. */
    public static function safe(string $label, array $terms): string
    {
        return self::matches($label, $terms) === [] ? $label : '[redacted: matches the term list]';
    }

    /** Name of the first metadata block found in an image, or null when it carries none. */
    public static function imageMetadata(string $data, string $ext): ?string
    {
        switch (strtolower($ext)) {
            case 'png':
                for ($at = 8; $at + 8 <= strlen($data);) {
                    $length = unpack('N', substr($data, $at, 4))[1];
                    $type = substr($data, $at + 4, 4);
                    if (in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf'], true)) {
                        return "PNG {$type} chunk";
                    }
                    if ($type === 'IEND') {
                        return null;
                    }
                    $at += 12 + $length;
                }

                return null;
            case 'jpg':
            case 'jpeg':
                for ($at = 2; $at + 4 <= strlen($data) && $data[$at] === "\xFF";) {
                    $marker = ord($data[$at + 1]);
                    if ($marker === 0xD9 || $marker === 0xDA) {
                        return null; // end of image, or start of the pixel data
                    }
                    $length = unpack('n', substr($data, $at + 2, 2))[1];
                    $segment = substr($data, $at + 4, $length - 2);
                    if ($marker === 0xE1 && str_starts_with($segment, "Exif\0")) {
                        return 'EXIF';
                    }
                    if ($marker === 0xE1 && str_starts_with($segment, 'http://ns.adobe.com/xap/')) {
                        return 'XMP';
                    }
                    if ($marker === 0xED) {
                        return 'IPTC';
                    }
                    if ($marker === 0xFE) {
                        return 'JPEG comment';
                    }
                    $at += 2 + $length;
                }

                return null;
            case 'webp':
                for ($at = 12; $at + 8 <= strlen($data);) {
                    $type = substr($data, $at, 4);
                    $size = unpack('V', substr($data, $at + 4, 4))[1];
                    if ($type === 'EXIF' || $type === 'XMP ') {
                        return 'WebP ' . trim($type) . ' chunk';
                    }
                    $at += 8 + $size + ($size & 1);
                }

                return null;
            case 'svg':
                return preg_match('/<metadata\b|xmlns:(rdf|dc|cc)=/i', $data) ? 'SVG metadata' : null;
            default:
                return str_contains($data, "Exif\0\0") || str_contains($data, '<x:xmpmeta') ? 'EXIF or XMP' : null;
        }
    }

    /** @return list<string> absolute http(s) URLs in a page, entity-decoded */
    public static function links(string $html): array
    {
        preg_match_all('#https?://[^\s"\'<>)]+#i', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $found);

        return array_values(array_unique(array_map(static fn (string $u): string => rtrim($u, '/.,'), $found[0])));
    }

    /** @return list<string> visible text of an HTML page, as normalized words */
    public static function visibleWords(string $html): array
    {
        $html = (string) preg_replace('#<(script|style|noscript|svg)\b.*?</\1>#is', ' ', $html);

        return preg_split('/[^\p{L}\p{N}]+/u', self::normalize(strip_tags($html)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return array<string, true> every run of $size consecutive words */
    public static function shingles(array $words, int $size = 8): array
    {
        $set = [];
        for ($i = 0, $n = count($words) - $size; $i <= $n; $i++) {
            $set[implode(' ', array_slice($words, $i, $size))] = true;
        }

        return $set;
    }

    /** NUL-separated git log records, trimmed (git adds a newline after each one). */
    public static function records(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\0", $output)), static fn (string $r): bool => $r !== ''));
    }
}
