import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

// Mock API
vi.mock('../../src/utils/api', () => ({
    default: { post: vi.fn(), get: vi.fn() },
}));

// Mock router
const mockPush = vi.fn();
vi.mock('vue-router', () => ({
    useRouter: () => ({ push: mockPush }),
    useRoute: () => ({ params: {} }),
    createRouter: vi.fn(() => ({ beforeEach: vi.fn(), install: vi.fn() })),
    createWebHistory: vi.fn(),
}));

// Mock auth store
const mockLogin = vi.fn();
vi.mock('../../src/store/auth', () => ({
    useAuthStore: () => ({
        isAuthenticated: false,
        login: mockLogin,
        token: null,
        user: null,
    }),
}));

import LoginPage from '../../src/pages/Login.vue';

describe('Login page mounted behavior', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('renders login form with username, password, and submit button', () => {
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });
        expect(wrapper.find('#username').exists()).toBe(true);
        expect(wrapper.find('#password').exists()).toBe(true);
        expect(wrapper.find('button[type="submit"]').exists()).toBe(true);
        expect(wrapper.find('h1').text()).toContain('Admissions System');
    });

    it('shows validation error when username is empty', async () => {
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });
        await wrapper.find('#password').setValue('somepassword');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.find('.form-error').exists()).toBe(true);
    });

    it('shows validation error when password is empty', async () => {
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });
        await wrapper.find('#username').setValue('someuser');
        await wrapper.find('form').trigger('submit');
        expect(wrapper.find('.form-error').exists()).toBe(true);
    });

    it('calls store login on valid submission', async () => {
        mockLogin.mockResolvedValueOnce({ mfa_required: false });
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#username').setValue('testuser');
        await wrapper.find('#password').setValue('testpass');
        await wrapper.find('form').trigger('submit');
        await vi.dynamicImportSettled();

        expect(mockLogin).toHaveBeenCalledWith('testuser', 'testpass', null, null);
    });

    it('shows error alert on login failure', async () => {
        mockLogin.mockRejectedValueOnce({
            response: { data: { error: { message: 'Invalid credentials.' } } },
        });
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#username').setValue('baduser');
        await wrapper.find('#password').setValue('badpass');
        await wrapper.find('form').trigger('submit');
        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('.alert-error').exists()).toBe(true);
    });

    it('redirects to /mfa when MFA is required', async () => {
        mockLogin.mockResolvedValueOnce({ mfa_required: true });
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#username').setValue('admin');
        await wrapper.find('#password').setValue('pass');
        await wrapper.find('form').trigger('submit');
        await vi.dynamicImportSettled();

        expect(mockPush).toHaveBeenCalledWith('/mfa');
    });

    it('redirects to / when MFA is not required', async () => {
        mockLogin.mockResolvedValueOnce({ mfa_required: false });
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#username').setValue('applicant');
        await wrapper.find('#password').setValue('pass');
        await wrapper.find('form').trigger('submit');
        await vi.dynamicImportSettled();

        expect(mockPush).toHaveBeenCalledWith('/');
    });

    it('disables submit button while loading', async () => {
        mockLogin.mockImplementation(() => new Promise(() => {})); // never resolves
        const wrapper = mount(LoginPage, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#username').setValue('user');
        await wrapper.find('#password').setValue('pass');
        await wrapper.find('form').trigger('submit');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});
