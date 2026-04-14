<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\DataDictionary;
use App\Models\Organization;
use App\Models\CourseCategory;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create system administrator
        $admin = User::create([
            'username' => 'admin',
            'password_hash' => Hash::make('AdminPassword123!'),
            'full_name' => 'System Administrator',
            'email' => 'admin@localhost',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $admin->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create department manager
        $manager = User::create([
            'username' => 'manager',
            'password_hash' => Hash::make('ManagerPassword123!'),
            'full_name' => 'Department Manager',
            'email' => 'manager@localhost',
            'department_id' => 'DEPT-001',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $manager->id,
            'role' => 'manager',
            'department_scope' => 'DEPT-001',
            'is_active' => true,
        ]);

        // Create admissions advisor
        $advisor = User::create([
            'username' => 'advisor',
            'password_hash' => Hash::make('AdvisorPassword123!'),
            'full_name' => 'Admissions Advisor',
            'email' => 'advisor@localhost',
            'department_id' => 'DEPT-001',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $advisor->id,
            'role' => 'advisor',
            'department_scope' => 'DEPT-001',
            'is_active' => true,
        ]);

        // Create data steward
        $steward = User::create([
            'username' => 'steward',
            'password_hash' => Hash::make('StewardPassword123!'),
            'full_name' => 'Data Steward',
            'email' => 'steward@localhost',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $steward->id,
            'role' => 'steward',
            'is_active' => true,
        ]);

        // Create applicant
        $applicant = User::create([
            'username' => 'applicant',
            'password_hash' => Hash::make('ApplicantPassword123!'),
            'full_name' => 'Test Applicant',
            'email' => 'applicant@localhost',
            'status' => 'active',
        ]);
        UserRoleScope::create([
            'user_id' => $applicant->id,
            'role' => 'applicant',
            'is_active' => true,
        ]);

        // Seed data dictionaries
        $this->seedDictionaries();

        // Seed sample organizations
        $this->seedOrganizations();

        // Seed course categories
        $this->seedCourseCategories();
    }

    private function seedDictionaries(): void
    {
        $entries = [
            ['dictionary_type' => 'ticket_category', 'code' => 'GENERAL', 'label' => 'General Inquiry', 'sort_order' => 1],
            ['dictionary_type' => 'ticket_category', 'code' => 'ADMISSION', 'label' => 'Admission Question', 'sort_order' => 2],
            ['dictionary_type' => 'ticket_category', 'code' => 'FINANCIAL', 'label' => 'Financial Aid', 'sort_order' => 3],
            ['dictionary_type' => 'ticket_category', 'code' => 'TRANSFER', 'label' => 'Transfer Credit', 'sort_order' => 4],
            ['dictionary_type' => 'ticket_category', 'code' => 'PROGRAM', 'label' => 'Program Information', 'sort_order' => 5],
            ['dictionary_type' => 'ticket_priority', 'code' => 'Normal', 'label' => 'Normal Priority', 'sort_order' => 1],
            ['dictionary_type' => 'ticket_priority', 'code' => 'High', 'label' => 'High Priority', 'sort_order' => 2],
            ['dictionary_type' => 'org_type', 'code' => 'UNIVERSITY', 'label' => 'University', 'sort_order' => 1],
            ['dictionary_type' => 'org_type', 'code' => 'COLLEGE', 'label' => 'College', 'sort_order' => 2],
            ['dictionary_type' => 'org_type', 'code' => 'DEPARTMENT', 'label' => 'Department', 'sort_order' => 3],
            ['dictionary_type' => 'appointment_type', 'code' => 'IN_PERSON', 'label' => 'In Person', 'sort_order' => 1],
            ['dictionary_type' => 'appointment_type', 'code' => 'PHONE', 'label' => 'Phone Call', 'sort_order' => 2],
            ['dictionary_type' => 'appointment_type', 'code' => 'VIDEO', 'label' => 'Video Conference', 'sort_order' => 3],
        ];

        foreach ($entries as $entry) {
            DataDictionary::create($entry);
        }
    }

    private function seedOrganizations(): void
    {
        Organization::create([
            'code' => 'ORG-000001',
            'name' => 'Main Campus',
            'type' => 'UNIVERSITY',
            'address' => '123 University Ave',
            'phone' => '555-0100',
            'status' => 'active',
        ]);

        Organization::create([
            'code' => 'ORG-000002',
            'name' => 'Admissions Department',
            'type' => 'DEPARTMENT',
            'address' => '123 University Ave, Building A',
            'phone' => '555-0101',
            'parent_org_id' => 1,
            'status' => 'active',
        ]);
    }

    private function seedCourseCategories(): void
    {
        $parent = CourseCategory::create([
            'code' => 'STEM',
            'name' => 'Science, Technology, Engineering & Mathematics',
            'status' => 'active',
        ]);

        CourseCategory::create([
            'code' => 'CS',
            'name' => 'Computer Science',
            'parent_category_id' => $parent->id,
            'status' => 'active',
        ]);

        CourseCategory::create([
            'code' => 'BUS',
            'name' => 'Business Administration',
            'status' => 'active',
        ]);

        CourseCategory::create([
            'code' => 'ARTS',
            'name' => 'Arts & Humanities',
            'status' => 'active',
        ]);
    }
}
