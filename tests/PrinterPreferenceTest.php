<?php

namespace Bitdreamit\QzTray\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrinterPreferenceTest extends TestCase
{
    protected string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceId = (string) Str::uuid();
    }

    public function test_set_printer_stores_device_scoped_preference(): void
    {
        $response = $this->withHeader('X-Device-Id', $this->deviceId)
            ->postJson('/qz/printer', [
                'printer' => 'Zebra ZD421',
                'path'    => '/labels/print',
                'device_id' => $this->deviceId,
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('qz_printer_preferences', [
            'identity_type'  => 'device',
            'identity_value' => $this->deviceId,
            'path'           => '/labels/print',
            'printer_name'   => 'Zebra ZD421',
        ]);
    }

    public function test_get_printer_via_query_param_route(): void
    {
        // AUDIT H3: the ?path= route must behave like the segment route.
        $this->withHeader('X-Device-Id', $this->deviceId)
            ->postJson('/qz/printer', [
                'printer'   => 'Epson TM-T88',
                'path'      => '/pos/receipt',
                'device_id' => $this->deviceId,
            ])->assertOk();

        $this->withHeader('X-Device-Id', $this->deviceId)
            ->getJson('/qz/printer?path='.urlencode('/pos/receipt'))
            ->assertOk()
            ->assertJsonPath('printer', 'Epson TM-T88')
            ->assertJsonPath('scoped_to', 'device');
    }

    public function test_get_printer_falls_back_to_default_printer(): void
    {
        config(['qz-tray.default_printer' => 'Fallback Printer']);

        $this->withHeader('X-Device-Id', $this->deviceId)
            ->getJson('/qz/printer?path='.urlencode('/never-seen'))
            ->assertOk()
            ->assertJsonPath('printer', 'Fallback Printer')
            ->assertJsonPath('scoped_to', null);
    }

    public function test_preferences_isolated_between_devices(): void
    {
        $otherDevice = (string) Str::uuid();

        $this->withHeader('X-Device-Id', $this->deviceId)
            ->postJson('/qz/printer', [
                'printer'   => 'Device A Printer',
                'path'      => '/page',
                'device_id' => $this->deviceId,
            ])->assertOk();

        // Device B must NOT see Device A's choice (BUG-19 regression guard).
        $this->withHeader('X-Device-Id', $otherDevice)
            ->getJson('/qz/printer?path='.urlencode('/page'))
            ->assertOk()
            ->assertJsonPath('printer', null)
            ->assertJsonPath('scoped_to', null);
    }

    public function test_tenant_id_out_of_bigint_range_is_rejected(): void
    {
        config(['qz-tray.id_type' => 'bigint']);

        // AUDIT M4: 21 digits exceeds unsigned bigint — must be a 422, not
        // a 500 from the database layer.
        $response = $this->withHeader('X-Device-Id', $this->deviceId)
            ->postJson('/qz/printer', [
                'printer'   => 'P',
                'path'      => '/p',
                'device_id' => $this->deviceId,
                'tenant_id' => '99999999999999999999',
            ]);

        $response->assertStatus(422);
    }

    public function test_missing_migrations_degrade_gracefully(): void
    {
        // AUDIT H4: drop the table and confirm a clean 503 JSON instead of
        // a raw QueryException.
        DB::statement('DROP TABLE qz_printer_preferences');

        $this->withHeader('X-Device-Id', $this->deviceId)
            ->postJson('/qz/printer', ['printer' => 'P', 'path' => '/p', 'device_id' => $this->deviceId])
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }
}
