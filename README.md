# MCP Server for Omeka S

A [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server for Omeka S. It
lets AI assistants such as Claude search your items, read their metadata, look at images,
and count what's there, all without leaving the conversation.

The server runs inside Omeka as a module and adds one endpoint to your installation:

```
https://example.org/mcp
```

- **Read-only.** Assistants can't create, change, or delete anything.
- **Public content, unless you add a key.** Assistants see what a visitor to your public
  site sees. With an Omeka API key in the address, they see what that key's user may see.
- **No setup for users.** Add the address to an assistant and start asking.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Connecting an assistant](#connecting-an-assistant)
- [Seeing private content](#seeing-private-content)
- [Tools](#tools)
- [Example prompts](#example-prompts)
- [Privacy and security](#privacy-and-security)
- [Limits](#limits)
- [Troubleshooting](#troubleshooting)
- [Protocol support](#protocol-support)
- [Adding tools from other modules](#adding-tools-from-other-modules)
- [Why the protocol is built in](#why-the-protocol-is-built-in)
- [License](#license)

## Requirements

- Omeka S 4.2.1 or later.
- For assistants that connect from the cloud, such as Claude on the web or desktop, your
  Omeka installation must be reachable from the internet. An installation on `localhost`
  works only with assistants running on the same computer, such as Claude Code.

## Installation

1. Copy the module into Omeka's `modules` directory, in a folder named `McpServer`.
2. In the Omeka admin, go to **Modules** and click **Install** next to MCP Server.

There's nothing to configure. The endpoint is `/mcp` under your Omeka base URL. Use that
address exactly, with no slash at the end.

## Connecting an assistant

Add the server to your assistant as a remote MCP server, using your endpoint's address,
such as `https://example.org/mcp`. It uses Streamable HTTP and needs no sign-in. Each
assistant explains where to add one:

- [Claude](https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp),
  on the web or desktop, as a custom connector
- [Claude Code](https://code.claude.com/docs/en/mcp)
- [VS Code](https://code.visualstudio.com/docs/copilot/customization/mcp-servers)
- [Cursor](https://cursor.com/docs/context/mcp)

To check that the server responds, list its tools with the
[MCP Inspector](https://github.com/modelcontextprotocol/inspector):

```sh
npx @modelcontextprotocol/inspector --cli https://example.org/mcp --transport http --method tools/list
```

## Seeing private content

Without a key, assistants see public content only. To let an assistant see private
content too, add an Omeka API key to the address, the same way Omeka's REST API takes one:

```
https://example.org/mcp?key_identity=IDENTITY&key_credential=CREDENTIAL
```

Create the key in the Omeka admin, on the user's edit page under **API keys**. Omeka shows
the credential only once, when the key is created. The assistant then sees what that user
may see:

- **Reviewers, Editors, Supervisors, and Global Administrators** see all private content.
- **Authors and Researchers** see public content and what they own.

Full records carry Omeka's own flags, as its JSON-LD does: `"is_public": false` on a
private item, media, item set, or value. Every tool still only reads.

A key in the address is a password that anyone who sees the address can use:

- **It appears wherever the address does:** in your web server's access logs, in any
  proxy in front of it, and in the assistant's connector settings.
- **Use a dedicated account** with only the role it needs, not a personal or
  administrator account. Where a connector is shared, as on some assistants' team plans,
  everyone using it shares its key.
- **To revoke access, delete the key** in Omeka. A request with a key that no longer works
  is refused, rather than answered as if it had none.

## Tools

| Tool | What it does |
|---|---|
| `get_overview` | Describes the installation: counts, dates, and what search can filter on |
| `search_items` | Finds items by words, filters, and conditions on fields |
| `get_item` | Reads one item in full |
| `count_values` | Counts a field's values, most common first or alphabetically, such as creators, subjects, or image rights |
| `search_media` | Finds media by words, type, item, and conditions on fields |
| `get_media` | Reads one media, with its image for the assistant to look at |
| `search_item_sets` | Finds item sets by words and conditions on fields |
| `get_item_set` | Reads one item set in full, with its item count |

Assistants usually start with `get_overview`, then search or count, then read the items
they need. All tools are read-only.

### get_overview

Describes the installation. Returns:

- how many items there are, how many have media, and how many media there are
- when an item was last added, and last modified
- the media types in use, with how many media and items have each
- the item sets, with their IDs and item counts
- the classes items use, and the properties in use
- the sites, with their summaries

Each list shows at most 200 entries, with its full length when it has more. To read all
of one, a page at a time:

| Parameter | Type | Description |
|---|---|---|
| `list` | string | Return only this list: `item_sets`, `media_types`, `classes`, `properties`, or `sites`. |
| `page` | integer | With `list`, the page of entries, 200 to a page, starting at 1. |

### search_items

Finds items, a page at a time, as short summaries with a link to each item's public page.

| Parameter | Type | Description |
|---|---|---|
| `query` | string | Words to search for, ranked by relevance. Matches whole words only. |
| `contains` | string | Text that must appear somewhere in an item's values, even inside a word. |
| `item_set` | integer[] | Only items in any of these item sets, by ID. At most 100. |
| `class` | string | Only items of this class, by term (`dctype:Text`) or label (`Text`). |
| `site` | string | Only items in this site, by slug. |
| `has_media` | boolean | Only items with media (`true`) or without (`false`). |
| `where` | object[] | Conditions on fields, all of which must hold. At most 10. See below. |
| `sort` | string | `relevance`, `added`, `modified`, `title`, or a property term such as `dcterms:date`. |
| `order` | string | `asc` or `desc`. |
| `page` | integer | Page number, starting at 1. |
| `per_page` | integer | Results per page, from 1 to 50. Default 10. |

Each `where` condition has a `field` (a property term), an `op`, and usually a `value`:

| `op` | Matches results whose field… |
|---|---|
| `equals` | is exactly the value |
| `contains` | contains the value |
| `starts_with` | starts with the value. Dates usually start with the year, so `17` finds the 1700s. |
| `ends_with` | ends with the value |
| `links_to` | links to the resource (an item, media, or item set) with this ID |
| `exists` | has any value (no `value` needed) |
| `missing` | has no value (no `value` needed) |

### get_item

Reads one item in full: every value with its label and any annotations, its media, its
item sets, a link to its public page, and in `module_data`, anything other modules add,
such as map locations.

A field with more than 100 values shows the first 100 and its total, and `field` reads
all of them, a page at a time. A value longer than 2,000 characters, such as a long
transcription, is cut, and `field` with `offset` reads the rest of it, 10,000 characters
at a time.

| Parameter | Type | Description |
|---|---|---|
| `id` | integer | The item's ID. Required. |
| `response_format` | string | `concise` (the default) for readable values with labels, or `detailed` for Omeka's own JSON-LD, which also lists everything that links to this one. |
| `field` | string | Return only this property's values, by term, such as `dcterms:subject`, 100 to a page. Not with `detailed`. |
| `page` | integer | With `field`, the page of values, starting at 1. |
| `offset` | integer | With `field`, read one value's text from this character, starting at 0. Not with `page`. |
| `position` | integer | With `offset`, which of the field's values to read, starting at 1. Default 1. |

### count_values

Counts the values of one field, with how many items have each, a page at a time: most
common first, or alphabetically, which puts variant spellings of the same value side by
side. Takes the same filters as `search_items`, so it can count within a subset, such as
the most common subjects among paintings. A value that links to another item counts by
that item's title.

It counts items by default. Media and item sets have metadata of their own, such as the
rights and credits recorded for each image, and `resource_type` counts theirs instead.

| Parameter | Type | Description |
|---|---|---|
| `field` | string | The property to count, such as `dcterms:subject`. Required. |
| `sort` | string | `count` (the default) for the most common first, or `value` for alphabetical order. |
| `page` | integer | Page number, starting at 1. |
| `per_page` | integer | Values per page, from 1 to 100. Default 20. |
| `resource_type` | string | `items` (the default), `media`, or `item_sets`. |
| `media_type` | string | When counting media, only media of this exact type, such as `image/jpeg`. |
| `query`, `contains`, `item_set`, `class`, `site`, `has_media`, `where` | | The same filters as `search_items`. `item_set` and `has_media` apply only to items. |

### search_media

Finds media, the files and other content attached to items, such as images, pages, and
PDFs. Media can have their own metadata, such as a page's description or transcription.
Results are short summaries with the item each media belongs to.

| Parameter | Type | Description |
|---|---|---|
| `media_type` | string | Only media of this exact type, such as `application/pdf`. |
| `item` | integer | Only media of this item, by ID, in the order they were added unless sorted otherwise. |
| `query`, `contains`, `class`, `site`, `where`, `sort`, `order` | | The same as in `search_items`. |
| `page` | integer | Page number, starting at 1. |
| `per_page` | integer | Results per page, from 1 to 50. Default 20. |

`item_set` and `has_media` are for items only. To search the media of an item set, find
its items with `search_items`, then list each item's media with `item`.

### get_media

Reads one media: its metadata, the item it belongs to, and links to its file. When the
media has a thumbnail, as images and usually PDFs and videos do, the result includes that
image, so the assistant can see what the media shows. An HTML media, which holds its text
rather than a file, includes that text as `content`, cut like a long value.

| Parameter | Type | Description |
|---|---|---|
| `id` | integer | The media's ID. Required. |
| `image` | boolean | Include the image. Default `true`. |
| `field`, `page`, `offset`, `position` | | The same as in `get_item`. `field` can also be `content`, to read an HTML media's text with `offset`. |

### search_item_sets

Finds item sets by their title, description, and other metadata. Results are short
summaries with each set's description and item count. `get_overview` already lists the
item sets, so this is for searching them by what they're about.

| Parameter | Type | Description |
|---|---|---|
| `query`, `contains`, `class`, `site`, `where`, `sort`, `order` | | The same as in `search_items`. |
| `page` | integer | Page number, starting at 1. |
| `per_page` | integer | Results per page, from 1 to 50. Default 20. |

### get_item_set

Reads one item set in full: its title, description, and other values, how many items it
has, a link to its public page, and anything other modules add. To list its items, use
`search_items` with `item_set`.

| Parameter | Type | Description |
|---|---|---|
| `id` | integer | The item set's ID. Required. |
| `response_format` | string | `concise` (the default), or `detailed` for Omeka's own JSON-LD. |
| `field`, `page`, `offset`, `position` | | The same as in `get_item`. |

## Example prompts

- "Give me an overview: what kinds of items are here, and how many?"
- "Find letters written in the 1700s and summarize what they're about."
- "Who are the most frequent creators, and what are the most common subjects?"
- "Look at the photographs of the harbor and describe what they show."
- "Which items are linked to the item about the town hall?"
- "Which items have PDF files?"
- "What is each of the item sets about, and which are the largest?"
- "What has been added or changed recently?"
- "Are there inconsistent spellings or rarely used fields in the metadata?"

## Privacy and security

- **Without a key, only public content is visible.** Every read goes through Omeka's own
  API, so Omeka's visibility rules apply unchanged. Private items, private media, and
  private values appear only to a request with an API key whose user may see them, and
  so do links to them.
- **Nothing can be changed.** All tools are read-only.
- **No sessions or cookies.** Requests don't start a session, and a browser's Omeka login
  never applies. Only an API key in the address signs a request in.
- **The endpoint is public,** like your public site and Omeka's REST API. Anyone who
  knows the address can use it. If that's a concern, rate-limit it at the web server, as
  you would any public endpoint.

Signing in through an assistant's own sign-in screen, so that each person uses their own
Omeka account without handling a key, is planned for a later version.

## Limits

These keep each answer within what assistants accept. When something is cut, the result
says so, and says how to read the rest, if there is a way.

- Values longer than 2,000 characters, such as long transcriptions, are cut, and `field`
  with `offset` reads the rest. Text cut inside an annotation or in `module_data` can't be
  read further.
- An item, media, or item set shows at most 100 values of one field, and `field` reads
  the rest. Other lists longer than 100 entries, such as the links from other items in the
  `detailed` format, are cut too, with their full length given.
- An item lists at most 50 of its media, and `search_media` with `item` lists them all.
- `get_overview` shows at most 200 entries of each list, and `list` reads the rest.
- `get_media` sends the large thumbnail (800 pixels by default), never the original file.
- `count_values` counts across at most 200,000 matching items, media, or item sets.
  Narrow larger sets with filters. Media can reach this much sooner than items, as when
  each page of a scanned book is its own media. Above 200,000 items, `get_overview`
  leaves out its media type counts.
- `count_values` shortens values longer than 300 characters and marks them as cut. To
  find what has one, search with a `starts_with` condition on its text.
- Value annotations, such as a date's "approximate", are shown with their values but
  can't be searched or counted. When a search or count finds nothing because of that, the
  result says so and says where to read them.
- Full-text search (`query`) matches whole words, so "letter" doesn't find "letters", and
  it ignores very common words. Use `contains` to match part of a word.

## Troubleshooting

**Claude can't connect.** Claude, on the web and in the desktop app, connects from
Anthropic's servers, so your installation must be reachable from the internet. A
`localhost` address won't work.

**The connection fails at an address ending in a slash.** Use `/mcp`, not `/mcp/`.
Omeka redirects `/mcp/` to `/mcp`, and not every client follows the redirect.

**Results have no links to public pages.** Assistants link only to pages on sites a
visitor can see. Assign the items and item sets to a public site, and their links appear.

**Searches miss items,** for example after importing directly into the database. Rebuild
the full-text index: on the admin **Settings** page, check **Index full-text search** and
save.

**An assistant with a key still sees only public content.** The key's user may not see
private content: an Author or a Researcher sees only what they own. Check the user's role.

**Requests with a key are refused.** The key was mistyped, deleted, or belongs to a
deactivated user. Create a new key and update the address. Some assistants show a
refused key as a sign-in or registration error, since they can't tell it apart from a
server that needs signing in.

**Assistants get links but no images.** `get_media` reads thumbnails from Omeka's
default local file storage. With other file storage, assistants get links to the files
instead.

**The connection stops working after an upgrade.** While Omeka needs a database
migration, `/mcp` redirects to the maintenance page like every other public page. Run
the migration in the admin.

## Protocol support

- **Transport:** Streamable HTTP. Requests are `POST /mcp` with a JSON body, and every
  response is a single JSON body. The server never opens an event stream and keeps no
  session.
- **Protocol versions:** 2025-06-18, 2025-11-25, and 2026-07-28.
- **Capabilities:** tools.
- **Testing:** the implementation is tested with the official MCP conformance suite,
  `@modelcontextprotocol/conformance`.

## Adding tools from other modules

Other Omeka modules can add their own tools. Implement
`McpServer\Mcp\Tool\ToolInterface`, whose docblocks give the rules, including how to name
tools and how to read data so that visibility stays correct. This module's own tools, in
`src/Mcp/Tool/Builtin/`, are working examples. Then register the tool under the
`mcp_server` key in your module's configuration:

```php
'mcp_server' => [
    'tools' => [
        'factories' => [
            'mymodule_search_things' => Service\SearchThingsFactory::class,
        ],
    ],
],
```

Names from other modules start with the module's name, as above. A tool's name should
never change once released, because clients remember permissions by name.

For counts, and to check whether a property or class is in use, use the
`McpServer\ResourceCounter` service, which keeps its queries within what the caller can
see. Read a tool's arguments with `ArgumentReader`, and throw a `ToolException` for an
error the assistant should see and can act on, such as a bad argument. Return results
with `ToolResult::json()`, and put any guidance for the assistant, such as why nothing
matched, in its note rather than in the data.

## Why the protocol is built in

The server speaks MCP with its own small implementation rather than a PHP MCP library.
For a server like this one, MCP over HTTP comes down to a handful of JSON-RPC methods, and
as of October 2026 none of the PHP libraries fit Omeka well:

- The official SDK, `mcp/sdk` (0.9), is still experimental and changes its API in every
  minor release. It needs about ten packages plus a PSR-7 implementation, which Omeka
  doesn't include, and packages bundled inside a module load ahead of Omeka's own copies.
- `logiscape/mcp-sdk-php`, used by TYPO3, requires a newer version of `psr/log` than the
  one Omeka's logging is built on.
- `laravel/mcp` and `symfony/mcp-bundle` require their own frameworks. The Symfony bundle
  also requires PHP 8.2 and a newer `symfony/console` than Omeka ships.
- The standalone SDKs' authentication assumes an outside identity provider, with no way
  to let anonymous requests through while signed-in requests see more. When this server
  adds OAuth sign-in, Omeka will be the identity provider: anonymous visitors will still
  see public content, and signing in will widen what a person sees.

WordPress made the same choice for its MCP adapter. The protocol code is kept apart from
the tools, in `src/Mcp/Protocol/`, with the controller handling only HTTP, and tools are
written against this module's own interface. A library could replace that layer later
without changing any tool.

## License

MCP Server is Copyright © 2026-present Corporation for Digital Scholarship, Vienna,
Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code under
the GNU General Public License, version 3 (GPLv3). The full text of this license
is given in the license file.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

All rights not expressly granted are reserved.
