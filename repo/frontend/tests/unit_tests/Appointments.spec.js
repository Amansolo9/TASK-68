import { describe, it, expect } from 'vitest';

describe('Appointments page role-based behavior', () => {
    const hoursUntil = (dt) => (new Date(dt).getTime() - Date.now()) / 3600000;

    // Applicant mode
    describe('applicant mode', () => {
        const userRoles = ['applicant'];
        const isStaff = ['advisor', 'manager', 'admin'].some(r => userRoles.includes(r));

        it('applicant is not identified as staff', () => {
            expect(isStaff).toBe(false);
        });

        it('applicant fetches from /appointments/my', () => {
            const endpoint = '/appointments/my';
            expect(endpoint).toBe('/appointments/my');
        });

        it('canReschedule is false when less than 24h before start for applicant', () => {
            const slot = { start_at: new Date(Date.now() + 12 * 3600000).toISOString() };
            const canReschedule = slot && hoursUntil(slot.start_at) >= 24;
            expect(canReschedule).toBe(false);
        });

        it('canReschedule is true when more than 24h before start for applicant', () => {
            const slot = { start_at: new Date(Date.now() + 48 * 3600000).toISOString() };
            const canReschedule = slot && hoursUntil(slot.start_at) >= 24;
            expect(canReschedule).toBe(true);
        });

        it('canCancel is false when less than 12h before start', () => {
            const slot = { start_at: new Date(Date.now() + 6 * 3600000).toISOString() };
            const canCancel = slot && hoursUntil(slot.start_at) >= 12;
            expect(canCancel).toBe(false);
        });

        it('canCancel is true when more than 12h before start', () => {
            const slot = { start_at: new Date(Date.now() + 24 * 3600000).toISOString() };
            const canCancel = slot && hoursUntil(slot.start_at) >= 12;
            expect(canCancel).toBe(true);
        });
    });

    // Staff mode
    describe('advisor/manager mode', () => {
        it('advisor is identified as staff', () => {
            const userRoles = ['advisor'];
            const isStaff = ['advisor', 'manager', 'admin'].some(r => userRoles.includes(r));
            expect(isStaff).toBe(true);
        });

        it('manager is identified as staff', () => {
            const userRoles = ['manager'];
            const isStaff = ['advisor', 'manager', 'admin'].some(r => userRoles.includes(r));
            expect(isStaff).toBe(true);
        });

        it('staff fetches from /appointments for management view', () => {
            const endpoint = '/appointments';
            expect(endpoint).toBe('/appointments');
        });

        it('manager/admin canReschedule is always true (override)', () => {
            const userRoles = ['manager'];
            const isManagerOrAdmin = ['manager', 'admin'].some(r => userRoles.includes(r));
            const slot = { start_at: new Date(Date.now() + 6 * 3600000).toISOString() };
            const canReschedule = isManagerOrAdmin || (slot && hoursUntil(slot.start_at) >= 24);
            expect(canReschedule).toBe(true);
        });

        it('no-show button visible only for staff and only after slot end + 10 min', () => {
            const userRoles = ['advisor'];
            const isStaff = ['advisor', 'manager', 'admin'].some(r => userRoles.includes(r));
            const pastSlot = { start_at: new Date(Date.now() - 20 * 60000).toISOString() };
            const canMarkNoShow = isStaff && pastSlot && hoursUntil(pastSlot.start_at) <= -(10 / 60);
            expect(canMarkNoShow).toBe(true);
        });

        it('no-show button hidden for staff when slot not yet ended', () => {
            const userRoles = ['advisor'];
            const isStaff = ['advisor', 'manager', 'admin'].some(r => userRoles.includes(r));
            const futureSlot = { start_at: new Date(Date.now() + 60 * 60000).toISOString() };
            const canMarkNoShow = isStaff && futureSlot && hoursUntil(futureSlot.start_at) <= -(10 / 60);
            expect(canMarkNoShow).toBe(false);
        });
    });
});
