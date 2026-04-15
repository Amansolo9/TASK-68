import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';

const mockPush = vi.fn();
vi.mock('vue-router', () => ({
    useRouter: () => ({ push: mockPush }),
    useRoute: () => ({ params: {} }),
    createRouter: vi.fn(() => ({ beforeEach: vi.fn(), install: vi.fn() })),
    createWebHistory: vi.fn(),
}));

const mockVerifyMfa = vi.fn();
const mockUseRecoveryCode = vi.fn();
vi.mock('../../src/store/auth', () => ({
    useAuthStore: () => ({
        isAuthenticated: true,
        mfaRequired: true,
        mfaVerified: false,
        verifyMfa: mockVerifyMfa,
        useRecoveryCode: mockUseRecoveryCode,
    }),
}));

import MfaVerify from '../../src/pages/MfaVerify.vue';

describe('MFA Verify page mounted behavior', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('renders TOTP code input and verify button', () => {
        const wrapper = mount(MfaVerify, { global: { stubs: { RouterLink: true } } });
        expect(wrapper.find('#code').exists()).toBe(true);
        expect(wrapper.find('button[type="submit"]').text()).toContain('Verify');
        expect(wrapper.find('h1').text()).toContain('Two-Factor');
    });

    it('calls verifyMfa with the entered code on submit', async () => {
        mockVerifyMfa.mockResolvedValueOnce({});
        const wrapper = mount(MfaVerify, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#code').setValue('123456');
        await wrapper.find('form').trigger('submit');
        await vi.dynamicImportSettled();

        expect(mockVerifyMfa).toHaveBeenCalledWith('123456');
    });

    it('shows error on invalid TOTP code', async () => {
        mockVerifyMfa.mockRejectedValueOnce({
            response: { data: { error: { message: 'Invalid code.' } } },
        });
        const wrapper = mount(MfaVerify, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#code').setValue('000000');
        await wrapper.find('form').trigger('submit');
        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('.alert-error').text()).toContain('Invalid');
    });

    it('shows recovery code form when toggled', async () => {
        const wrapper = mount(MfaVerify, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('button:not([type="submit"])').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('#recovery').exists()).toBe(true);
    });

    it('redirects to / after successful verification', async () => {
        mockVerifyMfa.mockResolvedValueOnce({});
        const wrapper = mount(MfaVerify, { global: { stubs: { RouterLink: true } } });

        await wrapper.find('#code').setValue('123456');
        await wrapper.find('form').trigger('submit');
        await vi.dynamicImportSettled();

        expect(mockPush).toHaveBeenCalledWith('/');
    });
});
