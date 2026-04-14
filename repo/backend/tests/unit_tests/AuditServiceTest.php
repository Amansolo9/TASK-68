<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\AuditService;
use App\Models\AuditLog;

class AuditServiceTest extends TestCase
{
    private AuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AuditService::class);
    }

    public function test_log_creates_audit_entry(): void
    {
        $entry = $this->service->log(
            'user', '1', 'login',
            1, 'admin', '127.0.0.1',
            null, null, ['key' => 'value']
        );

        $this->assertNotNull($entry->id);
        $this->assertEquals('user', $entry->entity_type);
        $this->assertEquals('1', $entry->entity_id);
        $this->assertEquals('login', $entry->event_type);
        $this->assertEquals(1, $entry->actor_user_id);
        $this->assertNotEmpty($entry->chain_hash);
    }

    public function test_chain_hash_changes_with_each_entry(): void
    {
        $entry1 = $this->service->log('user', '1', 'login', 1, 'admin', '127.0.0.1');
        $entry2 = $this->service->log('user', '2', 'login', 2, 'admin', '127.0.0.2');

        $this->assertNotEquals($entry1->chain_hash, $entry2->chain_hash);
    }

    public function test_audit_entries_are_immutable(): void
    {
        $entry = $this->service->log('user', '1', 'login', 1, 'admin', '127.0.0.1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $entry->update(['event_type' => 'tampered']);
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $entry = $this->service->log('user', '1', 'login', 1, 'admin', '127.0.0.1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $entry->delete();
    }

    public function test_verify_chain_returns_valid_for_intact_chain(): void
    {
        $this->service->log('user', '1', 'event1', 1, 'admin', '127.0.0.1');
        $this->service->log('user', '2', 'event2', 2, 'admin', '127.0.0.1');
        $this->service->log('user', '3', 'event3', 3, 'admin', '127.0.0.1');

        $result = $this->service->verifyChain();

        $this->assertTrue($result['valid']);
        $this->assertEquals(3, $result['checked']);
        $this->assertNull($result['broken_at']);
    }

    public function test_compute_entity_hash_is_deterministic(): void
    {
        $data = ['name' => 'Test', 'value' => 123];
        $hash1 = $this->service->computeEntityHash($data);
        $hash2 = $this->service->computeEntityHash($data);

        $this->assertEquals($hash1, $hash2);
    }

    public function test_before_and_after_hashes_are_recorded(): void
    {
        $entry = $this->service->log(
            'organization', '5', 'updated',
            1, 'steward', '127.0.0.1',
            'abc123beforehash', 'def456afterhash'
        );

        $this->assertEquals('abc123beforehash', $entry->before_hash);
        $this->assertEquals('def456afterhash', $entry->after_hash);
    }
}
