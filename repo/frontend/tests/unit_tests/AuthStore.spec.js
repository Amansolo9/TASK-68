import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

// Mock axios — must use inline vi.fn() since factory is hoisted
vi.mock('../../src/utils/api', () => ({
    default: { post: vi.fn(), get: vi.fn() },
}));

import api from '../../src/utils/api';
import { useAuthStore } from '../../src/store/auth';

const mockPost = api.post;
const mockGet = api.get;

describe('Auth store', () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useAuthStore();
        vi.clearAllMocks();
        localStorage.clear();
    });

    describe('login', () => {
        it('stores token and user on successful login', async () => {
            mockPost.mockResolvedValueOnce({
                data: {
                    data: {
                        token: 'test-token-123',
                        mfa_required: false,
                        user: { id: 1, username: 'admin', roles: ['admin'] },
                    },
                },
            });

            const result = await store.login('admin', 'pass');

            expect(store.token).toBe('test-token-123');
            expect(store.user.username).toBe('admin');
            expect(store.isAuthenticated).toBe(true);
            expect(localStorage.getItem('auth_token')).toBe('test-token-123');
        });

        it('sets mfaRequired flag when MFA is needed', async () => {
            mockPost.mockResolvedValueOnce({
                data: {
                    data: {
                        token: 'mfa-token',
                        mfa_required: true,
                        user: { id: 1, username: 'admin', roles: ['admin'] },
                    },
                },
            });

            await store.login('admin', 'pass');
            expect(store.mfaRequired).toBe(true);
            expect(store.mfaVerified).toBe(false);
            expect(store.isFullyAuthenticated).toBe(false);
        });

        it('sends captcha fields when provided', async () => {
            mockPost.mockResolvedValueOnce({
                data: { data: { token: 't', mfa_required: false, user: { id: 1, roles: [] } } },
            });

            await store.login('user', 'pass', 'cap-key', '42');
            expect(mockPost).toHaveBeenCalledWith('/auth/login', {
                username: 'user', password: 'pass',
                captcha_key: 'cap-key', captcha_answer: '42',
            });
        });
    });

    describe('logout', () => {
        it('clears auth state and local storage', async () => {
            store.token = 'some-token';
            store.user = { id: 1, roles: ['admin'] };
            localStorage.setItem('auth_token', 'some-token');
            mockPost.mockResolvedValueOnce({});

            await store.logout();

            expect(store.token).toBeNull();
            expect(store.user).toBeNull();
            expect(store.isAuthenticated).toBe(false);
            expect(localStorage.getItem('auth_token')).toBeNull();
        });

        it('clears state even if API call fails', async () => {
            store.token = 'tok';
            store.user = { id: 1, roles: [] };
            mockPost.mockRejectedValueOnce(new Error('network'));

            await store.logout();
            expect(store.token).toBeNull();
        });
    });

    describe('permission getters', () => {
        it('applicant has tickets.create but not security.manage', () => {
            store.user = { roles: ['applicant'] };
            expect(store.hasPermission('tickets.create')).toBe(true);
            expect(store.hasPermission('security.manage')).toBe(false);
        });

        it('admin has all permissions', () => {
            store.user = { roles: ['admin'] };
            expect(store.hasPermission('security.manage')).toBe(true);
            expect(store.hasPermission('audit.view')).toBe(true);
            expect(store.hasPermission('tickets.create')).toBe(false); // admin doesn't create tickets
        });

        it('manager has reports.view', () => {
            store.user = { roles: ['manager'] };
            expect(store.hasPermission('reports.view')).toBe(true);
        });

        it('hasAnyRole works correctly', () => {
            store.user = { roles: ['advisor'] };
            expect(store.hasAnyRole(['advisor', 'manager'])).toBe(true);
            expect(store.hasAnyRole(['admin'])).toBe(false);
        });
    });

    describe('MFA verification', () => {
        it('verifyMfa sets verified state', async () => {
            store.token = 'tok';
            store.mfaRequired = true;
            mockPost.mockResolvedValueOnce({ data: { data: { mfa_verified: true } } });

            await store.verifyMfa('123456');
            expect(store.mfaVerified).toBe(true);
            expect(store.mfaRequired).toBe(false);
        });
    });
});
