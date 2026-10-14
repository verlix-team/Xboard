<?php
/** Isolated probe of the actual MailService file: no kernel boot, real DB/Redis, queue or SMTP. */
try {
    $root = getenv('HOP_MAIL_PROBE_ROOT') ?: '/www';
    $loader = require (getenv('LEGACY_MAIL_VENDOR') ?: '/www/vendor/autoload.php');
    $loader->addClassMap([
        App\Services\MailService::class => $root . '/app/Services/MailService.php',
        App\Support\Setting::class => $root . '/app/Support/Setting.php',
    ]);
    if (class_exists(App\Models\MailLog::class, false)) throw new RuntimeException('Probe must run before application boot');

    class ProbeMailLog {
        public static bool $fail = false;
        public static array $rows = [];
        public static function create(array $row) {
            if (self::$fail) throw new RuntimeException('Injected audit storage failure');
            self::$rows[] = $row;
        }
    }
    class_alias(ProbeMailLog::class, App\Models\MailLog::class);
    class ProbeSettings extends App\Support\Setting {
        public function __construct() {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
    }
    class ProbeCache {
        public function get($key) { return 'none'; }
    }
    class ProbeTransport {
        public int $calls = 0;
        public function __construct(private bool $fail) {}
        public function send($view, $values, $callback) {
            $this->calls++;
            if ($this->fail) throw new RuntimeException('Injected SMTP failure');
            $callback(new Illuminate\Mail\Message(new Symfony\Component\Mime\Email()));
        }
    }
    class ProbeLogger {
        public function __construct(private bool $fail) {}
        public function warning($event, $context) {
            if (array_keys($context) !== ['type']) throw new LogicException('Unsafe diagnostic context');
            if ($this->fail) throw new RuntimeException('Injected diagnostic failure');
        }
    }

    $app = new Illuminate\Foundation\Application($root);
    $app->instance('config', new Illuminate\Config\Repository());
    $app->instance('cache', new ProbeCache());
    $app->instance(App\Support\Setting::class, new ProbeSettings());
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $cases = [[false, false, false], [false, true, false], [false, true, true], [true, false, false], [true, true, false]];
    $results = [];
    foreach ($cases as [$smtpFail, $logFail, $diagnosticFail]) {
        ProbeMailLog::$fail = $logFail;
        ProbeMailLog::$rows = [];
        $transport = new ProbeTransport($smtpFail);
        Illuminate\Support\Facades\Mail::swap($transport);
        Illuminate\Support\Facades\Log::swap(new ProbeLogger($diagnosticFail));
        $result = App\Services\MailService::sendEmail([
            'email' => 'test_mail_probe@example.invalid', 'subject' => 'Hop isolated probe', 'template_name' => 'smtpTest',
            'template_value' => ['name' => 'Hop', 'url' => 'https://example.invalid'],
        ]);
        if ($transport->calls !== 1 || (bool) $result['error'] !== $smtpFail
            || $result['config'] !== json_encode(['transport' => 'smtp'])
            || count(ProbeMailLog::$rows) !== ($logFail ? 0 : 1)) {
            throw new RuntimeException('Unexpected SMTP/log result');
        }
        $results[] = ['smtpFailure' => $smtpFail, 'auditFailure' => $logFail, 'diagnosticFailure' => $diagnosticFail,
            'fakeTransportCalls' => $transport->calls, 'retryRequired' => (bool) $result['error'], 'configUnchanged' => true];
    }
    echo json_encode(['result' => 'PASS', 'sourceSha256' => hash_file('sha256', $root . '/app/Services/MailService.php'),
        'cases' => $results, 'databaseWrites' => 0, 'redisCalls' => 0, 'queueCalls' => 0, 'realSmtpCalls' => 0]), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['result' => 'FAILED', 'type' => get_class($error)]) . PHP_EOL);
    exit(1);
}
