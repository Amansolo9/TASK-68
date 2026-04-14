<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ConsultationAttachment;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanAttachments extends Command
{
    protected $signature = 'attachments:cleanup-orphans';
    protected $description = 'Scan and clean up orphan attachment files without active metadata';

    public function handle(): int
    {
        $cleaned = 0;

        // Find failed uploads (metadata exists, file may not)
        $failed = ConsultationAttachment::where('upload_status', 'failed')->get();
        foreach ($failed as $attachment) {
            if ($attachment->storage_path && Storage::disk('local')->exists($attachment->storage_path)) {
                Storage::disk('local')->delete($attachment->storage_path);
            }
            $attachment->delete();
            $cleaned++;
        }

        $this->info("Cleaned up {$cleaned} orphan attachments.");
        return Command::SUCCESS;
    }
}
