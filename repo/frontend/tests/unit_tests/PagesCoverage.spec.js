import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

// ═══════════════════════════════════════════════════════
// Shared mocks — API, router, auth store
// ═══════════════════════════════════════════════════════

vi.mock('../../src/utils/api', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: [], meta: { pagination: { current_page: 1, last_page: 1 } } } }),
        post: vi.fn().mockResolvedValue({ data: { data: {} } }),
        put: vi.fn().mockResolvedValue({ data: { data: {} } }),
        delete: vi.fn().mockResolvedValue({ data: { data: {} } }),
    },
}));

vi.mock('vue-router', () => ({
    useRouter: () => ({ push: vi.fn() }),
    useRoute: () => ({ params: { id: '1' }, name: 'test' }),
    createRouter: vi.fn(() => ({ beforeEach: vi.fn(), install: vi.fn() })),
    createWebHistory: vi.fn(),
    RouterLink: { template: '<a><slot/></a>' },
}));

// Shared mutable auth state — mutate .current, don't reassign the object
const authState = {
    current: {
        isAuthenticated: true, mfaVerified: true,
        user: { full_name: 'Admin User', roles: ['admin'] }, roles: ['admin'],
        hasAnyRole: (r) => r.some(x => authState.current.roles.includes(x)),
        hasRole: (r) => authState.current.roles.includes(r),
        isAdmin: true, isApplicant: false,
        sessionInfo: { expires_at: '2026-12-31T00:00:00Z' },
    },
};

vi.mock('../../src/store/auth', () => ({
    useAuthStore: () => authState.current,
}));

import api from '../../src/utils/api';
const mockGet = api.get;

const ADMIN_AUTH = {
    isAuthenticated: true, mfaVerified: true,
    user: { full_name: 'Admin User', roles: ['admin'] }, roles: ['admin'],
    hasAnyRole: (r) => r.some(x => ['admin'].includes(x)),
    hasRole: (r) => ['admin'].includes(r),
    isAdmin: true, isApplicant: false,
    sessionInfo: { expires_at: '2026-12-31T00:00:00Z' },
};

const STEWARD_AUTH = {
    isAuthenticated: true, mfaVerified: true,
    user: { full_name: 'Steward User', roles: ['steward'] }, roles: ['steward'],
    hasAnyRole: (r) => r.some(x => ['steward'].includes(x)),
    hasRole: (r) => ['steward'].includes(r),
    isAdmin: false, isApplicant: false,
    sessionInfo: { expires_at: '2026-12-31T00:00:00Z' },
};

const globalStubs = { global: { stubs: { RouterLink: true } } };

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    authState.current = ADMIN_AUTH;
    mockGet.mockResolvedValue({
        data: { data: [], meta: { pagination: { current_page: 1, last_page: 1 } } },
    });
});

// ═══════════════════════════════════════════════════════
// AuditLogs — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import AuditLogs from '../../src/pages/AuditLogs.vue';

