<?php
namespace McpServer\Controller;

use ArrayIterator;
use DateTime;
use Doctrine\ORM\EntityManager;
use Laminas\Authentication\AuthenticationService;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use McpServer\Entity\McpServerKey;
use McpServer\Mcp\Protocol\Dispatcher;
use McpServer\Mcp\Protocol\ProtocolException;
use McpServer\Module;
use Omeka\Api\Request as ApiRequest;
use Omeka\Permissions\Acl;
use Throwable;

/**
 * The MCP endpoint, over Streamable HTTP.
 *
 * Handles HTTP and Omeka policy only: the method, the media type, the MCP
 * headers, denying writes, signing in with an MCP key, and the response. The
 * Dispatcher does all of JSON-RPC. A response with a body has a single JSON
 * body, except the refusal of a method other than POST, which explains itself
 * in plain text to a browser. The server never opens an event stream, never issues a session ID,
 * and never sets cookies. A request without an MCP key is anonymous and sees
 * public content only, or is refused when the installation requires sign-in.
 */
class McpController extends AbstractActionController
{
    // Request headers the protocol reads, by lowercase name.
    const MCP_HEADERS = ['mcp-protocol-version', 'mcp-method', 'mcp-name'];

    // How often a key's last use is recorded, at most, in seconds.
    const LAST_USED_INTERVAL = 300;

    // API operations no caller may perform here.
    const WRITE_OPERATIONS = [
        ApiRequest::CREATE, ApiRequest::BATCH_CREATE,
        ApiRequest::UPDATE, ApiRequest::BATCH_UPDATE,
        ApiRequest::DELETE, ApiRequest::BATCH_DELETE,
    ];

    protected $acl;

    // API adapter classes, which the API manager checks in the ACL before
    // every operation.
    protected $apiAdapters;

    protected $entityManager;

    protected $auth;

    protected $dispatcher;

