import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../src/utils/api', () => ({
    default: {
        get: vi.fn().mockResolvedValue({
            data: { data: { timestamp: '2026-04-15T10:00:00Z', has_updates: false }, meta: { poll_after_ms: 10000 } },
        }),
    },
}));

vi.mock('vue-router', () => ({
    useRouter: () => ({ push: vi.fn() }),
    useRoute: () => ({ name: 'dashboard' }),
    createRouter: vi.fn(() => ({ beforeEach: vi.fn(), install: vi.fn() })),
    createWebHistory: vi.fn(),
}));

vi.mock('../../src/store/auth', () => ({
    useAuthStore: () => ({
        isAuthenticated: true,
        mfaVerified: true,
        user: { full_name: 'Test User', roles: ['manager'] },
        roles: ['manager'],
        hasAnyRole: (r) => r.some(x => ['manager'].includes(x)),
        hasRole: (r) => ['manager'].includes(r),
        isAdmin: false,
        sessionInfo: { expires_at: '2026-04-15T12:00:00Z' },
    }),
}));

import Dashboard from '../../src/pages/Dashboard.vue';

describe('Dashboard page mounted behavior', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('renders welcome message with user name', () => {
        const wrapper = mount(Dashboard, { global: { stubs: { RouterLink: true } } });
        expect(wrapper.text()).toContain('Test User');
    });

    it('displays role badges', () => {
        const wrapper = mount(Dashboard, { global: { stubs: { RouterLink: true } } });
        expect(wrapper.find('.badge').exists()).toBe(true);
    });

    it('shows system status as operational', () => {
        const wrapper = mount(Dashboard, { global: { stubs: { RouterLink: true } } });
        expect(wrapper.text()).toContain('Operational');
    });

    it('displays session expiry info', () => {
        const wrapper = mount(Dashboard, { global: { stubs: { RouterLink: true } } });
        expect(wrapper.text()).toContain('expires');
    });
});
