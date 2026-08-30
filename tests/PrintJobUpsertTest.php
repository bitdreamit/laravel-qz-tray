<?php

namespace Bitdreamit\QzTray\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrintJobUpsertTest extends TestCase
{
    protected string $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceId = (string) Str::uuid();
    }

    protected function printPayload(array $overrides = []): array
    {
        return array_merge([
            'printer'   => 'HP LaserJet',
            'type'      => 'pdf',
            'url'       => 'https://example.test/invoice-1.pdf',
            'job_id'    => (string) Str::uuid(),
            'device_id' => $this->deviceId,
            'copies'    => 2,
        ], $overrides);
    }

    public function test_print_creates_job_row(): void
    {
        $payload = $this->printPayload();

        $response = $this->postJson('/qz/print', $payload);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('db_logged', true);

        $this->assertDatabaseHas('qz_print_jobs', [
            'id'      => $payload['job_id'],
            'status'  => 'pending',
            'copies'  => 2,
        ]);
    }

    public function test_replaying_same_job_id_updates_instead_of_duplicating(): void
    {
        $payload = $this->printPayload();

        $this->postJson('/qz/print', $payload)->assertOk();
        // The client reports 'completed' with the SAME job id (AUDIT C2).
        $this->postJson('/qz/print', array_merge($payload, ['status' => 'completed']))->assertOk();

        $rows = DB::table('qz_print_jobs')->where('id', $payload['job_id'])->get();

        $this->assertCount(1, $rows, 'Duplicate reports must not create a second row');
        $this->assertSame('completed', $rows[0]->status);
        $this->assertNotNull($rows[0]->processed_at, 'Terminal status must stamp processed_at');
    }

    public function test_replaying_job_id_does_not_move_processed_at_backwards(): void
    {
        $payload = $this->printPayload();

        $this->postJson('/qz/print', $payload)->assertOk();
        $this->postJson('/qz/print', array_merge($payload, ['status' => 'completed']))->assertOk();
        $firstStamp = DB::table('qz_print_jobs')->where('id', $payload['job_id'])->value('processed_at');

        $this->postJson('/qz/print', array_merge($payload, ['status' => 'processing']))->assertOk();

        $this->assertSame(
            $firstStamp,
            DB::table('qz_print_jobs')->where('id', $payload['job_id'])->value('processed_at'),
            'processed_at must never regress once set'
        );
    }

    public function test_status_patch_updates_job(): void
    {
        $payload = $this->printPayload();
        $this->postJson('/qz/print', $payload)->assertOk();

        $response = $this->patchJson("/qz/jobs/{$payload['job_id']}", [
            'status'     => 'completed',
            'device_id'  => $this->deviceId,
        ]);

        $response->assertOk()->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('qz_print_jobs', [
            'id'     => $payload['job_id'],
            'status' => 'completed',
        ]);
    }

    public function test_status_patch_rejects_jobs_not_owned_by_requester(): void
    {
        $payload = $this->printPayload();
        $this->postJson('/qz/print', $payload)->assertOk();

        // A different, unknown device tries to complete the job (AUDIT C4).
        $response = $this->withHeader('X-Device-Id', (string) Str::uuid())
            ->patchJson("/qz/jobs/{$payload['job_id']}", ['status' => 'completed']);

        $response->assertNotFound();

        $this->assertDatabaseHas('qz_print_jobs', [
            'id'     => $payload['job_id'],
            'status' => 'pending',
        ]);
    }

    public function test_invalid_device_id_header_does_not_break_logging(): void
    {
        // AUDIT H5: garbage in the X-Device-Id header must not crash the
        // insert into the uuid-typed device_id column.
        $payload = $this->printPayload();
        unset($payload['device_id']);

        $response = $this->withHeader('X-Device-Id', 'not-a-uuid-!!')
            ->postJson('/qz/print', $payload);

        $response->assertOk()->assertJsonPath('db_logged', true);

        $this->assertDatabaseHas('qz_print_jobs', [
            'id'        => $payload['job_id'],
            'device_id' => null,
        ]);
    }

    public function test_cancel_scopes_to_requesting_device(): void
    {
        $payload = $this->printPayload();
        $this->postJson('/qz/print', $payload)->assertOk();

        // A foreign device cannot cancel (AUDIT C4)...
        $this->withHeader('X-Device-Id', (string) Str::uuid())
            ->deleteJson("/qz/jobs/{$payload['job_id']}")
            ->assertNotFound();

        // ...but the owning device can.
        $this->withHeader('X-Device-Id', $this->deviceId)
            ->deleteJson("/qz/jobs/{$payload['job_id']}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('qz_print_jobs', [
            'id'     => $payload['job_id'],
            'status' => 'cancelled',
        ]);
    }
}
