<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\MailTemplateController;
use App\Models\MailTemplate;
use App\Models\MailLog;
use App\Support\Setting;
use App\Services\MailService;
use App\Jobs\SendEmailJob;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PHPUnit\Framework\TestCase;

/** Isolated SQLite only: never boots the real application or reads a real .env. */
final class EnglishMailTemplatePersistenceTest extends TestCase
{
    private MailTemplateController $controller;

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        $database = new Manager($app);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
        $database->bootEloquent();
        $app->instance('db', $database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $cache = Mockery::mock();
        $cache->shouldReceive('forget')->andReturn(true);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $app->instance('cache', $cache);
        $settings = Mockery::mock(Setting::class);
        $settings->shouldReceive('get')->andReturn(null);
        $app->instance(Setting::class, $settings);
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('json')->andReturnUsing(fn ($data, $status) => new JsonResponse($data, $status));
        $app->instance(ResponseFactory::class, $responses);
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'), $app);
        Request::macro('validate', function ($rules) use ($validator) { return $validator->make($this->all(), $rules)->validate(); });
        $database->schema()->create('v2_mail_templates', function (Blueprint $table) {
            $table->increments('id'); $table->string('name')->unique(); $table->string('subject'); $table->text('content'); $table->timestamps();
        });
        $database->schema()->create('v2_mail_log', function (Blueprint $table) {
            // Match the original Laravel migration, not the fields the sender wishes existed.
            $table->integer('id', true); $table->string('email', 64); $table->string('subject'); $table->string('template_name');
            $table->text('error')->nullable(); $table->integer('created_at'); $table->integer('updated_at');
        });
        $this->controller = new MailTemplateController();
    }

    protected function tearDown(): void
    {
        Request::flushMacros(); Facade::clearResolvedInstances(); Mockery::close();
        parent::tearDown();
    }

    private function get(): array
    {
        return $this->controller->get(Request::create('/', 'GET', ['name' => 'verify']))->getData(true)['data'];
    }

    private function save(string $expected, string $subject = 'Hop verification code', string $content = 'Code: {{code}}'): JsonResponse
    {
        return $this->controller->save(Request::create('/', 'POST', ['name' => 'verify', 'subject' => $subject, 'content' => $content, 'expected_version' => $expected]));
    }

    public function test_default_save_and_stale_write_keep_the_latest_template(): void
    {
        $default = $this->get();
        $this->assertSame('default', $default['version']);
        $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', $default['subject'] . $default['content']);
        $this->assertSame(200, $this->save('default')->getStatusCode());
        $version = $this->get()['version'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version);
        $this->assertSame(409, $this->save('default', 'Stale overwrite')->getStatusCode());
        $this->assertSame('Hop verification code', MailTemplate::first()->subject);
        $this->assertSame(200, $this->save($version, 'New English subject')->getStatusCode());
    }

    public function test_reset_checks_version_and_returns_to_english_builtin(): void
    {
        $this->save('default');
        $this->assertSame(409, $this->controller->reset(Request::create('/', 'POST', ['name' => 'verify', 'expected_version' => 'default']))->getStatusCode());
        $version = $this->get()['version'];
        $this->assertSame(200, $this->controller->reset(Request::create('/', 'POST', ['name' => 'verify', 'expected_version' => $version]))->getStatusCode());
        $this->assertSame(0, MailTemplate::count());
        $this->assertSame('default', $this->get()['version']);
        $this->assertStringContainsString('Your verification code', $this->get()['content']);
    }

    public function test_missing_required_variables_and_secret_subject_do_not_write(): void
    {
        $this->assertSame(422, $this->save('default', '{{code}}')->getStatusCode());
        $this->assertSame(422, $this->save('default', 'Safe title', '<p>Missing code</p>')->getStatusCode());
        $this->assertSame(0, MailTemplate::count());
        $this->assertSame(404, $this->controller->get(Request::create('/', 'GET', ['name' => '../../secret']))->getStatusCode());
    }

    public function test_actual_db_renderer_preserves_bulk_subject_and_logs_no_smtp_credentials(): void
    {
        MailTemplate::create(['name' => 'notify', 'subject' => '{{subject|Notification}}', 'content' => '<p>{{content}}</p>']);
        $sent = [];
        $transport = Mockery::mock();
        $transport->shouldReceive('html')->once()->andReturnUsing(function ($html, $callback) use (&$sent) {
            $message = new \Symfony\Component\Mime\Email();
            $callback(new \Illuminate\Mail\Message($message));
            $sent = [$message->getSubject(), $html];
        });
        Mail::swap($transport);
        $result = MailService::sendEmail(['email' => 'test_mail@example.invalid', 'subject' => 'Maintenance & updates', 'template_name' => 'notify',
            'template_value' => ['name' => 'Hop', 'content' => 'Safe <script>', 'content_mode' => 'text']]);
        $this->assertSame(['Maintenance & updates', '<p>Safe &lt;script&gt;</p>'], $sent);
        $this->assertSame(['transport' => 'smtp'], json_decode($result['config'], true));
        $this->assertArrayNotHasKey('config', MailLog::first()->getAttributes());
        $this->assertSame('Maintenance & updates', MailLog::first()->subject);
    }

