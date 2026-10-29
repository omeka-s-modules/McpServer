<?php
namespace McpServer\Mcp\Protocol;

use Laminas\Log\LoggerInterface;
use McpServer\Mcp\Tool\ToolResult;
use Throwable;

/**
 * Answers MCP JSON-RPC messages.
 *
 * Here a request body is decoded and checked as JSON-RPC, then handed to its
 * era, and the result or error becomes the response, with the HTTP status the
 * spec gives it. The controller handles only HTTP and Omeka policy.
 *
 * Each era answers its own requests. EnvelopeEra serves 2026-07-28, where each
 * request carries its version in _meta, and HandshakeEra serves the earlier
 * versions, which open with initialize. What both eras ask of the tools is in
 * ToolRequests.
 */
class Dispatcher
{
    // How responses are encoded, the same as tool results inside them.
    const JSON_FLAGS = ToolResult::JSON_FLAGS;

    protected $handshakeEra;

    protected $envelopeEra;

    protected $logger;

    public function __construct(HandshakeEra $handshakeEra, EnvelopeEra $envelopeEra, LoggerInterface $logger)
    {
        $this->handshakeEra = $handshakeEra;
        $this->envelopeEra = $envelopeEra;
        $this->logger = $logger;
    }

    /**
     * Answer one request body.
     *
     * Every failure becomes a JSON-RPC error response, so nothing is thrown.
     *
     * @param string $body The raw request body
     * @param array $headers The MCP request headers, keyed by lowercase name
     * @return array [HTTP status, response body, or null when there is
     *   nothing to send back]
     */
    public function handle(string $body, array $headers): array
    {
        $id = null;
        try {
            $message = $this->decode($body);
            $id = self::getRequestId($message);
            $response = $this->dispatch($message, $headers);
            return null === $response ? [202, null] : [200, $response];
        } catch (ProtocolException $e) {
            return [$e->getHttpStatus(), self::errorResponse($id, $e->getCode(), $e->getMessage(), $e->getData())];
        } catch (Throwable $e) {
            $this->logger->err(sprintf('MCP Server: %s', $e));
            return [200, self::errorResponse($id, ProtocolException::INTERNAL_ERROR, 'Internal error')];
        }
    }

    /**
     * Build the error response for a ProtocolException thrown before the
     * dispatcher runs, with the message's id when the body has one.
     *
     * For the controller's own refusals, such as an MCP key that isn't
     * accepted.
     */
    public static function exceptionResponse(string $body, ProtocolException $e): array
    {
        return self::errorResponse(self::getBodyRequestId($body), $e->getCode(), $e->getMessage(), $e->getData());
    }

    /**
     * Build the internal error response for a request the server failed to
     * answer, with the message's id when the body has one.
     *
     * For the controller, when a request fails outside the dispatcher, such as
     * while signing in.
     */
    public static function failureResponse(string $body): array
    {
        return self::errorResponse(self::getBodyRequestId($body), ProtocolException::INTERNAL_ERROR, 'Internal error');
    }

    /**
     * Build a JSON-RPC error response.
     *
     * Public for the controller's errors that come before a message is read,
     * such as the wrong media type, which have no id.
     *
     * @param string|int|null $id The id of the message being answered, or
     *   null when it's unknown
     */
    public static function errorResponse($id, int $code, string $message, $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if (null !== $data) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /**
     * Decode a request body into one JSON-RPC message.
     *
     * @throws ProtocolException
     */
    protected function decode(string $body): array
    {
        $message = json_decode($body, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new ProtocolException('Parse error', ProtocolException::PARSE_ERROR, 400);
        }
        if (!is_array($message)) {
            throw new ProtocolException('Invalid request: the body must be a JSON object.', ProtocolException::INVALID_REQUEST, 400);
        }
        if ($message && array_is_list($message)) {
            throw new ProtocolException('Invalid request: batches are not supported. Send one message per request.', ProtocolException::INVALID_REQUEST, 400);
        }
        return $message;
    }

    /**
     * Get the id of the message in a raw body, or null when it has no valid
     * one.
     */
    protected static function getBodyRequestId(string $body): string|int|null
    {
        $message = json_decode($body, true);
        return is_array($message) ? self::getRequestId($message) : null;
    }

    /**
     * Get a message's id, or null when it has no valid one.
     */
    protected static function getRequestId(array $message): string|int|null
    {
        $id = $message['id'] ?? null;
        return (is_string($id) || is_int($id)) ? $id : null;
    }

    /**
     * Answer one decoded JSON-RPC message.
     *
     * @param array $message The decoded message
     * @param array $headers The MCP request headers, keyed by lowercase name
     * @return array|null The response, or null when there is nothing to send
     *     back: the message was a notification or a client's response
     * @throws ProtocolException
     */
    protected function dispatch(array $message, array $headers): ?array
    {
        if ('2.0' !== ($message['jsonrpc'] ?? null)) {
            throw new ProtocolException('Invalid request: jsonrpc must be "2.0".', ProtocolException::INVALID_REQUEST, 400);
        }
        if (!array_key_exists('method', $message)) {
            // A response to a request from the server. This server sends none,
            // so there is nothing to do with it.
            if (array_key_exists('id', $message) && (array_key_exists('result', $message) || array_key_exists('error', $message))) {
                return null;
            }
            throw new ProtocolException('Invalid request: method is missing.', ProtocolException::INVALID_REQUEST, 400);
        }
        $method = $message['method'];
        $params = $message['params'] ?? [];
        if (!is_string($method) || !is_array($params)) {
            throw new ProtocolException('Invalid request: method must be a string and params an object.', ProtocolException::INVALID_REQUEST, 400);
        }
        if (!array_key_exists('id', $message)) {
            // A notification. None needs an answer, and this server acts on none.
            return null;
        }
        $id = self::getRequestId($message);
        if (null === $id) {
            throw new ProtocolException('Invalid request: id must be a string or an integer.', ProtocolException::INVALID_REQUEST, 400);
        }
        $result = $this->isEnvelopeRequest($params, $headers)
            ? $this->envelopeEra->answer($method, $params, $headers)
            : $this->handshakeEra->answer($method, $params, $headers);
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * Whether a request is in the envelope era.
     *
     * Either _meta carries a protocol version, or the header names an
     * envelope-era one. The header matters for requests that leave out _meta,
     * so they can be told what's missing rather than taken for handshake-era
     * requests. It's checked before initialize, since in the envelope era
     * initialize is a removed method rather than the start of a handshake.
     */
    protected function isEnvelopeRequest(array $params, array $headers): bool
    {
        return isset($params['_meta'][EnvelopeEra::META_PROTOCOL_VERSION])
            || ProtocolVersion::isEnvelope($headers['mcp-protocol-version'] ?? '');
    }
}
