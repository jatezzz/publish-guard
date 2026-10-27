# publish-guard

A pre-publish check for public repositories and static sites. It fails the build when what you are about to publish
could leak something you meant to keep out of it:

- **Names on a private list** (a former employer, a client under NDA, an internal codename) in any tracked file, any commit
  message, a branch name or a rendered page. Matching ignores case, accents and invisible characters, and decodes HTML
  entities and %-escapes.
- **Metadata in images**: EXIF (including GPS), XMP, IPTC, PNG text chunks and SVG metadata.
- **Commit identity**: authors outside an allow-list, and `Co-authored-by` trailers.
- **Outbound links** to hosts that aren't on an allow-list, plus an optional one-hop read of the pages you link to.
- **Copied phrasing**: 8-word phrases your pages share with sites you list privately.

It **never prints a term**. Findings name the entry by its line number, and locations that themselves contain a term are
redacted. That way the CI log of a public repository can't leak the list it is protecting.

Zero dependencies: PHP 8.2+ and git.

## Usage

```sh
# the list stays private: a file outside the repo, or a CI secret
php bin/publish-guard --terms-file ~/private/terms.txt \
  --authors you@users.noreply.github.com \
  --pages dist --allowed-hosts github.com,www.linkedin.com
```

Exit status: `0` clean, `1` findings, `2` usage or setup error (for example, a missing term list).

### Term list format

```text
# one entry per line
Acme Corp          phrase: case- and accent-insensitive, any whitespace between words
/proj-\d{3}/       PCRE pattern, case-insensitive
~Initech           "soft": fails in your own material, only warns on pages you link to
```

### Options

| Option | What it does |
|---|---|
| `--repo DIR` | Repository to check (default: current directory) |
| `--terms-file FILE` / `PUBLISH_GUARD_TERMS` | The private list |
| `--pages DIR` (repeatable) | Built HTML/XML/TXT to check as rendered pages |
| `--url URL` (repeatable) | A live page to fetch and check |
| `--allowed-hosts LIST` | External hosts your pages may link to |
| `--fetch-hosts LIST` + `--fetch` | Read linked pages on these hosts one hop deep |
| `--overlap FILE` | Report phrases shared with the URLs in FILE |
| `--authors LIST` / `PUBLISH_GUARD_AUTHORS` | Allowed commit authors; turns on the commit check |
| `--committers LIST` | Extra allowed committers (default `noreply@github.com`) |
| `--allow-missing-terms` | Run the other checks without a list |

### In CI (GitHub Actions)

```yaml
- uses: actions/checkout@v4
  with: { fetch-depth: 0 }          # the commit checks need the history
- run: php bin/publish-guard --authors "${{ vars.AUTHORS }}" --pages dist
  env:
    PUBLISH_GUARD_TERMS: ${{ secrets.PUBLISH_GUARD_TERMS }}
```

Run it **after** committing and before pushing: a term in a commit message fails the check too, and in a public repo a
pushed message can't be taken back quietly.

## Why it exists

I extracted this from the build of my own portfolio site. Before anything goes live there, the guard has to pass.
It catches the mistakes that are easy to make and expensive to undo in public: a client's name in a commit message, a
photo with GPS coordinates, a link to a page that names someone it shouldn't.

## Tests

```sh
php tests/run.php
```

Unit tests cover the matching, image parsing and redaction. End-to-end tests run the CLI against throwaway git repositories.

## How it's made

Written with Claude Code under my direction. I review every change, and nothing ships without passing the tests.

## License

MIT
