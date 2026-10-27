<?php

declare(strict_types=1);

/*
 * Zero-dependency test runner: php tests/run.php
 * Exit status 1 when any test fails.
 */

require dirname(__DIR__) . '/src/Guard.php';

use PublishGuard\Guard;

$failed = 0;
$passed = 0;

function test(string $name, callable $body): void
{
    global $failed, $passed;
    try {
        $body();
        $passed++;
        fwrite(STDOUT, "ok    {$name}\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDOUT, "FAIL  {$name}: {$error->getMessage()}\n");
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Runs the CLI on a throwaway repository and returns [exit code, output]. */
function run(string $repo, string $args, string $terms = ''): array
{
    $env = $terms === '' ? '' : 'PUBLISH_GUARD_TERMS=' . escapeshellarg($terms) . ' ';
    exec($env . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/publish-guard') . ' --repo ' . escapeshellarg($repo) . ' ' . $args . ' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

function repo(array $files, string $message = 'first', string $email = 'me@example.com'): string
{
    $dir = sys_get_temp_dir() . '/publish-guard-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    foreach ($files as $path => $content) {
        @mkdir(dirname($dir . '/' . $path), 0777, true);
        file_put_contents($dir . '/' . $path, $content);
    }
    $g = 'git -C ' . escapeshellarg($dir);
    exec("{$g} init -q -b main && {$g} add -A && {$g} -c user.name=Me -c user.email=" . escapeshellarg($email) . ' commit -q -m ' . escapeshellarg($message));

    return $dir;
}

// ---------- Unit ----------

test('normalize: case, accents, invisible characters, entities and %-escapes', function (): void {
    check(Guard::normalize("Acme\u{200B} Ñandú &amp; Co%20Ltd") === 'acme nandu & co ltd', Guard::normalize("Acme\u{200B} Ñandú &amp; Co%20Ltd"));
});

test('terms: phrases match across whitespace and accents; regex and soft entries parse', function (): void {
    $terms = Guard::parseTerms("# comment\nAcme Corp\n/secret-\\d+/\n~Ñandú Labs\n");
    check(count($terms) === 3, 'three entries');
    check(Guard::matches("built for ACME\n  corp", $terms) === [['line' => 2, 'soft' => false]], 'phrase across lines');
    check(Guard::matches('ref secret-42', $terms)[0]['line'] === 3, 'regex');
    check(Guard::matches('nandu labs', $terms) === [['line' => 4, 'soft' => true]], 'soft, accent-insensitive');
    check(Guard::matches('nothing here', $terms) === [], 'clean text');
});

test('terms: an invalid pattern is a setup error', function (): void {
    try {
        Guard::parseTerms("/([/\n");
        throw new RuntimeException('no exception');
    } catch (InvalidArgumentException $e) {
        check(str_contains($e->getMessage(), 'line 1'), $e->getMessage());
    }
});

test('safe: a location that contains a term is redacted', function (): void {
    $terms = Guard::parseTerms('acme');
    check(Guard::safe('docs/acme.md', $terms) === '[redacted: matches the term list]', 'redacted');
    check(Guard::safe('docs/about.md', $terms) === 'docs/about.md', 'kept');
});

test('images: PNG text chunk, JPEG EXIF and SVG metadata are found; clean files pass', function (): void {
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', str_repeat("\0", 13));
    check(Guard::imageMetadata($png . $chunk('IEND', ''), 'png') === null, 'clean PNG');
    check(Guard::imageMetadata($png . $chunk('tEXt', "Author\0me") . $chunk('IEND', ''), 'png') === 'PNG tEXt chunk', 'PNG text');
    $exif = "Exif\0\0" . str_repeat('x', 10);
    check(Guard::imageMetadata("\xFF\xD8\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . "\xFF\xDA", 'jpg') === 'EXIF', 'JPEG EXIF');
    check(Guard::imageMetadata("\xFF\xD8\xFF\xDB\x00\x04\x00\x00\xFF\xDA", 'jpeg') === null, 'clean JPEG');
    check(Guard::imageMetadata('<svg><metadata>x</metadata></svg>', 'svg') === 'SVG metadata', 'SVG');
});

test('links and shingles', function (): void {
    check(Guard::links('<a href="https://a.example/x/">a</a> https://b.example.') === ['https://a.example/x', 'https://b.example'], 'links');
    check(count(Guard::shingles(range(1, 10), 8)) === 3, 'three 8-word runs in 10 words');
});

// ---------- End to end ----------

test('cli: a clean repository passes', function (): void {
    [$code, $out] = run(repo(['README.md' => "hello\n"]), '--authors me@example.com', 'acme');
    check($code === 0, $out);
});

test('cli: a term in a file fails and the term is never printed', function (): void {
    [$code, $out] = run(repo(['notes.txt' => "made for Acme\n"]), '', "acme");
    check($code === 1 && str_contains($out, 'term #1 in notes.txt:1'), $out);
    check(!preg_match('/acme/i', $out), 'output leaks the term');
});

test('cli: a term in a commit message fails', function (): void {
    [$code, $out] = run(repo(['a.txt' => "x\n"], 'ship the Acme build'), '', 'acme');
    check($code === 1 && str_contains($out, 'message'), $out);
});

test('cli: co-author trailer and unknown author fail the commit check', function (): void {
    [$code, $out] = run(repo(['a.txt' => "x\n"], "feat\n\nCo-authored-by: Bot <bot@example.com>", 'other@example.com'), '--authors me@example.com --allow-missing-terms');
    check($code === 1 && str_contains($out, 'co-author') && str_contains($out, 'author address'), $out);
});

test('cli: a page linking to a host off the allow-list fails', function (): void {
    $repo = repo(['site/index.html' => '<a href="https://allowed.example/">ok</a><a href="https://elsewhere.example/">no</a>']);
    [$code, $out] = run($repo, '--allow-missing-terms --pages ' . escapeshellarg($repo . '/site') . ' --allowed-hosts allowed.example');
    check($code === 1 && str_contains($out, 'elsewhere.example'), $out);
});

test('cli: no term list is a setup error unless allowed', function (): void {
    [$code] = run(repo(['a.txt' => "x\n"]), '');
    check($code === 2, 'expected exit 2');
});

fwrite(STDOUT, "\n{$passed}/" . ($passed + $failed) . " passed\n");
exit($failed > 0 ? 1 : 0);
