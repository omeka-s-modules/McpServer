<?php
namespace McpServer\Controller;

use ArrayIterator;
use Laminas\Authentication\AuthenticationService;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Http\Response;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use McpServer\Mcp\Exception\ProtocolException;
use McpServer\Mcp\Protocol\Dispatcher;
use Throwable;

/**
 * The MCP endpoint, over Streamable HTTP.
 *
 * Handles HTTP and Omeka policy only: the method, the media type, the MCP
 * headers, refusing credentials, and the response. The Dispatcher does all of
 * JSON-RPC. Every response is a single JSON body. The server never opens an
 * event stream, never issues a session ID, and never sets cookies. Every
 * request is anonymous, so callers see public content only.
 */
class IndexController extends AbstractActionController
{
    // Request headers the protocol reads, by lowercase name.
    const MCP_HEADERS = ['mcp-protocol-version', 'mcp-method', 'mcp-name'];

    protected $auth;

    // Builds the dispatcher, which isn't built until it's needed.
    protected $dispatcherFactory;

    protected $dispatcher;

    protected $logger;

    /**
     * @param callable $dispatcherFactory Returns the Dispatcher. It's called
     *   inside the action's error handling, so a failure to build it, such as
     *   a broken tool in another module's config, is still answered as JSON.
     */
    public function __construct(AuthenticationService $auth, callable $dispatcherFactory, LoggerInterface $logger)
    {
        $this->auth = $auth;
        $this->dispatcherFactory = $dispatcherFactory;
        $this->logger = $logger;
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
            // answers its own failures, so this catches only those before it
            // starts, such as failing to build it.
            $this->logger->err(sprintf('MCP Server: %s', $e));
            $response = $this->jsonResponse(200, Dispatcher::failureResponse((string) $this->getRequest()->getContent()));
        } finally {
            $output = ob_get_clean();
        }
        if (is_string($output) && '' !== trim($output)) {
            $this->logger->warn(sprintf(
                'MCP Server: left this output out of the response: %s',
                mb_substr(trim(strip_tags($output)), 0, 1000)
            ));
        }
        return $response;
    }

    protected function getDispatcher(): Dispatcher
    {
        return $this->dispatcher ??= ($this->dispatcherFactory)();
    }

    protected function handle(Request $request): Response
    {
        if (!$request->isPost()) {
            $response = $this->getResponse();
            $response->setStatusCode(405);
            $response->getHeaders()->addHeaderLine('Allow', 'POST');
            return $response;
        }
        if (!$this->isJson($request)) {
            return $this->jsonResponse(415, Dispatcher::errorResponse(
                null, ProtocolException::INVALID_REQUEST, 'Send the request body as application/json.'
            ));
        }
        [$status, $body] = $this->getDispatcher()->handle(
            $request->getContent(),
            $this->getMcpHeaders($request),
            fn () => $this->refuseSignedInRequests($request)
        );
        if (null === $body) {
            $response = $this->getResponse();
            $response->setStatusCode($status);
            return $response;
        }
        return $this->jsonResponse($status, $body);
    }

    /**
     * Keep every request anonymous.
     *
     * The route uses core's key-auth mode, which starts no session and never
     * takes the caller from a browser cookie. In that mode, though, core signs
     * a request in from key_identity and key_credential in the URL before this
     * controller runs, so such requests are refused.
     *
     * Signing in, when it's supported, belongs here: before any query, and
     * only once per request. Doctrine caches compiled queries per request, and
     * the visibility filters don't vary with the identity, so a query compiled
     * for one identity would be reused for another.
     *
     * @throws ProtocolException
     */
    protected function refuseSignedInRequests(Request $request): void
    {
        if (null !== $request->getQuery('key_identity') || null !== $request->getQuery('key_credential') || $this->auth->hasIdentity()) {
            throw new ProtocolException(
                'This server doesn\'t accept credentials. Remove key_identity and key_credential from the URL.',
                ProtocolException::INVALID_REQUEST,
                400
            );
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