describe('AuditLogs page (mounted)', () => {
    it('renders the Audit Logs header', () => {
        const wrapper = mount(AuditLogs, globalStubs);
        expect(wrapper.text()).toContain('Audit Logs');
    });

    it('calls /audit-logs on mount', async () => {
        mount(AuditLogs, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/audit-logs'), expect.anything());
    });

    it('renders chain verify button', () => {
        const wrapper = mount(AuditLogs, globalStubs);
        expect(wrapper.find('button').text()).toContain('Verify Chain');
    });

    it('renders filter selects for entity and event type', () => {
        const wrapper = mount(AuditLogs, globalStubs);
        const selects = wrapper.findAll('select');
        expect(selects.length).toBeGreaterThanOrEqual(2);
    });

    it('displays table with correct headers', () => {
        const wrapper = mount(AuditLogs, globalStubs);
        const headers = wrapper.findAll('th').map(th => th.text());
        expect(headers).toEqual(expect.arrayContaining(['Time', 'Actor', 'Entity', 'Event']));
    });
});

// ═══════════════════════════════════════════════════════
// Organizations — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import Organizations from '../../src/pages/Organizations.vue';

describe('Organizations page (mounted)', () => {
    it('renders the Organizations header', () => {
        const wrapper = mount(Organizations, globalStubs);
        expect(wrapper.text()).toContain('Organizations');
    });

    it('calls /organizations on mount', async () => {
        mount(Organizations, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/organizations'), expect.anything());
    });

    it('renders search input', () => {
        const wrapper = mount(Organizations, globalStubs);
        expect(wrapper.find('input[placeholder*="Search"]').exists()).toBe(true);
    });

    it('renders table with Code and Name columns', () => {
        const wrapper = mount(Organizations, globalStubs);
        const headers = wrapper.findAll('th').map(th => th.text());
        expect(headers).toEqual(expect.arrayContaining(['Code', 'Name']));
    });

    it('shows Add Organization button for steward', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(Organizations, globalStubs);
        expect(wrapper.text()).toContain('Add Organization');
    });
});

// ═══════════════════════════════════════════════════════
// Users — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import Users from '../../src/pages/Users.vue';

describe('Users page (mounted)', () => {
    it('renders User Management header', () => {
        const wrapper = mount(Users, globalStubs);
        expect(wrapper.text()).toContain('User Management');
    });

    it('calls /users on mount', async () => {
        mount(Users, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/users'), expect.anything());
    });

    it('renders Create User button', () => {
        const wrapper = mount(Users, globalStubs);
        const buttons = wrapper.findAll('button');
        const createBtn = buttons.find(b => b.text().includes('Create User'));
        expect(createBtn).toBeTruthy();
    });

    it('renders status filter select with active/inactive/locked options', () => {
        const wrapper = mount(Users, globalStubs);
        const options = wrapper.findAll('select option').map(o => o.text());
        expect(options).toEqual(expect.arrayContaining(['Active', 'Inactive', 'Locked']));
    });

    it('renders table with Username, Roles, Status columns', () => {
        const wrapper = mount(Users, globalStubs);
        const headers = wrapper.findAll('th').map(th => th.text());
        expect(headers).toEqual(expect.arrayContaining(['Username', 'Roles', 'Status']));
    });

    it('renders table with MFA column', () => {
        const wrapper = mount(Users, globalStubs);
        const headers = wrapper.findAll('th').map(th => th.text());
        expect(headers).toEqual(expect.arrayContaining(['MFA']));
    });
});

// ═══════════════════════════════════════════════════════
// Positions — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import Positions from '../../src/pages/Positions.vue';

describe('Positions page (mounted)', () => {
    it('renders Positions header', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(Positions, globalStubs);
        expect(wrapper.text()).toContain('Positions');
    });

    it('calls /positions on mount', async () => {
        authState.current = STEWARD_AUTH;
        mount(Positions, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/positions'), expect.anything());
    });

    it('renders a data table', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(Positions, globalStubs);
        expect(wrapper.find('table').exists()).toBe(true);
    });
});

// ═══════════════════════════════════════════════════════
// CourseCategories — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import CourseCategories from '../../src/pages/CourseCategories.vue';

describe('CourseCategories page (mounted)', () => {
    it('renders Course Categories header', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(CourseCategories, globalStubs);
        expect(wrapper.text()).toContain('Course Categories');
    });

    it('calls /course-categories on mount', async () => {
        authState.current = STEWARD_AUTH;
        mount(CourseCategories, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/course-categories'), expect.anything());
    });

    it('renders a data table', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(CourseCategories, globalStubs);
        expect(wrapper.find('table').exists()).toBe(true);
    });
});

// ═══════════════════════════════════════════════════════
// Dictionaries — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import Dictionaries from '../../src/pages/Dictionaries.vue';

describe('Dictionaries page (mounted)', () => {
    it('renders Data Dictionaries header', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(Dictionaries, globalStubs);
        const text = wrapper.text();
        expect(text.toLowerCase()).toContain('dictionar');
    });

    it('calls /dictionaries on mount', async () => {
        authState.current = STEWARD_AUTH;
        mount(Dictionaries, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/dictionaries'), expect.anything());
    });

    it('renders a data table', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(Dictionaries, globalStubs);
        expect(wrapper.find('table').exists()).toBe(true);
    });
});

// ═══════════════════════════════════════════════════════
// PersonnelList — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import PersonnelList from '../../src/pages/PersonnelList.vue';

describe('PersonnelList page (mounted)', () => {
    it('renders Personnel header', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(PersonnelList, globalStubs);
        expect(wrapper.text()).toContain('Personnel');
    });

    it('calls /personnel on mount', async () => {
        authState.current = STEWARD_AUTH;
        mount(PersonnelList, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/personnel'), expect.anything());
    });

    it('renders a data table', () => {
        authState.current = STEWARD_AUTH;
        const wrapper = mount(PersonnelList, globalStubs);
        expect(wrapper.find('table').exists()).toBe(true);
    });
});

