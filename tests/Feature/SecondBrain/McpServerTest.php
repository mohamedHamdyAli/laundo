<?php

namespace Tests\Feature\SecondBrain;

use Laundo\SecondBrain\Brain;
use Laundo\SecondBrain\Build\Doctor;
use Laundo\SecondBrain\Build\Indexer;
use Laundo\SecondBrain\Mcp\Tools;
use Laundo\SecondBrain\Support\Paths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The MCP surface, driven the way a client drives it.
 *
 * The server is started as a real subprocess and spoken to over stdio, because
 * the failure this is guarding against is not a wrong answer — it is a byte on
 * stdout that is not JSON-RPC. One PHP warning, one stray `echo`, and the
 * client's parser desynchronises and the server presents as hanging with
 * nothing in any log. That cannot be caught by calling the handler in-process.
 */
final class McpServerTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 3);

        require_once self::$root.'/.second-brain/autoload.php';

        $paths = Paths::discover(self::$root);
        if (! (new Brain($paths))->isBuilt()) {
            Indexer::make($paths)->run();
        }
    }

    /**
     * Speak to a freshly started server and collect its replies.
     *
     * @param  list<array<string,mixed>>  $messages
     * @return list<array<string,mixed>>
     */
    private function converse(array $messages): array
    {
        $input = '';
        foreach ($messages as $message) {
            $input .= json_encode($message)."\n";
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, self::$root.'/.second-brain/bin/mcp-server.php'],
            $descriptors,
            $pipes,
            self::$root
        );

        $this->assertIsResource($process, 'the MCP server should start');

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $replies = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            $this->assertIsArray(
                $decoded,
                "every line on stdout must be JSON-RPC. Got: {$line}\nstderr was: {$stderr}"
            );

            $replies[] = $decoded;
        }

        return $replies;
    }

    /** @return array<string,mixed> */
    private function callTool(string $name, array $arguments): array
    {
        $replies = $this->converse([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => []]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]],
        ]);

        foreach ($replies as $reply) {
            if (($reply['id'] ?? null) === 2) {
                return $reply;
            }
        }

        $this->fail("no reply to the {$name} call");
    }

    // ---------------------------------------------------------------- protocol

    #[Test]
    public function it_completes_the_handshake_and_advertises_its_tools(): void
    {
        $replies = $this->converse([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => []]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ]);

        // Two replies, not three: a notification must never be answered.
        $this->assertCount(2, $replies);

        $this->assertSame('2025-06-18', $replies[0]['result']['protocolVersion']);
        $this->assertSame('laundo-second-brain', $replies[0]['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $replies[0]['result']['capabilities']);

        $names = array_column($replies[1]['result']['tools'], 'name');

        $this->assertSame([
            'second_brain_search',
            'second_brain_get_feature',
            'second_brain_get_module',
            'second_brain_get_dependencies',
            'second_brain_get_related_files',
            'second_brain_get_architecture',
        ], $names);
    }

    #[Test]
    public function every_tool_declares_a_description_and_a_schema(): void
    {
        foreach (Tools::definitions() as $tool) {
            $this->assertNotEmpty($tool['name']);
            $this->assertNotEmpty($tool['description'], $tool['name'].' needs a description');
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertArrayHasKey('properties', $tool['inputSchema']);
        }
    }

    #[Test]
    public function an_unknown_method_is_a_json_rpc_error_not_a_crash(): void
    {
        $replies = $this->converse([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nonsense/method'],
        ]);

        $this->assertSame(-32601, $replies[0]['error']['code']);
    }

    #[Test]
    public function malformed_input_is_answered_with_a_parse_error(): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, self::$root.'/.second-brain/bin/mcp-server.php'],
            $descriptors,
            $pipes,
            self::$root
        );

        fwrite($pipes[0], "{not json at all\n");
        fclose($pipes[0]);

        $stdout = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $reply = json_decode($stdout, true);

        $this->assertSame(-32700, $reply['error']['code']);
    }

    // ------------------------------------------------------------------- tools

    #[Test]
    public function search_returns_a_small_text_payload(): void
    {
        $reply = $this->callTool('second_brain_search', ['query' => 'coupon discount', 'limit' => 5]);

        $this->assertFalse($reply['result']['isError']);
        $this->assertSame('text', $reply['result']['content'][0]['type']);

        $text = $reply['result']['content'][0]['text'];
        $payload = json_decode($text, true);

        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['results']);
        $this->assertLessThan(24000, strlen($text), 'MCP responses are capped so they cannot flood the context');
    }

    #[Test]
    public function each_tool_answers_and_stays_within_the_cap(): void
    {
        $calls = [
            'second_brain_search' => ['query' => 'settlement commission'],
            'second_brain_get_feature' => ['feature' => 'Discount Codes'],
            'second_brain_get_module' => ['module' => 'Order'],
            'second_brain_get_dependencies' => ['target' => 'SettlementService'],
            'second_brain_get_related_files' => ['target' => 'app/Modules/Order/Services/OrderStateMachine.php'],
            'second_brain_get_architecture' => ['section' => 'overview'],
        ];

        foreach ($calls as $name => $arguments) {
            $reply = $this->callTool($name, $arguments);

            $this->assertFalse($reply['result']['isError'], $name.' returned an error');

            $text = $reply['result']['content'][0]['text'];
            $this->assertLessThanOrEqual(24500, strlen($text), $name.' returned too much');
            $this->assertNotEmpty(json_decode($text, true), $name.' returned nothing usable');
        }
    }

    /**
     * The largest answers the brain can produce, asked for by name.
     *
     * These are the ones that broke: the biggest feature groups encode well
     * past the cap, and the original code cut the JSON string in half and
     * still reported success — so the caller received text that parsed to null
     * with `isError: false` and no way to tell. A capped answer has to stay
     * valid JSON and say that it was shortened.
     */
    #[Test]
    public function an_oversized_answer_is_shortened_but_stays_valid_json(): void
    {
        $oversized = [
            ['second_brain_get_feature', ['feature' => "API \u{00B7} Driver"]],
            ['second_brain_get_feature', ['feature' => "API \u{00B7} Orders"]],
            ['second_brain_get_feature', ['feature' => 'Drivers']],
            ['second_brain_get_feature', ['feature' => 'Laundries']],
            ['second_brain_get_architecture', ['section' => 'communities']],
        ];

        foreach ($oversized as [$name, $arguments]) {
            $label = $name.' '.json_encode($arguments, JSON_UNESCAPED_UNICODE);
            $reply = $this->callTool($name, $arguments);

            $this->assertFalse($reply['result']['isError'], $label.' returned an error');

            $text = $reply['result']['content'][0]['text'];
            $decoded = json_decode($text, true);

            $this->assertIsArray($decoded, $label.' did not return parseable JSON');
            $this->assertLessThanOrEqual(24500, strlen($text), $label.' exceeded the cap');

            // Whatever shortening happened has to be stated, not silent.
            if (isset($decoded['_shortened'])) {
                $this->assertNotEmpty($decoded['truncated'], $label.' shortened lists without saying so');
            }
        }
    }

    #[Test]
    public function an_unknown_tool_name_is_reported_rather_than_thrown(): void
    {
        $reply = $this->callTool('second_brain_does_not_exist', []);

        $payload = json_decode($reply['result']['content'][0]['text'], true);

        $this->assertArrayHasKey('error', $payload);
        $this->assertContains('second_brain_search', $payload['available']);
    }

    #[Test]
    public function tool_limits_are_clamped_rather_than_trusted(): void
    {
        $reply = $this->callTool('second_brain_search', ['query' => 'order', 'limit' => 9999]);

        $payload = json_decode($reply['result']['content'][0]['text'], true);

        $this->assertLessThanOrEqual(25, count($payload['results']));
    }

    #[Test]
    public function no_tool_response_contains_source_code_or_a_secret(): void
    {
        $calls = [
            'second_brain_search' => ['query' => 'app key password token secret'],
            'second_brain_get_module' => ['module' => 'Platform'],
            'second_brain_get_architecture' => ['section' => 'overview'],
        ];

        $forbidden = ['<?php', 'APP_KEY', 'DB_PASSWORD', 'base64:', 'AKIA', '-----BEGIN'];

        foreach ($calls as $name => $arguments) {
            $text = $this->callTool($name, $arguments)['result']['content'][0]['text'];

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $text, "{$name} leaked [{$needle}]");
            }
        }
    }

    // ------------------------------------------------------------------ doctor

    /**
     * Staleness is deliberately excluded here.
     *
     * The doctor is right to report it — a file added since the last index
     * really is missing from the brain, and this very test file triggered it
     * the first time it ran. But asserting a *fresh* index in a test would mean
     * rebuilding on every run, which rewrites `manifest.json` and dirties the
     * working tree to make an assertion about operations rather than about
     * correctness. What must hold at all times is the rest: no secret reached
     * the index, no undeclared edge type exists, and the routes came from
     * artisan rather than from the degraded reader.
     */
    #[Test]
    public function the_doctor_finds_no_secret_no_stray_edge_type_and_no_degraded_routes(): void
    {
        $report = (new Doctor(new Brain(Paths::discover(self::$root))))->run();

        $serious = array_values(array_filter(
            $report['problems'],
            static fn (array $problem) => $problem['check'] !== 'staleness'
        ));

        $this->assertSame(
            [],
            $serious,
            'doctor found problems: '.json_encode($serious, JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function the_doctor_notices_when_a_file_on_disk_is_missing_from_the_brain(): void
    {
        $report = (new Doctor(new Brain(Paths::discover(self::$root))))->run();

        // The check has to be capable of firing, or it is decoration. Either
        // the brain is current (counts agree) or the staleness check said so.
        $stale = array_values(array_filter(
            $report['problems'],
            static fn (array $problem) => $problem['check'] === 'staleness'
        ));

        if ($stale === []) {
            $this->assertSame($report['counts']['files_on_disk'], $report['counts']['files_indexed']);

            return;
        }

        $this->assertNotEmpty($stale[0]['examples']);
        $this->assertStringContainsString('brain.php update', $stale[0]['fix']);
    }
}
