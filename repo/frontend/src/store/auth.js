import { defineStore } from 'pinia';
import api from '../utils/api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('auth_token') || null,
        user: JSON.parse(localStorage.getItem('auth_user') || 'null'),
        mfaRequired: false,
        mfaVerified: false,
        sessionInfo: null,
    }),

    getters: {
        isAuthenticated: (state) => !!state.token && !!state.user,
        isFullyAuthenticated: (state) => !!state.token && !!state.user && (!state.mfaRequired || state.mfaVerified),
        roles: (state) => state.user?.roles || [],
        hasRole: (state) => (role) => state.user?.roles?.includes(role) || false,
        hasAnyRole: (state) => (roles) => roles.some(r => state.user?.roles?.includes(r)),
        isAdmin: (state) => state.user?.roles?.includes('admin') || false,
        isManager: (state) => state.user?.roles?.includes('manager') || false,
        isSteward: (state) => state.user?.roles?.includes('steward') || false,
        isAdvisor: (state) => state.user?.roles?.includes('advisor') || false,
        isApplicant: (state) => state.user?.roles?.includes('applicant') || false,
        permissions: (state) => {
            const rolePermissions = {
                applicant: ['plans.view_published', 'tickets.create', 'appointments.book'],
                advisor: ['plans.view_published', 'tickets.reply_assigned', 'appointments.manage'],
                manager: [
                    'plans.view_published', 'plans.create_version', 'plans.submit_review', 'plans.approve', 'plans.publish',
                    'tickets.reply_assigned', 'tickets.route', 'tickets.reassign', 'tickets.review_sampled',
                    'appointments.manage', 'appointments.override_policy', 'reports.view',
                ],
                steward: ['plans.view_published', 'masterdata.edit', 'masterdata.merge_request', 'masterdata.merge_approve', 'reports.view'],
                admin: [
                    'plans.view_published', 'plans.create_version', 'plans.submit_review', 'plans.approve', 'plans.publish',
                    'tickets.reply_assigned', 'tickets.route', 'tickets.reassign', 'tickets.review_sampled',
                    'appointments.manage', 'appointments.override_policy',
                    'masterdata.edit', 'masterdata.merge_request', 'masterdata.merge_approve',
                    'security.manage', 'audit.view', 'reports.view', 'attachments.view_sensitive',
                ],
            };
            const perms = new Set();
            for (const role of (state.user?.roles || [])) {
                for (const p of (rolePermissions[role] || [])) {
                    perms.add(p);
                }
            }
            return perms;
        },
        hasPermission: (state) => {
            return (permission) => {
                const rolePermissions = {
                    applicant: ['plans.view_published', 'tickets.create', 'appointments.book'],
                    advisor: ['plans.view_published', 'tickets.reply_assigned', 'appointments.manage'],
                    manager: [
                        'plans.view_published', 'plans.create_version', 'plans.submit_review', 'plans.approve', 'plans.publish',
                        'tickets.reply_assigned', 'tickets.route', 'tickets.reassign', 'tickets.review_sampled',
                        'appointments.manage', 'appointments.override_policy', 'reports.view',
                    ],
                    steward: ['plans.view_published', 'masterdata.edit', 'masterdata.merge_request', 'masterdata.merge_approve', 'reports.view'],
                    admin: [
                        'plans.view_published', 'plans.create_version', 'plans.submit_review', 'plans.approve', 'plans.publish',
                        'tickets.reply_assigned', 'tickets.route', 'tickets.reassign', 'tickets.review_sampled',
                        'appointments.manage', 'appointments.override_policy',
                        'masterdata.edit', 'masterdata.merge_request', 'masterdata.merge_approve',
                        'security.manage', 'audit.view', 'reports.view', 'attachments.view_sensitive',
                    ],
                };
                for (const role of (state.user?.roles || [])) {
                    if ((rolePermissions[role] || []).includes(permission)) return true;
                }
                return false;
            };
        },
    },

    actions: {
        async login(username, password, captchaKey = null, captchaAnswer = null) {
            const payload = { username, password };
            if (captchaKey) payload.captcha_key = captchaKey;
            if (captchaAnswer) payload.captcha_answer = captchaAnswer;

            const response = await api.post('/auth/login', payload);
            const data = response.data.data;

            this.token = data.token;
            this.user = data.user;
            this.mfaRequired = data.mfa_required;
            this.mfaVerified = !data.mfa_required;

            localStorage.setItem('auth_token', data.token);
            localStorage.setItem('auth_user', JSON.stringify(data.user));

            return data;
        },

        async verifyMfa(code) {
            const response = await api.post('/mfa/verify-login', { code });
            this.mfaVerified = true;
            this.mfaRequired = false;
            return response.data.data;
        },

        async useRecoveryCode(recoveryCode) {
            const response = await api.post('/mfa/recovery/use', { recovery_code: recoveryCode });
            this.mfaVerified = true;
            this.mfaRequired = false;
            return response.data.data;
        },

        async fetchSession() {
            const response = await api.get('/auth/session');
            const data = response.data.data;
            this.user = data.user;
            this.sessionInfo = data.session;
            this.mfaVerified = data.session?.mfa_verified || false;
            this.mfaRequired = data.user?.totp_enabled && !this.mfaVerified;
            localStorage.setItem('auth_user', JSON.stringify(data.user));
        },

        async logout() {
            try {
                await api.post('/auth/logout');
            } catch {
                // Proceed with local logout even if API call fails
            }
            this.clearAuth();
        },

        clearAuth() {
            this.token = null;
            this.user = null;
            this.mfaRequired = false;
            this.mfaVerified = false;
            this.sessionInfo = null;
            localStorage.removeItem('auth_token');
            localStorage.removeItem('auth_user');
        },

        async refreshToken() {
            const response = await api.post('/auth/refresh');
            const data = response.data.data;
            this.token = data.token;
            localStorage.setItem('auth_token', data.token);
        },
    },
});
