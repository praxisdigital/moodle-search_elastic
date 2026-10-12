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
use GuzzleHttp\Psr7\Response;

/**
 * Apache Lucene version detection tests.
 *
 * These run against a mocked service response, so they do not need a test server.
 *
 * @package     search_elastic
 * @copyright   2026 Praxis Digital
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \search_elastic\engine::get_es_lucene_version
 */
final class lucene_version_test extends advanced_testcase {
    /**
     * Lucene version strings as reported by the service, and the expected major version.
     *
     * @return array
     */
    public static function lucene_version_provider(): array {
        return [
            'Elasticsearch 6 (Lucene 7)' => ['7.7.3', 7, true],
            'Elasticsearch 7 (Lucene 8)' => ['8.11.1', 8, false],
            'OpenSearch 2 (Lucene 9)' => ['9.12.1', 9, false],
            // Issue #167: "10.3.2" < 8 is a string comparison in PHP, and is true.
            'OpenSearch 3.5 (Lucene 10)' => ['10.3.2', 10, false],
            'Two-part version' => ['10.3', 10, false],
        ];
    }

    /**
     * Test the Lucene version is returned as a comparable major version.
     *
     * @param string $reported The lucene_version string returned by the service.
     * @param int $expected The expected major version.
     * @param bool $legacymapping Whether the legacy (Lucene < 8) mapping should apply.
     * @dataProvider lucene_version_provider
     */
    public function test_get_es_lucene_version(string $reported, int $expected, bool $legacymapping): void {
        $this->resetAfterTest();
        set_config('hostname', 'http://localhost', 'search_elastic');

        $body = json_encode(['version' => ['number' => '1.0.0', 'lucene_version' => $reported]]);
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $body),
        ]));

        $engine = new engine();
        $version = $engine->get_es_lucene_version($stack);

        $this->assertSame($legacymapping, $version < 8);
        $this->assertSame($expected, $version);

        // The mock queue is empty: another HTTP request would fail.
        $this->assertSame($expected, $engine->get_es_lucene_version($stack));
    }

    /**
     * Responses that must not populate the Lucene version cache.
     *
     * @return array
     */
    public static function invalid_version_provider(): array {
        return [
            'HTTP error' => [503, '{"error":"Service unavailable"}'],
            'HTTP error with version' => [500, '{"version":{"lucene_version":"10.3.2"}}'],
            'Invalid JSON' => [200, '<html>Bad gateway</html>'],
            'Missing version' => [200, '{}'],
            'Invalid version object' => [200, '{"version":"10.3.2"}'],
            'Missing Lucene version' => [200, '{"version":{}}'],
            'Null Lucene version' => [200, '{"version":{"lucene_version":null}}'],
            'Non-string Lucene version' => [200, '{"version":{"lucene_version":true}}'],
            'Empty Lucene version' => [200, '{"version":{"lucene_version":""}}'],
            'Non-numeric Lucene version' => [200, '{"version":{"lucene_version":"unknown"}}'],
            'Malformed Lucene version' => [200, '{"version":{"lucene_version":"10.invalid"}}'],
            'Zero Lucene version' => [200, '{"version":{"lucene_version":"0.0.0"}}'],
        ];
    }

    /**
     * Failed lookups leave the cache empty so the next call retries.
     *
     * @param int $status HTTP status of the failed lookup.
     * @param string $body Response body of the failed lookup.
     * @dataProvider invalid_version_provider
     */
    public function test_failed_lookup_is_not_cached(int $status, string $body): void {
        $this->resetAfterTest();
        set_config('hostname', 'http://localhost', 'search_elastic');

        $mock = new MockHandler([
            new Response($status, [], $body),
            new Response(200, [], '{"version":{"lucene_version":"10.3.2"}}'),
        ]);
        $stack = HandlerStack::create($mock);
        $engine = new engine();

        $exception = null;
        try {
            $engine->get_es_lucene_version($stack);
        } catch (\RuntimeException $e) {
            $exception = $e;
        }
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertCount(1, $mock);

        $this->assertSame(10, $engine->get_es_lucene_version($stack));
        $this->assertCount(0, $mock);
        // A successful retry is cached: another HTTP request would exhaust the mock queue.
        $this->assertSame(10, $engine->get_es_lucene_version($stack));
    }
}
