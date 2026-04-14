<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Database-backed distributed lock service.
 * Uses MySQL-compatible lock records for concurrency control.
 */
class LockService
{
    private string $ownerId;

    public function __construct()
    {
        $this->ownerId = Str::uuid()->toString();
    }

    /**
     * Acquire a lock. Returns true if acquired, false if not.
     */
    public function acquire(string $key, int $ttlSeconds = 30): bool
    {
        $this->cleanupExpired();

        try {
            DB::table('distributed_locks')->insert([
                'lock_key' => $key,
                'owner' => $this->ownerId,
                'acquired_at' => now(),
                'expires_at' => now()->addSeconds($ttlSeconds),
            ]);
            return true;
        } catch (\Throwable $e) {
            // Key already exists — check if expired
            $existing = DB::table('distributed_locks')
                ->where('lock_key', $key)
                ->first();

            if ($existing && now()->greaterThan($existing->expires_at)) {
                // Expired lock — try to take over
                $updated = DB::table('distributed_locks')
                    ->where('lock_key', $key)
                    ->where('owner', $existing->owner)
                    ->update([
                        'owner' => $this->ownerId,
                        'acquired_at' => now(),
                        'expires_at' => now()->addSeconds($ttlSeconds),
                    ]);
                return $updated > 0;
            }

            return false;
        }
    }

    /**
     * Release a lock.
     */
    public function release(string $key): bool
    {
        $deleted = DB::table('distributed_locks')
            ->where('lock_key', $key)
            ->where('owner', $this->ownerId)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Execute a callback under a lock.
     */
    public function withLock(string $key, callable $callback, int $ttlSeconds = 30)
    {
        if (!$this->acquire($key, $ttlSeconds)) {
            throw new \RuntimeException("Could not acquire lock: {$key}. Please retry.");
        }

        try {
            return $callback();
        } finally {
            $this->release($key);
        }
    }

    /**
     * Cleanup expired locks.
     */
    public function cleanupExpired(): int
    {
        return DB::table('distributed_locks')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
