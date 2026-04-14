<?php

use Illuminate\Support\Facades\Schedule;

// SLA overdue recalculation - every 15 minutes during business hours
Schedule::command('tickets:recalculate-sla')->everyFifteenMinutes();

// Weekly consultation quality-review sampling - Mondays at 1 AM
Schedule::command('tickets:sample-quality-review')->weeklyOn(1, '01:00');

// Nightly data quality metrics computation - daily at 2 AM
Schedule::command('metrics:compute-data-quality')->dailyAt('02:00');

// Published artifact integrity verification - daily at 3 AM
Schedule::command('artifacts:verify-integrity')->dailyAt('03:00');

// Orphan attachment cleanup scan - daily at 4 AM
Schedule::command('attachments:cleanup-orphans')->dailyAt('04:00');

// Appointment pending-hold expiration cleanup - every 5 minutes
Schedule::command('appointments:expire-pending-holds')->everyFiveMinutes();

// Stale lock detection and cleanup - every 10 minutes
Schedule::command('locks:cleanup-stale')->everyTenMinutes();
