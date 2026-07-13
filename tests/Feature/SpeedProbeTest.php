<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpeedProbeTest extends TestCase
{
    public function test_speed_probe_returns_200(): void
    {
        $this->get('/api/speed-probe')->assertStatus(200);
    }

    public function test_speed_probe_returns_exactly_64kb(): void
    {
        $response = $this->get('/api/speed-probe');
        $this->assertEquals(65536, strlen($response->content()));
    }

    public function test_speed_probe_has_no_cache_headers(): void
    {
        $response = $this->get('/api/speed-probe');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
    }

    public function test_speed_probe_has_octet_stream_content_type(): void
    {
        $response = $this->get('/api/speed-probe');
        $this->assertStringContainsString('application/octet-stream', $response->headers->get('Content-Type'));
    }

    public function test_speed_probe_has_correct_content_length_header(): void
    {
        $response = $this->get('/api/speed-probe');
        $this->assertEquals('65536', $response->headers->get('Content-Length'));
    }

    public function test_speed_probe_requires_no_authentication(): void
    {
        // Must be reachable without a Bearer token — unauthenticated request must return 200.
        $response = $this->get('/api/speed-probe');
        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
        $response->assertStatus(200);
    }
}
