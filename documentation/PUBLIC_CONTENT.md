# Public website content files

The public site uses version-controlled Markdown for editorial content. This is a small
content layer, not a database CMS. Content changes are reviewed in Git, validated during
the frontend build, and released in `wellness-public.zip`.

## Where content lives

```text
Frontend/content/
  en/pages/       Complete English pages
  en/sections/    English sections embedded in another page
  fr/pages/       Matching Canadian French pages
  fr/sections/    Matching Canadian French sections
Frontend/public/content-assets/  Reviewed public images copied into the build
```

The relative filename is the stable content key. For example, the `/about` route renders
`pages/about.md` from the selected language. `sections/home-welcome.md` is embedded on the
home page. English and French must contain the same relative filenames; the build fails
when a counterpart is missing.

## File format

Every file begins with YAML metadata:

```markdown
---
title: About us
description: A concise search and sharing description.
status: published
---
# About our centre

Page content is written in Markdown.
```

`status` is either `draft` or `published`. Draft content is bundled only as source input
and is not rendered. A page requires exactly one `#` heading. An embedded section cannot
contain a `#` heading because its parent page owns the main heading.

Supported authoring includes headings, paragraphs, emphasis, lists, tables, blockquotes,
horizontal rules, links and images. Raw HTML is deliberately rejected. Links may use an
internal root path (`/book`), an on-page anchor, HTTPS, `mailto:`, or `tel:`. Executable or
data URL schemes are rejected/removed. Public images should use a root-relative reviewed
`/content-assets/` path and meaningful alternative text. The validator confirms that the
referenced file exists. A fuller governed media workflow is a later slice.

## Editing and publishing

1. Edit both language files locally or through GitHub's text editor.
2. Keep factual statements, professional claims, policies and translations reviewed by
   the clinic owner or appropriate professional.
3. Run `npm run validate:content` from `Frontend`.
4. Run `npm test` and `npm run test:build` before release.
5. Commit the content files and generated release package.
6. Deploy the contents of the new `wellness-public.zip` to the public document root.

Do not edit production copies directly through FTP. Those edits would not be represented
in Git and would be overwritten by the next deployment.

## What does not belong in Markdown

Prices, service durations, practitioner assignments, availability and booking eligibility
remain authoritative API/database data. Published service and practitioner directories
will combine an optional Markdown introduction with sanitized public API projections.
Personal information, client information, private addresses, credentials, tokens, secrets,
clinical content and unpublished operational notes never belong in this content tree.

## Adding a page or section

Add the matching English and French files, then reference their shared content key from a
public route or `ContentSection`. Public navigation changes remain code-reviewed so an
uploaded file cannot silently add a misleading or privileged route. Future resource
articles may use a validated manifest generated from reviewed Markdown metadata.
