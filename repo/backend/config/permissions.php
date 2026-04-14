<?php

return [
    // Map each plan transition target state to the required permission
    'plan_transition_permissions' => [
        'submitted'    => 'plans.submit_review',
        'under_review' => 'plans.submit_review',
        'returned'     => 'plans.approve',
        'approved'     => 'plans.approve',
        'rejected'     => 'plans.approve',
        'published'    => 'plans.publish',
        'archived'     => 'plans.create_version',
        'draft'        => 'plans.create_version',
    ],

    'role_permissions' => [
        'applicant' => [
            'plans.view_published',
            'tickets.create',
            'appointments.book',
        ],
        'advisor' => [
            'plans.view_published',
            'tickets.reply_assigned',
            'appointments.manage',
        ],
        'manager' => [
            'plans.view_published',
            'plans.create_version',
            'plans.submit_review',
            'plans.approve',
            'plans.publish',
            'tickets.reply_assigned',
            'tickets.route',
            'tickets.reassign',
            'tickets.review_sampled',
            'appointments.manage',
            'appointments.override_policy',
            'reports.view',
        ],
        'steward' => [
            'plans.view_published',
            'masterdata.edit',
            'masterdata.merge_request',
            'masterdata.merge_approve',
            'reports.view',
        ],
        'admin' => [
            'plans.view_published',
            'plans.create_version',
            'plans.submit_review',
            'plans.approve',
            'plans.publish',
            'tickets.reply_assigned',
            'tickets.route',
            'tickets.reassign',
            'tickets.review_sampled',
            'appointments.manage',
            'appointments.override_policy',
            'masterdata.edit',
            'masterdata.merge_request',
            'masterdata.merge_approve',
            'security.manage',
            'audit.view',
            'reports.view',
            'attachments.view_sensitive',
        ],
    ],
];
