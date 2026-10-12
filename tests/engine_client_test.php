<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace search_elastic;

use advanced_testcase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for engine HTTP client reuse without an Elasticsearch server.
 *
 * @package     search_elastic
 * @copyright   2026 Praxis Digital
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \search_elastic\engine
 */
final class engine_client_test extends advanced_testcase {
    /**
     * The normal client is retained per engine; custom handlers remain isolated.
     */
    public function test_client_reuse(): void {
        $this->resetAfterTest();
        $engine = new engine();
        $method = new \ReflectionMethod(engine::class, 'get_client');
        $method->setAccessible(true);

        $client = $method->invoke($engine);
        $this->assertSame($client, $method->invoke($engine));
        $this->assertNotSame($client, $method->invoke(new engine()));

        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $customclient = $method->invoke($engine, $stack);
        $this->assertNotSame($client, $customclient);
        $this->assertSame(200, $customclient->get('http://localhost:9200')->getStatusCode());
        $this->assertSame($client, $method->invoke($engine));
    }

    /**
     * Health checks keep their own handler and timeout without replacing the normal client.
     */
    public function test_health_check_isolation(): void {
        $this->resetAfterTest();
        set_config('hostname', 'http://localhost', 'search_elastic');
        set_config('port', 9200, 'search_elastic');
        set_config('timeout', 60, 'search_elastic');

        $normalstack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"version":{"number":"8.5.3"}}'),
        ]));
        $normalhistory = [];
        $normalstack->push(Middleware::history($normalhistory));
        $client = new esrequest($normalstack);
        $engine = new engine();
        $property = new \ReflectionProperty(engine::class, 'client');
        $property->setAccessible(true);
        $property->setValue($engine, $client);

        $checkstack = HandlerStack::create(new MockHandler([new Response(200)]));
        $checkhistory = [];
        $checkstack->push(Middleware::history($checkhistory));
        $this->assertSame(200, $engine->get_server_status_code($checkstack));
        $this->assertSame(5, $checkhistory[0]['options']['timeout']);
        $this->assertCount(0, $normalhistory);
        $this->assertSame($client, $property->getValue($engine));

        $this->assertSame('8.5.3', $engine->get_es_version());
        $this->assertSame(60, $normalhistory[0]['options']['timeout']);
    }

    /**
     * Version lookups, successive bulk writes and single writes use the retained client.
     */
    public function test_indexing_uses_shared_client(): void {
        $this->resetAfterTest();
        set_config('hostname', 'http://localhost', 'search_elastic');
        set_config('port', 9200, 'search_elastic');
        set_config('index', 'test_index', 'search_elastic');
        set_config('sendsize', 1, 'search_elastic');

        $mock = new MockHandler([
            new Response(200, [], '{"version":{"lucene_version":"10.3.2"}}'),
            new Response(200, [], '{"errors":false}'),
            new Response(200, [], '{"errors":false}'),
            new Response(201, [], '{}'),
        ]);
        $stack = HandlerStack::create($mock);
        $history = [];
        $stack->push(Middleware::history($history));
        $client = new esrequest($stack);
        $engine = new engine();
        $property = new \ReflectionProperty(engine::class, 'client');
        $property->setAccessible(true);
        $property->setValue($engine, $client);

        $this->assertSame(10, $engine->get_es_lucene_version());

        $method = new \ReflectionMethod(engine::class, 'batch_add_documents');
        $method->setAccessible(true);
        foreach (['first', 'second'] as $id) {
            $payload = json_encode(['index' => ['_id' => $id]]) . "\n" .
                json_encode(['id' => $id, 'content' => 'Test document']) . "\n";
            $this->assertSame(0, $method->invoke($engine, $payload, true));
        }

        $this->assertTrue($engine->index_single_document(['id' => 'third', 'content' => 'Test document']));
        $this->assertCount(4, $history);
        $this->assertSame('/test_index/_bulk', $history[1]['request']->getUri()->getPath());
        $this->assertSame('/test_index/_bulk', $history[2]['request']->getUri()->getPath());
        $this->assertSame('/test_index/_doc/third', $history[3]['request']->getUri()->getPath());
        $this->assertSame($client, $property->getValue($engine));
    }
}
