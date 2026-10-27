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
  site sees. With an MCP key in the address, they see what that key's user may see, and
  you can require one.
- **Nothing for users to install.** Add the address to an assistant and start asking.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Connecting an assistant](#connecting-an-assistant)
- [Example prompts](#example-prompts)
- [MCP keys](#mcp-keys)
- [Tools](#tools)
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

The endpoint is `/mcp` under your Omeka base URL. **MCP Server > Overview** in the admin
shows its full address and the tools assistants can use. Use that address exactly, with
no slash at the end.

The module has one optional setting, **Require sign-in**, off by default. See
[Requiring a key](#requiring-a-key).

## Connecting an assistant

Add the server to your assistant as a remote MCP server, using your endpoint's address,
such as `https://example.org/mcp`. It uses Streamable HTTP, and needs no MCP key unless
you've turned on **Require sign-in**. Each assistant explains where to add one:

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

## Example prompts

Once an assistant is connected, ask in plain language. For example:

- "Give me an overview: what kinds of items are here, and how many?"
- "Find letters written in the 1700s and summarize what they're about."
- "I'm teaching a unit on the American Revolution. What primary sources are here, and in
  what order would you use them?"
- "Who are the most frequent creators, and what are the most common subjects?"
- "Look at the photographs of the harbor and describe what they show."
- "Which items are linked to the item about the town hall?"
- "Which items have PDF files?"
- "What is each of the item sets about, and which are the largest?"
- "What has been added or changed recently?"
- "Which fields do the records in this item set leave empty?"
- "Are there inconsistent spellings or rarely used fields in the metadata?"

## MCP keys

Without a key, assistants see public content only. To let an assistant see private
content too, add an MCP key to the address:

```
https://example.org/mcp?key=KEY
```

Create the key in the Omeka admin, under **MCP Server > Keys**. Omeka shows the address
with the key only once, when the key is created. Whoever may manage a user's API keys,
such as a Global Administrator, may manage their MCP keys there too.

The assistant sees what the key's user may see:

- **Reviewers, Editors, Supervisors, and Global Administrators** see all private content.
- **Authors and Researchers** see public content and what they own.

An MCP key works only at `/mcp`, whose tools only read, and never for Omeka's REST API.
So it can't be used to change anything, whatever its user's role. Omeka API keys don't
work at `/mcp`.

Treat an address with a key like a password. Anyone who sees it can read what its user
may see:

- **It appears wherever the address does:** in your web server's access logs, in any
  proxy in front of it, and in the assistant's connector settings.
- **Where a connector is shared,** as on some assistants' team plans, everyone using it
  shares its key. Use an account that sees only what all of them should see.
- **To revoke access, delete the key** under **MCP Server > Keys**, which also shows when
  each key was last used, to within five minutes. A request with a key that no longer
  works is refused, rather than answered as if it had none.

### Requiring a key

By default, anyone with the address can read your public content. To refuse assistants
without a key, check **Require sign-in** in the module's configuration: on the
**Modules** page, click **Configure** next to MCP Server. Only a Global Administrator can
change it. A request without a key is then refused, with a message saying where to create
one.

To give public-only access while sign-in is required, create keys for a Researcher
account that owns nothing; it sees only public content. Each key can then be revoked on
its own.

## Tools

| Tool | What it does |
|---|---|
| `get_overview` | Describes the installation: counts, dates, and what search can filter on |
| `search_items` | Finds items by words, filters, and conditions on properties |
| `get_item` | Reads one item in full |
| `count_values` | Counts a property's values, most common first or alphabetically, such as creators, subjects, or image rights |
| `count_properties` | Counts which properties records have filled in, such as for a metadata audit |
| `search_media` | Finds media by words, type, item, and conditions on properties |
| `get_media` | Reads one media, with its image for the assistant to look at |
| `search_item_sets` | Finds item sets by words and conditions on properties |
| `get_item_set` | Reads one item set in full, with its item count |

Assistants usually start with `get_overview`, then search or count, then read the items
they need. All tools are read-only. Like Omeka, the tools call a record's fields
properties, and name each one by its term, such as `dcterms:title`.

When an assistant may see private content, results mark it as Omeka's JSON-LD does:
`"is_public": false` on a private item, media, item set, or value. Search results show
values as plain text, so there a private value is `{"text": "…", "is_public": false}`,
a value with a language tag is `{"text": "…", "lang": "…"}`, and a value that links to
another record gives its `id`, as `{"text": "…", "id": 123}`. To find only private
items, media, or item sets, search or count with `is_public` set to `false`.

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
| `query` | string | Words to search for. Finds records with any of the words, most relevant first, and matches whole words only. |
| `contains` | string | Text that must appear somewhere in an item's values, even inside a word. |
| `item_set` | integer[] | Only items in any of these item sets, by ID. At most 100. |
| `class` | string | Only items of this class, by term (`dctype:Text`) or label (`Text`). |
| `site` | string | Only items in this site, by slug. |
| `is_public` | boolean | Only public items (`true`) or private ones (`false`). |
| `has_media` | boolean | Only items with media (`true`) or without (`false`). |
| `where` | object[] | Conditions on properties, all of which must hold. At most 10. See below. |
| `sort` | string | `relevance`, `added`, `modified`, `title`, or a property term such as `dcterms:date`. |
| `order` | string | `asc` or `desc`. |
| `page` | integer | Page number, starting at 1. |
| `per_page` | integer | Results per page, from 1 to 50. Default 10. |
| `properties` | string[] | Properties whose values each result should list, such as `dcterms:rights`. At most 10. See below. |

Each `where` condition has a `property` (by term), an `op`, and usually a `value`:

| `op` | Matches results whose property… |
|---|---|
| `equals` | is exactly the value |
| `contains` | contains the value |
| `starts_with` | starts with the value. Dates usually start with the year, so `17` finds the 1700s. |
| `ends_with` | ends with the value |
| `links_to` | links to the resource (an item, media, or item set) with this ID |
| `exists` | has any value (no `value` needed) |
| `missing` | has no value (no `value` needed) |

With `properties`, each result lists the values of the properties named in place of its
usual summary, beside its ID, title, and link: up to 10 values of each property, each
shortened to 300 characters. That reads a property across many results at once, instead
of opening each one.

### get_item

Reads one item in full: every value with its label and any annotations, its media, its
item sets, a link to its public page, and in `module_data`, anything other modules add,
such as map locations.

A property with more than 100 values shows the first 100 and its total, and `property` reads
all of them, a page at a time. A value longer than 2,000 characters, such as a long
transcription, is cut, and `property` with `offset` reads the rest of it, 10,000 characters
at a time.

| Parameter | Type | Description |
|---|---|---|
| `id` | integer | The item's ID. Required. |
| `response_format` | string | `concise` (the default) for readable values with labels, or `detailed` for Omeka's own JSON-LD, which also lists everything that links to this one. |
| `property` | string | Return only this property's values, by term, such as `dcterms:subject`, 100 to a page. Not with `detailed`. |
| `page` | integer | With `property`, the page of values, starting at 1. |
| `offset` | integer | With `property`, read one value's text from this character, starting at 0. Not with `page`. |
| `position` | integer | With `offset`, which of the property's values to read, starting at 1. Default 1. |

### count_values

Counts the values of one property, with how many items have each, a page at a time: most
common first, or alphabetically, which puts variant spellings of the same value side by
side. Takes the same filters as `search_items`, so it can count within a subset, such as
the most common subjects among paintings. A value that links to another item counts by
that item's title.

It counts items by default. Media and item sets have metadata of their own, such as the
rights and credits recorded for each image, and `resource_type` counts theirs instead.

| Parameter | Type | Description |
|---|---|---|
| `property` | string | The property to count, by term, such as `dcterms:subject`. Required. |
| `prefix_length` | integer | Count values by their first characters, from 1 to 50, rather than whole. Dates stored starting with the year count by century with 2, and by decade with 3. |
| `sort` | string | `count` (the default) for the most common first, or `value` for alphabetical order. |
| `page` | integer | Page number, starting at 1. |
| `per_page` | integer | Values per page, from 1 to 100. Default 20. |
| `resource_type` | string | `items` (the default), `media`, or `item_sets`. |
| `media_type` | string | When counting media, only media of this exact type, such as `image/jpeg`. |
| `query`, `contains`, `item_set`, `class`, `site`, `is_public`, `has_media`, `where` | | The same filters as `search_items`. `item_set` and `has_media` apply only to items. |

### count_properties

Counts which properties are filled in: for the items that match the filters, how many
have each property, most common first. It shows what metadata a set of records has, or
where it has gaps, in one call, such as which properties the people records lack. A
property none of them has isn't listed; to find the records missing one, search with a
`missing` condition. Where values carry language tags, a property also lists how many have
it in each language, to see how far translations go.

| Parameter | Type | Description |
|---|---|---|
| `resource_type` | string | `items` (the default), `media`, or `item_sets`. |
| `media_type` | string | When counting media, only media of this exact type, such as `image/jpeg`. |
| `query`, `contains`, `item_set`, `class`, `site`, `is_public`, `has_media`, `where` | | The same filters as `search_items`. `item_set` and `has_media` apply only to items. |

### search_media

Finds media, the files and other content attached to items, such as images, pages, and
PDFs. Media can have their own metadata, such as a page's description or transcription.
Results are short summaries with the item each media belongs to.

| Parameter | Type | Description |
|---|---|---|
| `media_type` | string | Only media of this exact type, such as `application/pdf`. |
| `item` | integer | Only media of this item, by ID, in the order they were added unless sorted otherwise. |
| `query`, `contains`, `class`, `site`, `is_public`, `where`, `sort`, `order`, `properties` | | The same as in `search_items`. |
| `page` | integer | Page number, starting at 1. |
| `per_page` | integer | Results per page, from 1 to 50. Default 20. |

`item_set` and `has_media` are for items only. To search the media of an item set, find
its items with `search_items`, then list each item's media with `item`.

### get_media

Reads one media: its metadata, the item it belongs to, links to its file, and in
`module_data`, anything other modules add. When the media has a thumbnail, as images and
usually PDFs and videos do, the result includes that image, so the assistant can see what
the media shows. An HTML media, which holds its text rather than a file, includes that
text as `content`, cut like a long value.

| Parameter | Type | Description |
|---|---|---|
| `id` | integer | The media's ID. Required. |
| `response_format` | string | `concise` (the default), or `detailed` for Omeka's own JSON-LD. |
| `image` | boolean | Include the image. Default `true`. |
| `content` | boolean | Read an HTML media's own text instead of its record, with `offset`, 10,000 characters at a time. |
| `property`, `page`, `offset`, `position` | | The same as in `get_item`. |

### search_item_sets

Finds item sets by their title, description, and other metadata. Results are short
summaries with each set's description and item count. `get_overview` already lists the
item sets, so this is for searching them by what they're about.

| Parameter | Type | Description |
|---|---|---|
| `query`, `contains`, `class`, `site`, `is_public`, `where`, `sort`, `order`, `properties` | | The same as in `search_items`. |
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
| `property`, `page`, `offset`, `position` | | The same as in `get_item`. |

## Privacy and security

- **Without a key, only public content is visible.** Every read goes through Omeka's own
  API, so Omeka's visibility rules apply unchanged. Private items, private media, and
  private values appear only to a request with an MCP key whose user may see them, and
  so do links to them.
- **Nothing can be changed.** All tools are read-only.
- **No sessions or cookies.** Requests don't start a session, and a browser's Omeka login
  never applies. Only an MCP key in the address signs a request in.
- **The endpoint is public by default,** like your public site and Omeka's REST API.
  Anyone who knows the address can read your public content. To limit it to people
  you've given keys, [require a key](#requiring-a-key). To limit how often it's used,
  rate-limit it at the web server, as you would any public endpoint.

Signing in through an assistant's own sign-in screen, so that each person uses their own
Omeka account without handling a key, is planned for a later version.

## Limits

These keep each answer within what assistants accept. When something is cut, the result
says so, and says how to read the rest, if there is a way.

- Values longer than 2,000 characters, such as long transcriptions, are cut, and
  `property` with `offset` reads the rest. Text cut inside an annotation or in `module_data` can't be
  read further.
- An item, media, or item set shows at most 100 values of one property, and `property`
  reads the rest. Other lists longer than 100 entries, such as the links from other items in the
  `detailed` format, are cut too, with their full length given.
- An item lists at most 50 of its media, and `search_media` with `item` lists them all.
- With `properties`, a search result lists at most 10 values of each property, each
  shortened to 300 characters, and a page is at most 40,000 characters; a longer one is
  refused, with the `per_page` that fits. `property` reads a property in full.
- `get_overview` shows at most 200 entries of each list, and `list` reads the rest.
- `get_media` sends the large thumbnail (800 pixels by default), never the original file.
- `count_values` and `count_properties` count across at most 200,000 matching items, media,
  or item sets. Narrow larger sets with filters. Media can reach this much sooner than
  items, as when each page of a scanned book is its own media. Above 200,000 items,
  `get_overview` leaves out its media type counts.
- `count_values` shortens values longer than 300 characters and marks them as cut. To
  find what has one, search with a `starts_with` condition on its text.
- Value annotations, such as a date's "approximate", are shown with their values but
  can't be searched or counted. When a search or count finds nothing because of that, the
  result says so and says where to read them.
- Full-text search (`query`) finds records with any of the words, and matches whole words,
  so "letter" doesn't find "letters". It ignores very common words. Use `contains` to
  match part of a word, or to require one.

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
private content: an Author or a Researcher sees public content and only the private
content they own. Check the user's role.

**Requests without a key are refused.** **Require sign-in** is on. Add a key to the
address, or turn the setting off in the module's configuration.

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

Other Omeka modules can add their own tools. A tool implements
`McpServer\Mcp\Tool\ToolInterface`, whose docblocks give the rules, including how to name
tools and how to read data so that visibility stays correct. Here is a complete one, which
counts the items added recently:

```php
namespace MyModule\Mcp;

use DateTime;
use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;

class CountRecentItems implements ToolInterface
{
    protected $api;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function getTitle(): string
    {
        return 'Count recent items';
    }

    public function getDescription(): string
    {
        return 'Count the items added in the last few days.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'description' => 'How many days back to count.'],
            ],
            'required' => ['days'],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $days = (new ArgumentReader($arguments))->requireInt('days', 1);
        $since = (new DateTime("-$days days"))->format('Y-m-d');
        $items = $this->api->search('items', ['created_after' => $since, 'limit' => 0])->getTotalResults();
        return ToolResult::json(['days' => $days, 'items' => $items]);
    }
}
```

Its factory builds it with Omeka's API manager, as
`new CountRecentItems($services->get('Omeka\ApiManager'))`, and your module's
configuration registers the factory under the `mcp_server` key:

```php
'mcp_server' => [
    'tools' => [
        'factories' => [
            'mymodule_count_recent_items' => Service\CountRecentItemsFactory::class,
        ],
    ],
],
```

Names from other modules start with the module's name, as above. A tool's name should
never change once released, because clients remember permissions by name. A tool's title,
description, and schema should be the same for every caller, since the tool list is shared.
This module's own tools, in `src/Mcp/Tool/Builtin/`, are fuller examples.

These are the parts other modules can rely on; everything else may change between versions:

- **`ToolInterface`**, with **`Context`**, **`ToolAnnotations`**, and **`ToolResult`**, in
  `McpServer\Mcp\Tool`. Return results with `ToolResult::json()`, and put guidance for the
  assistant, such as why nothing matched, in its note rather than in the data.
- **`ArgumentReader`**, to read arguments, and **`ToolException`**,
  to refuse a bad call with a message the assistant can act on. When a tool's input schema
  sets `additionalProperties` to `false`, as above, an argument it doesn't name is refused,
  so a misspelled filter can't pass for one applied.
- **The `McpServer\ResourceCounter` service**, for counts the API can't make, and to check
  whether a property or class is in use. Give it the API resource and query you would
  search with: it fetches the matching records through the API itself, so it counts only
  what the caller may see.

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
  adds OAuth sign-in, Omeka will be the identity provider: anonymous visitors can still
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
