import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('Tickets inbox triage filters', () => {
    const ticketsVue = readFileSync(resolve(__dirname, '../../src/pages/Tickets.vue'), 'utf-8');

    it('has department_id filter input', () => {
        expect(ticketsVue).toContain('v-model="filters.department_id"');
        expect(ticketsVue).toContain('placeholder="Department ID"');
    });

    it('has category_tag filter select', () => {
        expect(ticketsVue).toContain('v-model="filters.category_tag"');
        expect(ticketsVue).toContain('All categories');
    });

    it('sends department_id filter param in fetch', () => {
        expect(ticketsVue).toContain('params.department_id = filters.department_id');
    });

    it('sends category_tag filter param in fetch', () => {
        expect(ticketsVue).toContain('params.category_tag = filters.category_tag');
    });

    it('shows department column in ticket table', () => {
        expect(ticketsVue).toContain('<th>Department</th>');
        expect(ticketsVue).toContain('t.department_id');
    });

    it('has reassign button for manager/admin roles', () => {
        expect(ticketsVue).toContain("auth.hasAnyRole(['manager','admin'])");
        expect(ticketsVue).toContain('Reassign');
    });

    it('reassign modal requires reason', () => {
        expect(ticketsVue).toContain('reassignForm.reason');
        expect(ticketsVue).toContain('Reason (required)');
        expect(ticketsVue).toContain('minlength="5"');
    });
});

describe('Appointments no-show helper text', () => {
    const appointmentsVue = readFileSync(resolve(__dirname, '../../src/pages/Appointments.vue'), 'utf-8');

    it('displays correct no-show text referencing slot start', () => {
        expect(appointmentsVue).toContain('No-show: available 10 min after slot start');
    });

    it('does not contain incorrect slot end reference', () => {
        expect(appointmentsVue).not.toContain('after slot end');
    });
});
