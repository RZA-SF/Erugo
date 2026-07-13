<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Share;
use App\Models\ReverseShareInvite;

class cleanSpecificShares implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $shareIds, public int $userId)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        foreach ($this->shareIds as $shareId) {
            $share = Share::find($shareId);
            if (!$share) {
                continue;
            }

            // Direct owner
            if ($share->user_id === $this->userId) {
                $share->cleanFiles(true);
                continue;
            }

            // Reverse-share: the inviting user owns the invite, not the share itself
            if ($share->invite_id &&
                ReverseShareInvite::where('id', $share->invite_id)
                    ->where('user_id', $this->userId)
                    ->exists()) {
                $share->cleanFiles(true);
            }
        }
    }
}
