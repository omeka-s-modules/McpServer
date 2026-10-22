<?php
namespace McpServer\Mcp\Exception;

use RuntimeException;

/**
 * A JSON-RPC error to send back to the client.
 *
 * The exception code is the JSON-RPC error code. The HTTP status is kept
 * separately because the MCP spec fixes it for some errors (400 for header
 * and version problems, 404 for an unknown method in the modern era) and
 * leaves it open for others.
 */
class ProtocolException extends RuntimeException
{
    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;

    // Codes defined by MCP 2026-07-28.
    const HEADER_MISMATCH = -32020;
    const UNSUPPORTED_PROTOCOL_VERSION = -32022;

    protected $httpStatus;

    protected $data;

    public function __construct(string $message, int $code, int $httpStatus = 200, $data = null)
    {
        parent::__construct($message, $code);
        $this->httpStatus = $httpStatus;
        $this->data = $data;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Get the optional error data, or null if there is none.
     */
    public function getData()
    {
        return $this->data;
    }
}