// ═══════════════════════════════════════════════════════
// MfaSetup — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import MfaSetup from '../../src/pages/MfaSetup.vue';

describe('MfaSetup page (mounted)', () => {
    it('renders MFA Setup heading', () => {
        const wrapper = mount(MfaSetup, globalStubs);
        const text = wrapper.text().toLowerCase();
        expect(text).toMatch(/mfa|multi.?factor|totp|two.?factor/);
    });

    it('renders a setup/enable button', () => {
        const wrapper = mount(MfaSetup, globalStubs);
        const btns = wrapper.findAll('button');
        expect(btns.length).toBeGreaterThanOrEqual(1);
    });
});

// ═══════════════════════════════════════════════════════
// AdmissionsPlanDetail — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import AdmissionsPlanDetail from '../../src/pages/AdmissionsPlanDetail.vue';

describe('AdmissionsPlanDetail page (mounted)', () => {
    beforeEach(() => {
        mockGet.mockResolvedValue({
            data: {
                data: {
                    id: 1, academic_year: '2030-2031', intake_batch: 'Test',
                    current_version_id: 1,
                    versions: [{ id: 1, state: 'draft', version_number: 1 }],
                },
                meta: {},
            },
        });
    });

    it('calls plan detail API on mount', async () => {
        mount(AdmissionsPlanDetail, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/admissions-plans/'));
    });

    it('renders plan-related content', async () => {
        const wrapper = mount(AdmissionsPlanDetail, globalStubs);
        await flushPromises();
        expect(wrapper.find('.card').exists()).toBe(true);
    });
});

// ═══════════════════════════════════════════════════════
// PublishedPlanDetail — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import PublishedPlanDetail from '../../src/pages/PublishedPlanDetail.vue';

describe('PublishedPlanDetail page (mounted)', () => {
    beforeEach(() => {
        mockGet.mockResolvedValue({
            data: {
                data: {
                    id: 1, academic_year: '2030-2031', intake_batch: 'Test',
                    published_version: { id: 1, state: 'published', programs: [] },
                },
                meta: {},
            },
        });
    });

    it('calls published-plans detail API on mount', async () => {
        mount(PublishedPlanDetail, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/published-plans/'));
    });

    it('renders plan card', async () => {
        const wrapper = mount(PublishedPlanDetail, globalStubs);
        await flushPromises();
        expect(wrapper.find('.card').exists()).toBe(true);
    });
});

// ═══════════════════════════════════════════════════════
// UserDetail — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import UserDetail from '../../src/pages/UserDetail.vue';

describe('UserDetail page (mounted)', () => {
    beforeEach(() => {
        mockGet.mockResolvedValue({
            data: {
                data: {
                    id: 1, username: 'testuser', full_name: 'Test User',
                    status: 'active', active_role_scopes: [{ id: 1, role: 'applicant' }],
                },
                meta: {},
            },
        });
    });

    it('calls user detail API on mount', async () => {
        mount(UserDetail, globalStubs);
        await flushPromises();
        expect(mockGet).toHaveBeenCalledWith(expect.stringContaining('/users/'));
    });

    it('renders user detail card', async () => {
        const wrapper = mount(UserDetail, globalStubs);
        await flushPromises();
        expect(wrapper.find('.card').exists()).toBe(true);
    });
});

// ═══════════════════════════════════════════════════════
// AppLayout — mounted behavioral tests
// ═══════════════════════════════════════════════════════

import AppLayout from '../../src/components/AppLayout.vue';

describe('AppLayout component (mounted)', () => {
    it('renders sidebar navigation', () => {
        const wrapper = mount(AppLayout, {
            global: { stubs: { RouterLink: true, RouterView: true } },
            slots: { default: '<div>Content</div>' },
        });
        expect(wrapper.find('nav, .sidebar, .nav, [class*="sidebar"]').exists()).toBe(true);
    });

    it('shows logout button or link', () => {
        const wrapper = mount(AppLayout, {
            global: { stubs: { RouterLink: true, RouterView: true } },
        });
        expect(wrapper.text().toLowerCase()).toContain('logout');
    });

    it('shows user name in layout', () => {
        const wrapper = mount(AppLayout, {
            global: { stubs: { RouterLink: true, RouterView: true } },
        });
        expect(wrapper.text()).toContain('Admin User');
    });
});
