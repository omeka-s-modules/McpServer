<?php
namespace McpServerTest\Unit;

use McpServer\Mcp\Protocol\Dispatcher;
use McpServer\Mcp\Protocol\ProtocolException;
use PHPUnit\Framework\TestCase;

/**
 * Checks the error responses the endpoint builds outside the dispatcher, as
 * when a key is refused or signing in fails: each answers the message's id
 * when the body has a valid one, and null otherwise, as JSON-RPC requires.
 */
class DispatcherTest extends TestCase
{
    public function testAFailureAnswersTheMessagesIdWithAnInternalError()
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => ProtocolException::INTERNAL_ERROR, 'message' => 'Internal error']],
            Dispatcher::failureResponse('{"jsonrpc":"2.0","id":7,"method":"tools/list"}')
        );
        $this->assertSame('abc', Dispatcher::failureResponse('{"jsonrpc":"2.0","id":"abc","method":"tools/list"}')['id']);
    }

    public function testAMissingOrInvalidIdIsAnsweredAsNull()
    {
        foreach (['', 'not json', '[{"id":1}]', '{"id":1.5}', '{"id":true}', '{"id":[1]}', '{"method":"notifications/initialized"}'] as $body) {
            $this->assertNull(Dispatcher::failureResponse($body)['id'], $body);
        }
    }

    public function testAProtocolExceptionKeepsItsCodeMessageAndData()
    {
        $e = new ProtocolException('Unsupported version', ProtocolException::INVALID_PARAMS, 400, ['supported' => ['2025-11-25']]);
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => ProtocolException::INVALID_PARAMS, 'message' => 'Unsupported version', 'data' => ['supported' => ['2025-11-25']]]],
            Dispatcher::exceptionResponse('{"jsonrpc":"2.0","id":3}', $e)
        );
        $this->assertSame(400, $e->getHttpStatus());
    }
}
