# MCP Server for Omeka S

A [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server for Omeka S. It
lets AI assistants such as Claude search your items, read their metadata, look at images,
read your sites' pages, and count what's there, all without leaving the conversation.

The server runs inside Omeka as a module and adds one endpoint to your installation:

```
https://example.org/mcp
```

- **Read-only.** Assistants can't create, change, or delete anything.
- **Public content, unless you add a key.** Assistants see your public content. With an
  MCP key in the address, they see what that key's user may see, and you can require one.
- **Nothing for users to install.** Add the address to an assistant and start asking.

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
[Requiring a key](#requiring-a-key). Uninstalling the module deletes its setting and every
MCP key.

### Connecting an assistant

Add the server to your assistant as a remote MCP server, using your endpoint's address,
such as `https://example.org/mcp`. It uses Streamable HTTP, and needs no MCP key unless
you've turned on **Require sign-in**. Each assistant explains where to add a server:

- [Claude](https://support.claude.com/en/articles/11175166-getting-started-with-custom-connectors-using-remote-mcp),
  on the web or desktop, as a custom connector
- [Claude Code](https://code.claude.com/docs/en/mcp)
- [VS Code](https://code.visualstudio.com/docs/copilot/customization/mcp-servers)
- [Cursor](https://cursor.com/docs/context/mcp)

### Example prompts

Once an assistant is connected, ask in plain language. For example:

- "Give me an overview: what kinds of items are here, and how many?"
- "Find letters written in the 1700s and summarize what they're about."
- "Who are the most frequent creators, and what are the most common subjects?"
- "Look at the photographs of the harbor and describe what they show."
- "Walk me through this site's exhibits in the order a visitor would see them."
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
  a user sees them on sites they own or have a role on, and Supervisors and Global
  Administrators see them all.

An MCP key works only at `/mcp`, never for Omeka's REST API, so it can't be used to change
anything, whatever its user's role. Omeka API keys don't work at `/mcp`.

Treat an address with a key like a password. Anyone who sees it can read what its user
may see:

- **It appears wherever the address does:** in your web server's access logs, in any
  proxy in front of it, and in the assistant's connector settings.
- **Where a connector is shared,** as on some assistants' team plans, everyone using it
  shares its key. Use an account that sees only what all of them should see.
- **To revoke access, delete the key** under **MCP Server > Keys**, which also shows when
  each key was last used. A request with a key that no longer works is refused, rather
  than answered as if it had none.

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
| `get_resource_template` | Describes one resource template: the properties a kind of record should have, with the labels and comments that say what each one means |
| `get_site` | Describes one site: its summary, how much it holds, and its navigation |
| `search_site_pages` | Finds site pages, such as exhibits, by their text, their site, or an item they show |
| `get_site_page` | Reads one site page: its text, block by block, and the items it shows |

Like Omeka, the tools call a record's fields properties, and name each one by its term,
such as `dcterms:title`.

With a key, assistants can tell private content from public, and can look for private
content alone.

### Privacy and security

- **Without a key, an assistant sees only public content,** the same as Omeka's REST API
  shows a visitor. With a key, it sees what the key's user may see.
- **Nothing can be changed.** All tools are read-only, and the endpoint refuses writes
  through Omeka's API, even from another module's tool.
- **The endpoint is public by default,** like your public site and Omeka's REST API.
  Anyone who knows the address can read your public content. To limit it to people
  you've given keys, [require a key](#requiring-a-key). To limit how often it's used,
  rate-limit it at the web server, as you would any public endpoint.

### Troubleshooting

**Results have no links to public pages.** Assistants link only to pages on sites a
visitor can see. Assign the items and item sets to a public site, and their links appear.

**Searches miss items,** for example after importing directly into the database. Rebuild
the full-text index: on the admin **Settings** page, check **Index full-text search** and
save.

**An assistant with a key still sees only public content.** The key's user may not see
private content: an Author or a Researcher sees public content and only the private
content they own. Check the user's role. Private site pages follow the site instead: the
user needs a role on that site, unless they're a Supervisor or a Global Administrator.

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

### Tool details

The server's tool list gives each tool's full description and parameters, as assistants
read them. The [MCP Inspector](https://github.com/modelcontextprotocol/inspector) lists
them:

```sh
npx @modelcontextprotocol/inspector --cli https://example.org/mcp --transport http --method tools/list
```

In the code, each tool's `getInputSchema()` in `src/Mcp/Tool/Builtin/` defines its
parameters, and `src/Mcp/Format/OutputLimits.php` sets how much a tool shows, and says
where the other limits are.

Results mark private records and values with `"is_public": false`, as Omeka's JSON-LD
does; to find only private items, media, or item sets, search or count with `is_public`
set to `false`.

### Adding tools from other modules

Other Omeka modules can add their own tools. A tool implements
`McpServer\Mcp\Tool\ToolInterface`, whose docblocks give the rules, including how to name
tools and how to read data so that visibility stays correct. Here is a complete one, which
picks an item at random, from all of them or from one item set, for exploring by chance,
something the built-in tools can't do:

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
        return 'Pick an item at random, from all of them or from one item set, to explore what\'s here by chance.';
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
never change once released, because clients remember approvals by name. A tool's title,
description, schema, and annotations should be the same for every caller, since the tool
list is shared. This module's own tools, in `src/Mcp/Tool/Builtin/`, are fuller examples,
and the module's tests check each of them against these rules.

These are the parts other modules can rely on; everything else may change between versions:

- **`ToolInterface`**, with **`Context`**, **`ToolAnnotations`**, and **`ToolResult`**, in
  `McpServer\Mcp\Tool`. Return results with `ToolResult::json()`, and put guidance for the
  assistant, such as why nothing matched, in its note rather than in the data.
- **`ArgumentReader`**, to read arguments, and **`ToolException`**, to refuse a bad call
  with a message the assistant can act on. When a tool's input schema sets
  `additionalProperties` to `false`, as above, an argument it doesn't name is refused, so a
  misspelled filter can't pass for one applied.

To count what the API can't, such as values grouped by property, follow the rules for
reading data in `ToolInterface`: get the records' IDs from the API, then query only rows
tied to those IDs. This module's own counting code is internal. If your tool needs it, ask
on the [Omeka forum](https://forum.omeka.org/c/omeka-s/modules), so a supported version
can be designed around real use.

### Running the tests

The tests use Omeka's own test framework and build their own records, so they run on any
development copy of Omeka. **Unit tests** need only Omeka's development dependencies
(`composer install`). **Database tests** check what each role may see, that nothing can
be written, and that the tools, the endpoint, and the admin pages work as described. They
need an empty MySQL database set in Omeka's `application/test/config/database.ini`, the
one Omeka's own tests use, and they drop every table in it.

From Omeka's root, run all of them, or only the unit tests:

```sh
vendor/bin/phpunit -c modules/McpServer/test/phpunit.xml
vendor/bin/phpunit -c modules/McpServer/test/phpunit.xml --testsuite unit
```

The tests aren't included in the module's release packages.

## License

MCP Server is Copyright © 2026-present Corporation for Digital Scholarship, Vienna,
Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code under
the GNU General Public License, version 3 (GPLv3). The full text of this license
is given in the license file.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

All rights not expressly granted are reserved.
