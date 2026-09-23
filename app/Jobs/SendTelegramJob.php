<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\ChecksProcessingAuthority;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ChecksProcessingAuthority;
    protected $telegramId;
    protected $text;
    protected ?array $processingPermit;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $telegramId, string $text, ?array $processingPermit = null)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = $telegramId;
        $this->text = $text;
        $this->processingPermit = $processingPermit ?? $this->scanPermit('telegramNotification');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!$this->executionAllowed($this->processingPermit)) return;
        $telegramService = new TelegramService();
        $telegramService->sendMessage($this->telegramId, $this->text, 'markdown');
    }
}