    public function test_smtp_fallback_uses_independent_english_view_without_database_template(): void
    {
        $transport = Mockery::mock();
        $transport->shouldReceive('send')->once()->withArgs(function ($view, $values, $callback) {
            $this->assertSame('mail.default.smtpTest', $view);
            $message = new \Symfony\Component\Mime\Email();
            $callback(new \Illuminate\Mail\Message($message));
            $this->assertSame('Hop test email', $message->getSubject());
            return true;
        });
        Mail::swap($transport);
        MailService::sendEmail(['email' => 'test_mail@example.invalid', 'subject' => 'Hop test email', 'template_name' => 'smtpTest', 'template_value' => ['name' => 'Hop', 'url' => 'https://example.invalid']]);
        $this->assertSame('mail.default.smtpTest', MailLog::first()->template_name);
    }

    public function test_mail_log_fixture_has_the_exact_legacy_columns(): void
    {
        $this->assertSame(['id', 'email', 'subject', 'template_name', 'error', 'created_at', 'updated_at'],
            Manager::schema()->getColumnListing('v2_mail_log'));
    }

    public function test_successful_smtp_is_not_released_when_log_storage_fails(): void
    {
        // This table belongs only to the per-test in-memory SQLite connection.
        Manager::schema()->drop('v2_mail_log');
        $transport = Mockery::mock();
        $transport->shouldReceive('send')->once()->andReturnNull();
        Mail::swap($transport);
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->with('Mail delivery log failed', ['type' => QueryException::class]);
        Log::swap($logger);

        $job = $this->mailJob();
        $job->handle();
        $this->assertSame(0, $job->releaseCount);
    }

    public function test_real_smtp_failure_keeps_retry_signal_and_safe_error_log(): void
    {
        $transport = Mockery::mock();
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('test_sensitive_transport_detail'));
        Mail::swap($transport);
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->with('Mail delivery failed', ['type' => \RuntimeException::class]);
        Log::swap($logger);

        $job = $this->mailJob();
        $job->handle();
        $this->assertSame(1, $job->releaseCount);
        $this->assertSame(\RuntimeException::class, MailLog::first()->error);
        $this->assertStringNotContainsString('test_sensitive_transport_detail', json_encode(MailLog::first()->getAttributes()));
    }

    public function test_successful_smtp_is_not_released_when_both_log_channels_fail(): void
    {
        Manager::schema()->drop('v2_mail_log');
        $transport = Mockery::mock();
        $transport->shouldReceive('send')->once()->andReturnNull();
        Mail::swap($transport);
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->with('Mail delivery log failed', ['type' => QueryException::class])
            ->andThrow(new \RuntimeException('test_sensitive_diagnostic_detail'));
        Log::swap($logger);

        $job = $this->mailJob();
        $job->handle();
        $this->assertSame(0, $job->releaseCount);
    }

    public function test_smtp_and_log_failures_keep_only_the_smtp_retry_signal(): void
    {
        Manager::schema()->drop('v2_mail_log');
        $transport = Mockery::mock();
        $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('test_sensitive_transport_detail'));
        Mail::swap($transport);
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->with('Mail delivery failed', ['type' => \RuntimeException::class])->ordered();
        $logger->shouldReceive('warning')->once()->with('Mail delivery log failed', ['type' => QueryException::class])->ordered();
        Log::swap($logger);

        $job = $this->mailJob();
        $job->handle();
        $this->assertSame(1, $job->releaseCount);
    }

    /** Isolate retry handling from authority storage; no real queue, permits or SMTP are used. */
    private function mailJob(): SendEmailJob
    {
        return new class(['email' => 'test_mail@example.invalid', 'subject' => 'Hop test email', 'template_name' => 'smtpTest',
            'template_value' => ['name' => 'Hop', 'url' => 'https://example.invalid']]) extends SendEmailJob {
            public int $releaseCount = 0;
            protected function scanPermit(string $taskCode): ?array { return null; }
            protected function executionAllowed(?array $permit): bool { return true; }
            public function release($delay = 0) { $this->releaseCount++; }
        };
    }
}
