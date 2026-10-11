<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

class TrustedProxyHttpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_loopback_proxy_makes_forwarded_https_authoritative_for_guest_navigation(): void
    {
        $response = $this->withServerVariables($this->forwardedHeaders())
            ->get('http://127.0.0.1/dashboard');

        $response->assertRedirect('https://tunnel.example.test/login');
        $response->assertSessionHas('url.intended', 'https://tunnel.example.test/dashboard');
        $this->assertTrue(app('request')->isSecure());
        $this->assertSame('https://tunnel.example.test/dashboard', app('request')->fullUrl());
        $this->assertContains('127.0.0.0/8', SymfonyRequest::getTrustedProxies());
        $this->assertContains('::1', SymfonyRequest::getTrustedProxies());
    }

    public function test_login_reuses_a_forwarded_https_intended_url(): void
    {
        $user = User::factory()->create();

        $this->withServerVariables($this->forwardedHeaders())
            ->get('http://127.0.0.1/dashboard')
            ->assertRedirect('https://tunnel.example.test/login')
            ->assertSessionHas('url.intended', 'https://tunnel.example.test/dashboard');

        $this->withServerVariables($this->forwardedHeaders())
            ->post('http://127.0.0.1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('https://tunnel.example.test/dashboard');
    }

    public function test_login_upgrades_a_same_host_http_intended_url_left_by_the_old_proxy_configuration(): void
    {
        $user = User::factory()->create();

        $this->withSession(['url.intended' => 'http://tunnel.example.test/collaboration/classes/35'])
            ->withServerVariables($this->forwardedHeaders())
            ->post('http://127.0.0.1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('https://tunnel.example.test/collaboration/classes/35');
    }

    public function test_login_discards_a_foreign_intended_url_instead_of_redirecting_off_site(): void
    {
        $user = User::factory()->create();

        $this->withSession(['url.intended' => 'https://attacker.example.test/redirect'])
            ->withServerVariables($this->forwardedHeaders())
            ->post('http://127.0.0.1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('https://tunnel.example.test/dashboard');
    }

    public function test_direct_http_without_forwarded_headers_remains_http(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.17',
            'HTTP_HOST' => 'plain.example.test',
        ])->get('http://plain.example.test/dashboard');

        $response->assertRedirect('http://plain.example.test/login');
        $response->assertSessionHas('url.intended', 'http://plain.example.test/dashboard');
        $this->assertFalse(app('request')->isSecure());
        $this->assertSame('http://plain.example.test/dashboard', app('request')->fullUrl());
    }

    public function test_direct_http_login_does_not_upgrade_its_same_host_intended_url(): void
    {
        $user = User::factory()->create();
        $directHttp = [
            'REMOTE_ADDR' => '198.51.100.17',
            'HTTP_HOST' => 'plain.example.test',
        ];

        $this->withSession(['url.intended' => 'http://plain.example.test/calendar'])
            ->withServerVariables($directHttp)
            ->post('http://plain.example.test/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('http://plain.example.test/calendar');
    }

    public function test_untrusted_remote_cannot_spoof_the_forwarded_scheme_or_host(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.17',
            'HTTP_HOST' => 'plain.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example.test',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('http://plain.example.test/dashboard');

        $response->assertRedirect('http://plain.example.test/login');
        $response->assertSessionHas('url.intended', 'http://plain.example.test/dashboard');
        $this->assertFalse(app('request')->isSecure());
        $this->assertSame('plain.example.test', app('request')->getHost());
    }

    /** @return array<string, string> */
    private function forwardedHeaders(): array
    {
        return [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'tunnel.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'tunnel.example.test',
            'HTTP_X_FORWARDED_PORT' => '443',
        ];
    }
}
