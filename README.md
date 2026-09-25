# markdown-for-agents

Serve a clean Markdown version of every page to AI agents, straight from your PHP app. When a request asks for `text/markdown`, or for the page URL with `.md` appended, the page your app renders is converted on the way out: YAML frontmatter from the page's own metadata, the main content as Markdown, and none of the navigation, scripts, forms or styling. Browsers keep getting HTML.

A self-hosted take on Cloudflare's [Markdown for Agents](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/), which runs at Cloudflare's edge on Pro plans and above. This one runs in your app, on any host, with no dependencies beyond PHP's DOM extension. Not affiliated with Cloudflare.

## Why

Agents read the web in tokens, and most of a web page is markup. Across 208 pages of three production sites we run, the HTML came to about 7.06 million tokens and the Markdown to about 0.65 million: **91% fewer**. Our own services page goes from about 19,800 tokens to about 3,100.

An agent that wants Markdown can say so in its `Accept` header. For tools that can't set headers, the [llms.txt](https://llmstxt.org/) convention adds `.md` URLs. This package answers both.

## Usage

```bash
composer config repositories.markdown-for-agents vcs https://github.com/digidomination/markdown-for-agents
composer require digidomination/markdown-for-agents:dev-main
```

Wrap your front controller:

```php
// public/index.php
use Digidomination\MarkdownForAgents\MarkdownForAgents;

require __DIR__ . '/../vendor/autoload.php';

(new MarkdownForAgents([
    'exclude_paths' => ['/admin', '/checkout'],
    'content_signal' => 'search=yes, ai-input=yes, ai-train=no',
]))->handle(fn () => (new App())->run());
```

Then:

```bash
curl -H 'Accept: text/markdown' https://example.com/pricing
curl https://example.com/pricing.md
```

Your app doesn't change. For a `.md` URL it sees the page's own URL, so routing, redirects and 404s behave exactly as they do for HTML.

## What an agent gets

```markdown
---
title: "Pricing – Example Ltd"
description: "Fixed prices, no hourly rates."
url: "https://example.com/pricing"
language: en
alternates:
  en: "https://example.com/pricing"
  de: "https://example.com/de/preise"
organization:
  name: Example Ltd
  telephone: "+49 30 1234567"
  email: "hello@example.com"
  address: Example Street 1, 10115 Berlin, DE
---

# Pricing

Plans start at €29 a month.

| Plan | Price |
| --- | --- |
| Basic | €29 |
| Pro | €59 |
```

The response carries:

| Header | Value |
|---|---|
| `Content-Type` | `text/markdown; charset=utf-8` |
| `Vary` | `Accept` (negotiated responses) |
| `X-Markdown-Tokens` | estimated tokens of the Markdown |
| `X-Original-Tokens` | estimated tokens of the HTML it replaced |
| `Content-Signal` | your `content_signal` option, unless the app sets one itself |
| `Link` | `<page URL>; rel="canonical"` on `.md` URLs, so search engines index the HTML page |

`ETag`, `Last-Modified` and `Content-Length` are removed: they described the HTML. Token counts are an estimate (characters / 4).

## What gets converted

The content root is the first of `main`, `[role=main]`, `article` and `body` that the page has (option `main`). The site header, navigation and footer normally sit outside `<main>`, so they never reach the output.

Inside the root, these are dropped: scripts and styles, forms and their controls, buttons, `nav`, dialogs, anything `hidden` or `aria-hidden="true"`, anything marked `data-md="skip"`, and whatever you list under `exclude`. Without a `<main>`, headers, footers and asides go too, unless they belong to an article.

What remains becomes CommonMark with GitHub tables: headings, paragraphs, nested lists, tables (with `colspan`), code blocks with their language, blockquotes, definition lists, `details`/`summary` (FAQ accordions), figures and captions. Links and images get absolute URLs, because the Markdown is read without the page around it. Images need alt text to be included (option `images`). A `role="img"` element speaks through its label, so a star rating becomes "5 of 5 stars". A card that is one big link puts its URL on the card's heading.

The frontmatter comes from the page's `<title>`, meta description, canonical URL, `lang`, hreflang alternates, `og:image`, author and article dates. The business behind the page (name, phone, email, address) comes from its JSON-LD. The footer that shows those to people is stripped, and "how do I reach them?" is a question agents get asked.

## HTML pages point at their Markdown

Every HTML page the wrapper serves gains:

