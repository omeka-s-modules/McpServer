# MCP Server for Omeka S

A [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server for Omeka S. It
lets AI assistants such as Claude search your items, read their metadata, look at images,
read your sites' pages, and count what's there, all without leaving the conversation.

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

- [For site administrators](#for-site-administrators)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Connecting an assistant](#connecting-an-assistant)
  - [Example prompts](#example-prompts)
  - [MCP keys](#mcp-keys)
  - [Tools](#tools)
  - [Privacy and security](#privacy-and-security)
  - [Limits](#limits)
  - [Troubleshooting](#troubleshooting)
- [For developers](#for-developers)
  - [Protocol support](#protocol-support)
  - [Tool details](#tool-details)
  - [Adding tools from other modules](#adding-tools-from-other-modules)
  - [Running the tests](#running-the-tests)
  - [Why the protocol is built in](#why-the-protocol-is-built-in)
- [License](#license)

## For site administrators

Setting the module up, connecting assistants, and what they can see and do.

### Requirements

- Omeka S 4.2.1 or later.
- For assistants that connect from the cloud, such as Claude on the web or desktop, your
  Omeka installation must be reachable from the internet. An installation on `localhost`
  works only with assistants running on the same computer, such as Claude Code.

### Installation

1. Copy the module into Omeka's `modules` directory, in a folder named `McpServer`.
2. In the Omeka admin, go to **Modules** and click **Install** next to MCP Server.

The endpoint is `/mcp` under your Omeka base URL. **MCP Server > Overview** in the admin
shows its full address and the tools assistants can use. Use that address exactly, with
no slash at the end.

The module has one optional setting, **Require sign-in**, off by default. See
[Requiring a key](#requiring-a-key).

### Connecting an assistant

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

### Example prompts

Once an assistant is connected, ask in plain language. For example:

- "Give me an overview: what kinds of items are here, and how many?"
- "Find letters written in the 1700s and summarize what they're about."
- "I'm teaching a unit on the American Revolution. What primary sources are here, and in
  what order would you use them?"
- "Who are the most frequent creators, and what are the most common subjects?"
- "Look at the photographs of the harbor and describe what they show."
- "Which items are linked to the item about the town hall?"
- "What is each of the item sets about, and which are the largest?"
- "What do the site's exhibits say about the harbor, and which items do they show?"
- "Walk me through this site's exhibits in the order a visitor would see them."
- "What has been added or changed recently?"
- "Which fields do the records in this item set leave empty?"
- "Are there inconsistent spellings or rarely used fields in the metadata?"
- "What does each field on the Person records mean, and which records leave out fields
  their template expects?"

### MCP keys

Without a key, assistants see public content only. To let an assistant see private
content too, add an MCP key to the address:

```
https://example.org/mcp?key=KEY
```

Create the key in the Omeka admin, under **MCP Server > Keys**, which shows the address
with the key once, when the key is created. Anyone who may manage a user's API keys, such
as a Global Administrator, may manage their MCP keys there too.

The assistant sees what the key's user may see:

- **Reviewers, Editors, Supervisors, and Global Administrators** see all private items,
  media, item sets, and values.
- **Authors and Researchers** see public content and what they own.
- **Private site pages,** and the pages of private sites, follow Omeka's rules for sites:
  a user sees them on sites they own or have a role on, and a Global Administrator sees
  them all.

An MCP key works only at `/mcp`, never for Omeka's REST API, so it can't be used to change
anything, whatever its user's role. Omeka API keys don't work at `/mcp`.

Treat an address with a key like a password. Anyone who sees it can read what its user
may see:

- **It appears wherever the address does:** in your web server's access logs, in any
  proxy in front of it, and in the assistant's connector settings.
- **Where a connector is shared,** as on some assistants' team plans, everyone using it
  shares its key. Use an account that sees only what all of them should see.
- **To revoke access, delete the key** under **MCP Server > Keys**, which also shows when
  each key was last used, to within five minutes. A request with a key that no longer
  works is refused, rather than answered as if it had none.

#### Requiring a key

By default, anyone with the address can read your public content. To refuse assistants
without a key, check **Require sign-in** in the module's configuration: on the
**Modules** page, click **Configure** next to MCP Server. Only a Global Administrator can
change it. Requests without a key are then refused, with a message saying where to create
one.

To give public-only access while sign-in is required, create keys for a Researcher
account that owns nothing; it sees only public content. Each key can then be revoked on
its own.

### Tools

| Tool | What it does |
|---|---|
| `get_overview` | Describes the installation: counts, dates, and what search can filter on |
| `count_values` | Counts a property's values, most common first or alphabetically, such as creators, subjects, or image rights |
| `count_properties` | Counts which properties records have filled in, such as for a metadata audit |
| `search_items` | Finds items by words, filters, and conditions on properties |
| `get_item` | Reads one item in full |
| `search_media` | Finds media by words, type, item, and conditions on properties |
| `get_media` | Reads one media, with its image for the assistant to look at |
| `search_item_sets` | Finds item sets by words and conditions on properties |
| `get_item_set` | Reads one item set in full, with its item count |
| `get_resource_template` | Describes one resource template: the properties a kind of record should have, with the labels and comments the template gives them |
| `get_site` | Describes one site: its summary, how much it holds, and its navigation |
| `search_site_pages` | Finds site pages, such as exhibits, by their text, their site, or an item they show |
| `get_site_page` | Reads one site page: its text, block by block, and the items it shows |

Assistants usually start with `get_overview`, then search or count, then read the items
they need. Like Omeka, the tools call a record's fields properties, and name each one by
its term, such as `dcterms:title`.

When an assistant may see private content, results mark what's private, so it knows that
content isn't on the public site. It can also look for private content alone.

### Privacy and security

- **Without a key, only public content is visible.** Every read goes through Omeka's own
  API, so Omeka's visibility rules apply unchanged. Private items, media, values, and
  site pages appear only to a request with an MCP key whose user may see them, and so do
  links to them.
- **Nothing can be changed.** All tools are read-only, and the endpoint refuses writes
  through Omeka's API, even from another module's tool.
- **No sessions or cookies.** Requests don't start a session, and a browser's Omeka login
  never applies. Only an MCP key in the address signs a request in.
- **The endpoint is public by default,** like your public site and Omeka's REST API.
  Anyone who knows the address can read your public content. To limit it to people
  you've given keys, [require a key](#requiring-a-key). To limit how often it's used,
  rate-limit it at the web server, as you would any public endpoint.

Signing in through an assistant's own sign-in screen, so that each person uses their own
Omeka account without handling a key, is planned for a later version.

### Limits

These keep each answer to a size assistants accept. Anything cut is marked, so the
assistant knows there's more.

- Long texts, such as transcriptions, show their first 2,000 characters, and long lists
  their first 100 entries, with their full length. An assistant can read the rest of most
  of them, a part at a time.
- An item lists at most 50 of its media, and an assistant can list the rest.
- Images are sent as their large thumbnail, 800 pixels by default, never as the original
  file.
- Counts cover at most 200,000 matching items, media, or item sets, so larger sets need
  narrowing first. Media can reach this much sooner than items, as when each page of a
  scanned book is its own media.
- Word search finds records with any of the words, and matches whole words only, so
  "letter" doesn't find "letters". It ignores very common words. Assistants can also
  search for text inside a word.
- Value annotations, such as a date's "approximate", are shown with their values but
  can't be searched or counted.

### Troubleshooting

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
content they own. Check the user's role. Private site pages follow the site instead: the
user needs a role on that site, whatever their role elsewhere.

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

## For developers

How the module works, adding tools to it, and testing it.

### Protocol support

- **Transport:** Streamable HTTP. Requests are `POST /mcp` with a JSON body, and every
  response is a single JSON body. The server never opens an event stream and keeps no
  session.
- **Protocol versions:** 2025-06-18, 2025-11-25, and 2026-07-28.
- **Capabilities:** tools.
- **Testing:** the implementation is checked with the official MCP conformance tool,
  `@modelcontextprotocol/conformance`, and by the module's own tests. See
  [Running the tests](#running-the-tests).

### Tool details

The server's tool list gives each tool's full description and parameters, as assistants
read them. The MCP Inspector command under
[Connecting an assistant](#connecting-an-assistant) lists them. In the code, each tool's
`getInputSchema()` in `src/Mcp/Tool/Builtin/` defines its parameters, and
`src/Mcp/Format/OutputLimits.php` sets how much a tool shows, and says where the other
limits are.

When an assistant may see private content, results mark it as Omeka's JSON-LD does:
`"is_public": false` on a private item, media, item set, or value, and on a site page
that is private or on a private site. Search results show values as plain text, so
there a private value is `{"text": "…", "is_public": false}`, a value with a language
tag is `{"text": "…", "lang": "…"}`, and a value that links to another record gives its
`id`, as `{"text": "…", "id": 123}`. To find only private items, media, or item sets,
search or count with `is_public` set to `false`.

### Adding tools from other modules

Other Omeka modules can add their own tools. A tool implements
`McpServer\Mcp\Tool\ToolInterface`, whose docblocks give the rules, including how to name
tools and how to read data so that visibility stays correct. Here is a complete one, which
picks an item at random, from all of them or from one item set, for exploring the
collection by chance, something the built-in tools can't do:

```php
namespace MyModule\Mcp;

use McpServer\Mcp\Tool\ArgumentReader;
use McpServer\Mcp\Tool\Context;
use McpServer\Mcp\Tool\ToolAnnotations;
use McpServer\Mcp\Tool\ToolInterface;
use McpServer\Mcp\Tool\ToolResult;
use Omeka\Api\Manager as ApiManager;

class GetRandomItem implements ToolInterface
{
    protected $api;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
    }

    public function getTitle(): string
    {
        return 'Get a random item';
    }

    public function getDescription(): string
    {
        return 'Pick an item at random, from all of them or from one item set, to explore the collection by chance.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_set' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Pick only from this item set, by id.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getAnnotations(): array
    {
        return ToolAnnotations::READ_ONLY;
    }

    public function call(array $arguments, Context $context): ToolResult
    {
        $itemSet = (new ArgumentReader($arguments))->getInt('item_set', null, 1);
        $query = $itemSet ? ['item_set_id' => $itemSet] : [];
        // Count and pick through the API, so the caller sees only what they may.
        $total = $this->api->search('items', $query + ['limit' => 0])->getTotalResults();
        if (!$total) {
            // Guidance for the assistant goes in the note, not the data.
            return ToolResult::json(['item' => null], 'There are no items to pick from.');
        }
        $item = $this->api->search('items', $query + [
            'sort_by' => 'id',
            'offset' => random_int(0, $total - 1),
            'limit' => 1,
        ])->getContent()[0];
        return ToolResult::json(
            ['item' => ['id' => $item->id(), 'title' => $item->displayTitle()]],
            'Call get_item with this id to read it in full.'
        );
    }
}
```

Its factory builds it with Omeka's API manager, as
`new GetRandomItem($services->get('Omeka\ApiManager'))`, and your module's
configuration registers the factory under the `mcp_server` key:

```php
'mcp_server' => [
    'tools' => [
        'factories' => [
            'mymodule_get_random_item' => Service\GetRandomItemFactory::class,
        ],
    ],
],
```

Names from other modules start with the module's name, as above. A tool's name should
never change once released, because clients remember permissions by name. A tool's title,
description, and schema should be the same for every caller, since the tool list is shared.
This module's own tools, in `src/Mcp/Tool/Builtin/`, are fuller examples, and the
module's tests check every registered tool against these rules. See
[Running the tests](#running-the-tests).

These are the parts other modules can rely on; everything else may change between versions:

- **`ToolInterface`**, with **`Context`**, **`ToolAnnotations`**, and **`ToolResult`**, in
  `McpServer\Mcp\Tool`. Return results with `ToolResult::json()`, and put guidance for the
  assistant, such as why nothing matched, in its note rather than in the data.
- **`ArgumentReader`**, to read arguments, and **`ToolException`**, to refuse a bad call
  with a message the assistant can act on. When a tool's input schema sets
  `additionalProperties` to `false`, as above, an argument it doesn't name is refused, so a
  misspelled filter can't pass for one applied.
- **The `McpServer\ResourceCounter` service**, for counts the API can't make, and to check
  whether a property or class is in use. Give it the API resource and query you would
  search with: it fetches the matching records through the API itself, so it counts only
  what the caller may see.

### Running the tests

The module's tests use Omeka's own test framework and build their own records, so they
run on any development copy of Omeka:

- **Unit tests** check that only a key's hash is kept, how tools are run and their results
  shaped, and the functions that read arguments and that cut, shorten, and convert text.
  They need only Omeka's development dependencies (`composer install`).
- **Database tests** install Omeka and the module in a fresh database, and build public
  and private records and users in each role. They check that:
  - every tool shows each user only what Omeka lets them see, and marks what's private
  - nobody can write through the endpoint, even with a tool that tries
  - in the admin, users can manage only the MCP keys they're allowed to
  - searches and counts give the right answers
  - long lists and texts are cut, and the rest can be read
  - the protocol, the endpoint, and MCP keys work as described
  - every registered tool follows the rules for tools, so a module that adds tools can
    run them to check its own

  They need an empty MySQL database set in Omeka's
  `application/test/config/database.ini`, the one Omeka's own tests use. The tests drop
  every table in it.

From Omeka's root, run all of them, or only the unit tests:

```sh
vendor/bin/phpunit -c modules/McpServer/test/phpunit.xml
vendor/bin/phpunit -c modules/McpServer/test/phpunit.xml --testsuite unit
```

The tests aren't included in the module's release packages.

### Why the protocol is built in

The server speaks MCP with its own small implementation rather than a PHP MCP library.
For a server like this one, MCP over HTTP comes down to a handful of JSON-RPC methods, and
as of October 2026 none of the PHP libraries fit Omeka. The official SDK, `mcp/sdk`, is
still experimental, and needs about ten packages that would load ahead of Omeka's own
copies. The others need a newer `psr/log` than Omeka's logging uses, or a framework of
their own. And their sign-in assumes an outside identity provider, with no way to let
anonymous requests through while signed-in ones see more, which this server needs now,
and will need when it adds OAuth sign-in with Omeka as the identity provider.

WordPress made the same choice for its MCP adapter. The protocol code is kept apart from
the tools, in `src/Mcp/Protocol/`, so a library could replace it later without changing
any tool.

## License

MCP Server is Copyright © 2026-present Corporation for Digital Scholarship, Vienna,
Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code under
the GNU General Public License, version 3 (GPLv3). The full text of this license
is given in the license file.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

All rights not expressly granted are reserved.
