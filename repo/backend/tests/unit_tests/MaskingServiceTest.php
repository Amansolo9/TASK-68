<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\MaskingService;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Support\Facades\Hash;

class MaskingServiceTest extends TestCase
{
    private MaskingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MaskingService::class);
    }

    public function test_mask_date_of_birth_for_unauthorized_user(): void
    {
        $user = User::create([
            'username' => 'applicant1',
            'password_hash' => Hash::make('password'),
            'full_name' => 'Test',
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'applicant', 'is_active' => true]);

        $result = $this->service->mask('date_of_birth', '1990-05-15', $user);

        $this->assertEquals('**/**/****', $result);
    }

    public function test_mask_government_id_shows_last_four(): void
    {
        $user = User::create([
            'username' => 'applicant2',
            'password_hash' => Hash::make('password'),
            'full_name' => 'Test',
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $user->id, 'role' => 'applicant', 'is_active' => true]);

        $result = $this->service->mask('government_id', '123-45-6789', $user);

        $this->assertEquals('***-**-6789', $result);
    }

    public function test_admin_with_permission_sees_unmasked(): void
    {
        $admin = User::create([
            'username' => 'admin1',
            'password_hash' => Hash::make('password'),
            'full_name' => 'Admin',
            'status' => 'active',
        ]);
        UserRoleScope::create(['user_id' => $admin->id, 'role' => 'admin', 'is_active' => true]);

        $result = $this->service->mask('date_of_birth', '1990-05-15', $admin);

        // Admin has attachments.view_sensitive permission
        $this->assertEquals('1990-05-15', $result);
    }

    public function test_mask_null_returns_empty(): void
    {
        $result = $this->service->mask('date_of_birth', null);
        $this->assertEquals('', $result);
    }

    public function test_mask_without_user_returns_masked(): void
    {
        $result = $this->service->mask('date_of_birth', '1990-05-15');
        $this->assertEquals('**/**/****', $result);
    }
}
