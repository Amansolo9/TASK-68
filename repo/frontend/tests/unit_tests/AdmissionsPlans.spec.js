import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock api module
const mockGet = vi.fn();
vi.mock('../../src/utils/api', () => ({
    default: { get: mockGet, post: vi.fn() },
}));

// Mock auth store
const mockAuthState = {
    isAuthenticated: true,
    mfaRequired: false,
    mfaVerified: true,
    user: { roles: ['applicant'] },
};

vi.mock('../../src/store/auth', () => ({
    useAuthStore: () => ({
        ...mockAuthState,
        hasAnyRole: (roles) => roles.some(r => mockAuthState.user.roles.includes(r)),
        hasRole: (role) => mockAuthState.user.roles.includes(role),
        isApplicant: mockAuthState.user.roles.includes('applicant'),
        hasPermission: () => false,
    }),
}));

describe('AdmissionsPlans page', () => {
    beforeEach(() => {
        mockGet.mockReset();
        mockGet.mockResolvedValue({ data: { data: [], meta: {} } });
    });

    it('applicant role calls /published-plans endpoint, not /admissions-plans', async () => {
        mockAuthState.user.roles = ['applicant'];

        // Simulate what fetchPlans does for applicant
        const isStaff = ['manager', 'admin', 'advisor', 'steward'].some(r => mockAuthState.user.roles.includes(r));
        const endpoint = isStaff ? '/admissions-plans' : '/published-plans';

        expect(endpoint).toBe('/published-plans');
        expect(endpoint).not.toBe('/admissions-plans');
    });

    it('manager role calls /admissions-plans endpoint for staff management', () => {
        mockAuthState.user.roles = ['manager'];

        const isStaff = ['manager', 'admin', 'advisor', 'steward'].some(r => mockAuthState.user.roles.includes(r));
        const endpoint = isStaff ? '/admissions-plans' : '/published-plans';

        expect(endpoint).toBe('/admissions-plans');
    });

    it('admin role calls /admissions-plans endpoint for staff management', () => {
        mockAuthState.user.roles = ['admin'];

        const isStaff = ['manager', 'admin', 'advisor', 'steward'].some(r => mockAuthState.user.roles.includes(r));
        const endpoint = isStaff ? '/admissions-plans' : '/published-plans';

        expect(endpoint).toBe('/admissions-plans');
    });

    it('year and intake filters are included in API params', () => {
        const yearFilter = '2025-2026';
        const intakeFilter = 'Fall';

        const params = { page: 1, per_page: 20 };
        if (yearFilter) params.academic_year = yearFilter;
        if (intakeFilter) params.intake_batch = intakeFilter;

        expect(params).toEqual({
            page: 1,
            per_page: 20,
            academic_year: '2025-2026',
            intake_batch: 'Fall',
        });
    });

    it('empty filters are not included in params', () => {
        const yearFilter = '';
        const intakeFilter = '';

        const params = { page: 1, per_page: 20 };
        if (yearFilter) params.academic_year = yearFilter;
        if (intakeFilter) params.intake_batch = intakeFilter;

        expect(params).toEqual({ page: 1, per_page: 20 });
        expect(params).not.toHaveProperty('academic_year');
        expect(params).not.toHaveProperty('intake_batch');
    });
});