    public function __construct(Acl $acl, array $apiAdapters, EntityManager $entityManager, AuthenticationService $auth, Dispatcher $dispatcher)
    {
        $this->acl = $acl;
        $this->apiAdapters = $apiAdapters;
        $this->entityManager = $entityManager;
        $this->auth = $auth;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Answer one MCP message.
     */
    public function indexAction()
    {
        // Notices that other code prints during the request would land in
        // front of the JSON and corrupt it, so output is captured and logged.
        ob_start();
        try {
            $response = $this->handle($this->getRequest());
        } catch (Throwable $e) {
            // An exception that reaches Omeka renders an HTML error page and
            // starts a session, so it's answered here as JSON. The dispatcher
            // answers its own failures, so this catches only those outside
            // it, such as a database error while looking up a key.
            $this->logger()->err(sprintf('MCP Server: %s', $e));
            $response = $this->jsonResponse(200, Dispatcher::failureResponse((string) $this->getRequest()->getContent()));
        } finally {
            $output = ob_get_clean();
        }
        if (is_string($output) && '' !== trim($output)) {
            $this->logger()->warn(sprintf(
                'MCP Server: left this output out of the response: %s',
                mb_substr(trim(strip_tags($output)), 0, 1000)
            ));
        }
        return $response;
    }

    protected function handle(Request $request): Response
    {
        if (!$request->isPost()) {
            // A browser opening the address sends GET, so the refusal says
            // what the address is, in the installation's language, for
            // whoever opened it. HTTP asks for an explanation with an error,
            // and MCP's spec leaves a 405's body open.
            $response = $this->getResponse();
            $response->setStatusCode(405);
            $response->getHeaders()
                ->addHeaderLine('Allow', 'POST')
                ->addHeaderLine('Content-Type', 'text/plain; charset=UTF-8');
            $response->setContent($this->translate("This address is for AI assistants. Add it to an assistant as an MCP server; it can't be opened in a browser.") . "\n");
            return $response;
        }
        if (!$this->isJson($request)) {
            return $this->jsonResponse(415, Dispatcher::errorResponse(
                null, ProtocolException::INVALID_REQUEST, 'Send the request body as application/json.'
            ));
        }
        $this->denyWrites();
        try {
            $this->signIn($request);
        } catch (ProtocolException $e) {
            return $this->jsonResponse($e->getHttpStatus(), Dispatcher::exceptionResponse($request->getContent(), $e));
        }
        [$status, $body] = $this->dispatcher->handle($request->getContent(), $this->getMcpHeaders($request));
        if (null === $body) {
            $response = $this->getResponse();
            $response->setStatusCode($status);
            return $response;
        }
        return $this->jsonResponse($status, $body);
    }

    /**
     * Deny every caller the API's writes, for this request.
     *
     * The tools only read, and tools from other modules must too: here, no
     * caller, anonymous or signed in with an MCP key, may create, update, or
     * delete anything through Omeka's API, whatever their role or other
     * modules' rules. The API manager checks the adapter before every
     * operation, batches included, so the denial is set on the adapters. A
     * rule for a role on a resource outranks one for all roles or all
     * resources, so it's set for each role on each adapter. The ACL is built
     * for each request, so it lasts only for this one.
     */
    protected function denyWrites(): void
    {
        $this->acl->deny(array_merge([null], $this->acl->getRoles()), $this->apiAdapters, self::WRITE_OPERATIONS);
    }

    /**
     * Sign the request in as the owner of the MCP key in its URL, if any.
     *
     * The key comes in the URL, as key. It works only here, never for Omeka's
     * REST API, and only reads, since writes through Omeka's API are denied
     * here. This route never takes the caller from a session or a browser
     * cookie, so without a key the request is anonymous and sees public
     * content only. When the installation requires sign-in, a request without
     * a key is refused instead, with 403 for the reason given below.
     *
     * Omeka API keys are refused, since they can also write through the REST
     * API. This module doesn't use them, but core does: on any key-auth
     * route, it signs a request in from key_identity and key_credential
     * before this controller runs. The route needs key-auth for its other
     * effect, that requests start no session and ignore browser cookies.
     *
     * A key that doesn't sign the request in is refused, not answered as
     * anonymous, so a mistyped key can't look like an installation with
     * nothing private. The status is 403, not 401, which would need an HTTP
     * authentication challenge and would start a client's OAuth sign-in.
     *
     * @throws ProtocolException
     */
    protected function signIn(Request $request): void
    {
        if (null !== $request->getQuery('key_identity') || null !== $request->getQuery('key_credential')) {
            throw new ProtocolException(
                'Omeka API keys don\'t work here. Create an MCP key in the Omeka admin, under MCP Server > Keys, and use the address it gives.',
                ProtocolException::INVALID_REQUEST,
                400
            );
        }
        $key = $request->getQuery('key');
        if (null === $key) {
            if ($this->settings()->get(Module::REQUIRE_SIGN_IN, false)) {
                throw new ProtocolException(
                    'This server requires an MCP key. Create one in the Omeka admin, under MCP Server > Keys, and use the address it gives.',
                    ProtocolException::INVALID_REQUEST,
                    403
                );
            }
            return;
        }
        $mcpKey = is_string($key) && '' !== $key
            ? $this->entityManager->getRepository(McpServerKey::class)->findOneBy(['hash' => McpServerKey::hashKey($key)])
            : null;
        if (!$mcpKey || !$mcpKey->getOwner()->isActive()) {
            throw new ProtocolException(
                'The MCP key in the address wasn\'t accepted. Create a new one in the Omeka admin, under MCP Server > Keys.',
                ProtocolException::INVALID_REQUEST,
                403
            );
        }
        $this->recordUse($mcpKey);
        $this->auth->getStorage()->write($mcpKey->getOwner());
    }

    /**
     * Record when a key was used, at most every LAST_USED_INTERVAL seconds,
     * since a conversation makes many calls, and never at the cost of the
     * request: a failure is logged, not raised.
     *
     * Updates the row directly, since a failed flush would close the entity
     * manager, and the tools need it for the rest of the request.
     */
    protected function recordUse(McpServerKey $mcpKey): void
    {
        $now = new DateTime;
        $last = $mcpKey->getLastUsed();
        if ($last && $now->getTimestamp() - $last->getTimestamp() < self::LAST_USED_INTERVAL) {
            return;
        }
        try {
            $this->entityManager->createQuery(sprintf('UPDATE %s k SET k.lastUsed = :now WHERE k.id = :id', McpServerKey::class))
                ->setParameter('now', $now, 'datetime')
                ->setParameter('id', $mcpKey->getId())
                ->execute();
        } catch (Throwable $e) {
            $this->logger()->warn(sprintf('MCP Server: could not record an MCP key\'s use: %s', $e->getMessage()));
        }
    }

    /**
     * Whether the request body is JSON, ignoring parameters such as charset.
     */
    protected function isJson(Request $request): bool
    {
        try {
            $header = $request->getHeaders()->get('Content-Type');
        } catch (Throwable $e) {
            // Laminas parses headers lazily, and a malformed one throws here.
            return false;
        }
        if (!$header || $header instanceof ArrayIterator) {
            return false;
        }
        $mediaType = strtolower(trim(explode(';', $header->getFieldValue())[0]));
        return 'application/json' === $mediaType;
    }

    /**
     * Get the MCP request headers, keyed by lowercase name.
     */
    protected function getMcpHeaders(Request $request): array
    {
        $headers = [];
        foreach (self::MCP_HEADERS as $name) {
            try {
                $header = $request->getHeaders()->get($name);
            } catch (Throwable $e) {
                // An unreadable header can't match the body.
                $headers[$name] = '';
                continue;
            }
            if ($header instanceof ArrayIterator) {
                // Repeated headers can't match the body either.
                $headers[$name] = implode(', ', array_map(fn ($h) => $h->getFieldValue(), iterator_to_array($header)));
            } elseif ($header) {
                $headers[$name] = trim($header->getFieldValue());
            }
        }
        return $headers;
    }

    protected function jsonResponse(int $status, array $body): Response
    {
        $response = $this->getResponse();
        $response->setStatusCode($status);
        $response->getHeaders()->clearHeaders()
            ->addHeaderLine('Content-Type', 'application/json')
            ->addHeaderLine('Cache-Control', 'no-store');
        $response->setContent(json_encode($body, Dispatcher::JSON_FLAGS));
        return $response;
    }
}
