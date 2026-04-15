<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\DuplicateDetectionService;
use App\Services\EncryptionService;
use App\Models\Personnel;
use App\Models\Organization;
use App\Models\DuplicateCandidate;

class DuplicateDetectionTest extends TestCase
{
    private DuplicateDetectionService $service;
    private EncryptionService $enc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DuplicateDetectionService::class);
        $this->enc = app(EncryptionService::class);
    }

    public function test_name_plus_dob_match_high_confidence(): void
    {
        $dob = $this->enc->encrypt('1990-05-15');
        Personnel::create(['full_name' => 'John Doe', 'normalized_name' => 'john doe', 'encrypted_date_of_birth' => $dob, 'status' => 'active']);
        Personnel::create(['full_name' => 'John Doe', 'normalized_name' => 'john doe', 'encrypted_date_of_birth' => $dob, 'status' => 'active']);

        $candidates = $this->service->detectPersonnelDuplicates();
        $match = collect($candidates)->first(fn ($c) => $c->detection_basis === 'normalized_name_and_dob_match');

        $this->assertNotNull($match);
        $this->assertGreaterThanOrEqual(0.95, (float) $match->confidence);
    }

    public function test_name_match_different_dob_lower_confidence(): void
    {
        Personnel::create(['full_name' => 'Jane Smith', 'normalized_name' => 'jane smith',
            'encrypted_date_of_birth' => $this->enc->encrypt('1990-01-01'), 'status' => 'active']);
        Personnel::create(['full_name' => 'Jane Smith', 'normalized_name' => 'jane smith',
            'encrypted_date_of_birth' => $this->enc->encrypt('1985-12-31'), 'status' => 'active']);

        $candidates = $this->service->detectPersonnelDuplicates();
        $match = collect($candidates)->first(fn ($c) => str_contains($c->detection_basis, 'name_match'));

        $this->assertNotNull($match);
        $this->assertLessThan(0.95, (float) $match->confidence);
    }

    public function test_unique_employee_ids_not_flagged(): void
    {
        // Different employee IDs — should not trigger employee_id duplicate
        Personnel::create(['full_name' => 'Alice A', 'normalized_name' => 'alice a', 'employee_id' => 'EMP-001', 'status' => 'active']);
        Personnel::create(['full_name' => 'Alice B', 'normalized_name' => 'alice b', 'employee_id' => 'EMP-002', 'status' => 'active']);

        $candidates = $this->service->detectPersonnelDuplicates();
        $empIdMatch = collect($candidates)->first(fn ($c) => $c->detection_basis === 'employee_id_exact_match');
        $this->assertNull($empIdMatch);
    }

    public function test_no_dob_name_only_still_detected(): void
    {
        Personnel::create(['full_name' => 'Bob X', 'normalized_name' => 'bob x', 'status' => 'active']);
        Personnel::create(['full_name' => 'Bob X', 'normalized_name' => 'bob x', 'status' => 'active']);

        $candidates = $this->service->detectPersonnelDuplicates();
        $this->assertNotEmpty($candidates);
    }

    public function test_inactive_records_not_considered(): void
    {
        Personnel::create(['full_name' => 'Retired R', 'normalized_name' => 'retired r', 'status' => 'active']);
        Personnel::create(['full_name' => 'Retired R', 'normalized_name' => 'retired r', 'status' => 'retired']);

        $candidates = $this->service->detectPersonnelDuplicates();
        $this->assertEmpty($candidates);
    }

    public function test_organization_duplicate_detection(): void
    {
        Organization::create(['code' => 'ORG-000100', 'name' => 'Test U', 'normalized_name' => 'test u', 'status' => 'active']);
        Organization::create(['code' => 'ORG-000101', 'name' => 'Test U', 'normalized_name' => 'test u', 'status' => 'active']);

        $candidates = $this->service->detectOrganizationDuplicates();
        $this->assertNotEmpty($candidates);
        $this->assertEquals('organization', $candidates[0]->entity_type);
    }

    public function test_detection_is_idempotent(): void
    {
        Personnel::create(['full_name' => 'Idem A', 'normalized_name' => 'idem a', 'status' => 'active']);
        Personnel::create(['full_name' => 'Idem A', 'normalized_name' => 'idem a', 'status' => 'active']);

        $this->service->detectPersonnelDuplicates();
        $this->service->detectPersonnelDuplicates();

        $this->assertEquals(1, DuplicateCandidate::where('entity_type', 'personnel')->count());
    }
}