- `Vary: Accept`
- `Link: </pricing.md>; rel="alternate"; type="text/markdown"`
- `<link rel="alternate" type="text/markdown" href="/pricing.md">` in the `<head>` (option `link_tag`)

Pages marked `noindex` are not advertised.

## Options

| Option | Default | |
|---|---|---|
| `negotiate` | `true` | Serve Markdown for `Accept: text/markdown` ranked at least as high as `text/html`. A wildcard never counts. |
| `suffix` | `true` | Serve Markdown at the page URL + `.md`; `index.html.md` after a trailing slash (`index.md` works too). |
| `advertise` | `true` | `Vary` and `Link` headers on HTML pages. |
| `link_tag` | `true` | Also put the `<link>` into the `<head>`. |
| `exclude_paths` | `[]` | Path prefixes that never get Markdown. `/admin` covers `/admin` and `/admin/…`, not `/administration`. |
| `content_signal` | `null` | [Content-Signal](https://contentsignals.org/) for Markdown responses, e.g. `search=yes, ai-input=yes, ai-train=no`. Nothing is sent unless you set it. |
| `main` | `['main', '[role=main]', 'article', 'body']` | Where the content is: the first selector that matches. |
| `exclude` | `[]` | Extra selectors to drop inside the content root, e.g. `.related-posts`. |
| `images` | `'alt'` | `'alt'` (only images with alt text), `'all'` or `'none'`. |
| `frontmatter` | `true` | YAML frontmatter from the page's metadata. |
| `organization` | `true` | Contact facts from the page's JSON-LD in the frontmatter. |
| `json_ld` | `false` | Append the page's JSON-LD as a fenced block. Off by default: on a schema-rich page it repeats the content, and on our services page it more than tripled the size. |
| `max_bytes` | `2097152` | Larger pages pass through as HTML. |
| `parser` | `'auto'` | PHP 8.4's HTML5 parser when present, libxml otherwise. Across our 208 test pages both produce byte-identical Markdown. |
| `log` | `null` | Path of a JSONL file with one line per Markdown response: time, path, mode, status, user agent, tokens. No IP addresses. |

Selectors cover tag, `#id`, `.class`, attribute tests (`[a]`, `=`, `~=`, `^=`, `$=`, `*=`), the descendant and `>` combinators, and comma lists.

## Compared with Cloudflare's Markdown for Agents

Going by [Cloudflare's documentation](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/):

| | Cloudflare | markdown-for-agents |
|---|---|---|
| Runs | at Cloudflare's edge | in your PHP app |
| Plans | Pro, Business, Enterprise | any host |
| Asked for with | `Accept: text/markdown` | `Accept: text/markdown`, or `.md` URLs |
| Frontmatter | title, description, image | also URL, language, alternates, author, dates, organization contact |
| JSON-LD | appended | on request (`json_ld`) |
| Token headers | `x-markdown-tokens`, `x-original-tokens` | the same two |
| Content-Signal | `ai-train=yes, search=yes, ai-input=yes` unless the origin sets one | only what you set |
| Size limit | 2 MB | 2 MB, adjustable |

## Caching

Markdown and HTML share a URL under content negotiation, and both carry `Vary: Accept`. Not every CDN keys its cache on `Vary`, though. If yours caches HTML pages, add a rule that bypasses the cache when the `Accept` header contains `text/markdown`. `.md` URLs are separate URLs and cache like any other.

## Command line

Preview a page the way an agent will see it:

```bash
vendor/bin/markdown-for-agents https://example.com/pricing --stats
vendor/bin/markdown-for-agents page.html --url=https://example.com/pricing --exclude=.related-posts
```

`--stats` prints sizes and token estimates to stderr.

## Requirements

- PHP 8.2+
- `ext-dom`, `ext-libxml`, `ext-mbstring`

No other dependencies. `composer test` runs the suite, including an end-to-end run through PHP's built-in server.

## Why this exists

Built for [digitaldomination.xyz](https://digitaldomination.xyz) and the sites we build and run, as part of making them easy for AI assistants to read, cite and recommend. Converting from the page we already render keeps one source of truth: what an agent reads is what a visitor sees. Open-sourced because it shouldn't take a CDN plan upgrade to be readable by agents.

## License

MIT. See [LICENSE](LICENSE).

## Maintainer

Built and maintained by [Digital Domination](https://digitaldomination.xyz). Issues and PRs welcome.
