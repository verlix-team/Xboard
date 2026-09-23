<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\ChecksProcessingAuthority;

class NodeUserSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ChecksProcessingAuthority;

    protected ?array $processingPermit;

    public $tries = 2;
    public $timeout = 10;

    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        private readonly ?int $oldGroupId = null,
        ?array $processingPermit = null
    ) {
        $this->onQueue('node_sync');
        $this->processingPermit = $processingPermit ?? $this->scanPermit('entitlementSync');
    }

    public function handle(): void
    {
        if (!$this->executionAllowed($this->processingPermit)) return;
        $user = User::find($this->userId);

        if ($this->action === 'updated' || $this->action === 'created') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            }
            if ($user) {
                NodeSyncService::notifyUserChanged($user);
            }
        } elseif ($this->action === 'deleted') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            }
        }
    }
}
