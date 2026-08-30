<?php

namespace Bitdreamit\QzTray\Tests;

use Illuminate\Support\Facades\Route;

class PackageBootsTest extends TestCase
{
    public function test_config_merges_with_sane_defaults(): void
    {
        $this->assertSame('qz', config('qz-tray.routes.prefix'));
        $this->assertSame('uuid', config('qz-tray.id_type'));
        $this->assertSame('v7', config('qz-tray.uuid_version'));
        $this->assertFalse(config('qz-tray.allow_public_cert_generate'));
    }

    public function test_web_routes_are_registered(): void
    {
        foreach (['qz.certificate', 'qz.sign', 'qz.print', 'qz.jobs', 'qz.jobs.status', 'qz.printer.get', 'qz.printer.get.query'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] should be registered");
        }
    }

    public function test_api_routes_are_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('qz-tray.routes.api.enabled'));
        $this->assertFalse(Route::has('api.qz.print'));
    }

    public function test_api_routes_register_when_enabled(): void
    {
        config(['qz-tray.routes.api.enabled' => true]);

        // The route file itself re-checks the flag, so it must be (re)loaded
        // with the new value for the guard to pass.
        require_once __DIR__.'/../routes/api.php';

        $this->assertTrue(Route::has('api.qz.print'));
    }

    public function test_provider_version_constant_is_semantic(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            QzTrayServiceProvider::VERSION
        );
    }
}
