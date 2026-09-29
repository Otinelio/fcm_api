<?php

namespace Tests\Unit;

use App\Support\StorageUrlResolver;
use Illuminate\Http\Request;
use Tests\TestCase;

class StorageUrlResolverTest extends TestCase
{
    public function test_resolves_null_or_empty(): void
    {
        $this->assertNull(StorageUrlResolver::resolve(null));
        $this->assertNull(StorageUrlResolver::resolve(''));
        $this->assertNull(StorageUrlResolver::resolve('   '));
    }

    public function test_preserves_external_third_party_urls(): void
    {
        $googleUrl = 'https://lh3.googleusercontent.com/a/ACg8ocKd7Dv5yw38MpfabrL8n4NCknMqzHFVUpgUTcavCK1j5ZlvBxg=s96-c';
        $this->assertEquals($googleUrl, StorageUrlResolver::resolve($googleUrl));

        $externalCdn = 'https://cdn.example.com/images/pic.png';
        $this->assertEquals($externalCdn, StorageUrlResolver::resolve($externalCdn));
    }

    public function test_dynamically_rewrites_old_ngrok_url_to_current_request_host(): void
    {
        $request = Request::create('http://192.168.1.83/admin/restaurants');
        app()->instance('request', $request);

        $oldNgrok = 'http://distract-buffalo-mockup.ngrok-free.dev/storage/logos/7fa6974b-f3e6-4129-a187-11e50b1ea44a.jpg?v=1788361755';
        $resolved = StorageUrlResolver::resolve($oldNgrok);

        $this->assertEquals('http://192.168.1.83/storage/logos/7fa6974b-f3e6-4129-a187-11e50b1ea44a.jpg?v=1788361755', $resolved);
    }

    public function test_dynamically_rewrites_localhost_url_to_current_request_host(): void
    {
        $request = Request::create('http://192.168.1.83/admin/campaigns');
        app()->instance('request', $request);

        $oldLocalhost = 'http://localhost/storage/campaigns/d83d6gjGXHVOEGzz6vLWzEvCmU3Hw7So0FqxULBf.jpg';
        $resolved = StorageUrlResolver::resolve($oldLocalhost);

        $this->assertEquals('http://192.168.1.83/storage/campaigns/d83d6gjGXHVOEGzz6vLWzEvCmU3Hw7So0FqxULBf.jpg', $resolved);
    }

    public function test_resolves_relative_storage_path(): void
    {
        $request = Request::create('https://mivafid.com/admin');
        app()->instance('request', $request);

        $relative = 'advertisements/promo.webp';
        $resolved = StorageUrlResolver::resolve($relative);

        $this->assertEquals('https://mivafid.com/storage/advertisements/promo.webp', $resolved);
    }
}
