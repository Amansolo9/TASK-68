import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../store/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/Login.vue'),
        meta: { guest: true },
    },
    {
        path: '/mfa',
        name: 'mfa',
        component: () => import('../pages/MfaVerify.vue'),
        meta: { requiresAuth: true },
    },
    {
        path: '/',
        component: () => import('../components/AppLayout.vue'),
        meta: { requiresAuth: true, requiresMfa: true },
        children: [
            {
                path: '',
                name: 'dashboard',
                component: () => import('../pages/Dashboard.vue'),
            },
            // User management (admin)
            {
                path: 'users',
                name: 'users',
                component: () => import('../pages/Users.vue'),
                meta: { permission: 'security.manage' },
            },
            {
                path: 'users/:id',
                name: 'user-detail',
                component: () => import('../pages/UserDetail.vue'),
                meta: { permission: 'security.manage' },
            },
            // Consultation Tickets
            {
                path: 'tickets',
                name: 'tickets',
                component: () => import('../pages/Tickets.vue'),
            },
            {
                path: 'tickets/:id',
                name: 'ticket-detail',
                component: () => import('../pages/TicketDetail.vue'),
            },
            // Appointments
            {
                path: 'appointments',
                name: 'appointments',
                component: () => import('../pages/Appointments.vue'),
            },
            // Admissions Plans - applicant browsing (published)
            {
                path: 'admissions-plans',
                name: 'admissions-plans',
                component: () => import('../pages/AdmissionsPlans.vue'),
            },
            {
                path: 'published-plans/:id',
                name: 'published-plan-detail',
                component: () => import('../pages/PublishedPlanDetail.vue'),
            },
            // Admissions Plans - internal management (staff)
            {
                path: 'admissions-plans/:id',
                name: 'admissions-plan-detail',
                component: () => import('../pages/AdmissionsPlanDetail.vue'),
                meta: { permission: 'plans.create_version' },
            },
            // Master data
            {
                path: 'organizations',
                name: 'organizations',
                component: () => import('../pages/Organizations.vue'),
            },
            {
                path: 'personnel',
                name: 'personnel',
                component: () => import('../pages/PersonnelList.vue'),
            },
            {
                path: 'positions',
                name: 'positions',
                component: () => import('../pages/Positions.vue'),
            },
            {
                path: 'course-categories',
                name: 'course-categories',
                component: () => import('../pages/CourseCategories.vue'),
            },
            {
                path: 'dictionaries',
                name: 'dictionaries',
                component: () => import('../pages/Dictionaries.vue'),
                meta: { permission: 'masterdata.edit' },
            },
            // Audit logs
            {
                path: 'audit-logs',
                name: 'audit-logs',
                component: () => import('../pages/AuditLogs.vue'),
                meta: { permission: 'audit.view' },
            },
            // MFA Setup
            {
                path: 'mfa-setup',
                name: 'mfa-setup',
                component: () => import('../pages/MfaSetup.vue'),
            },
        ],
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: '/',
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to, from, next) => {
    const auth = useAuthStore();

    if (to.meta.guest && auth.isAuthenticated) {
        return next('/');
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
        return next('/login');
    }

    if (to.meta.requiresMfa && auth.mfaRequired && !auth.mfaVerified) {
        return next('/mfa');
    }

    // Enforce route-level permission metadata (UX layer — backend remains authoritative)
    const requiredPermission = to.meta.permission || to.matched?.find(r => r.meta.permission)?.meta.permission;
    if (requiredPermission && auth.isAuthenticated) {
        if (!auth.hasPermission(requiredPermission)) {
            return next('/');
        }
    }

    // Enforce route-level role metadata
    const requiredRole = to.meta.role || to.matched?.find(r => r.meta.role)?.meta.role;
    if (requiredRole && auth.isAuthenticated) {
        const roles = Array.isArray(requiredRole) ? requiredRole : [requiredRole];
        if (!auth.hasAnyRole(roles)) {
            return next('/');
        }
    }

    next();
});

export default router;
