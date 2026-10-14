<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailTemplate;
use App\Services\MailService;
use App\Services\EnglishMailDefaults;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MailTemplateController extends Controller
{
    public function list()
    {
        $dbTemplates = MailTemplate::all()->keyBy('name');

        $result = [];
        foreach (MailTemplate::TEMPLATES as $name => $meta) {
            $db = $dbTemplates->get($name);
            $result[] = [
                'name' => $name,
                'label' => $meta['label'],
                'customized' => $db !== null,
                'subject' => $db?->subject,
                'updated_at' => $db?->updated_at?->timestamp,
            ];
        }

        return $this->success($result);
    }

    public function get(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        $db = MailTemplate::where('name', $name)->first();

        return $this->success([
            'name' => $name,
            'label' => $meta['label'],
            'required_vars' => $meta['required_vars'],
            'optional_vars' => $meta['optional_vars'],
            'customized' => $db !== null,
            'subject' => $db?->subject ?? $this->getDefaultSubject($name),
            'content' => $db?->content ?? $this->getDefaultContent($name),
            'version' => self::version($db),
        ]);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'name' => 'required|string',
            'subject' => 'required|string|max:255',
            'content' => 'required|string|max:100000',
            'expected_version' => 'nullable|string|max:64',
        ]);

        $meta = MailTemplate::getMeta($params['name']);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        $errors = array_merge(MailTemplate::validateContent($params['name'], $params['content']),
            MailTemplate::validateSubject($params['name'], $params['subject']));
        if (!empty($errors)) {
            return $this->fail([422, implode('; ', $errors)]);
        }

        $saved = DB::transaction(function () use ($params, $request) {
            $current = MailTemplate::where('name', $params['name'])->lockForUpdate()->first();
            if ($request->has('expected_version') && $request->input('expected_version') !== self::version($current)) return false;
            MailTemplate::updateOrCreate(['name' => $params['name']],
                ['subject' => $params['subject'], 'content' => $params['content']]);
            return true;
        });
        if (!$saved) return $this->fail([409, '模板已被修改，请重新加载；未覆盖您的草稿']);
        Cache::forget("mail_template:{$params['name']}");

        return $this->success(true);
    }

    public function reset(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        $reset = DB::transaction(function () use ($name, $request) {
            $current = MailTemplate::where('name', $name)->lockForUpdate()->first();
            if ($request->has('expected_version') && $request->input('expected_version') !== self::version($current)) return false;
            if ($current) $current->delete();
            return true;
        });
        if (!$reset) return $this->fail([409, '模板已被修改，请重新加载；未恢复默认值']);
        Cache::forget("mail_template:{$name}");
        return $this->success(true);
    }

    public function test(Request $request)
    {
        $name = $request->input('name');
        $meta = MailTemplate::getMeta($name);
        if (!$meta) {
            return $this->fail([404, '模板不存在']);
        }

        $email = $request->input('email', $request->user()->email);
        $testVars = $this->getTestVars($name);

        try {
            $log = MailService::sendEmail([
                'email' => $email,
                'subject' => $this->getTestSubject($name),
                'template_name' => $name,
                'template_value' => $testVars,
            ]);

            if ($log['error']) {
                return $this->fail([500, '测试邮件发送失败，请检查SMTP配置和投递记录']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::warning('Mail template test failed', ['type' => get_class($e)]);
            return $this->fail([500, '未确认测试邮件发送成功，请检查投递记录后再操作']);
        }
    }

    private function getTestSubject(string $name): string
    {
        $appName = admin_setting('app_name', 'XBoard');
        return match ($name) {
            'verify' => "{$appName} - verification code test",
            'notify' => "{$appName} - notification test",
            'remindExpire' => "{$appName} - subscription expiry test",
            'remindTraffic' => "{$appName} - data usage test",
            'mailLogin' => "{$appName} - login link test",
            'ticketReply' => "{$appName} - support ticket reply test",
            'smtpTest' => "{$appName} - SMTP test email",
            default => "{$appName} - test email",
        };
    }

    private function getTestVars(string $name): array
    {
        $appName = admin_setting('app_name', 'XBoard');
        $appUrl = admin_setting('app_url', 'https://example.com');

        return match ($name) {
            'verify' => [
                'name' => $appName,
                'code' => '123456',
                'url' => $appUrl,
            ],
            'notify' => [
                'name' => $appName,
                'content' => 'This is a test notification email.',
                'subject' => "{$appName} notification test",
                'url' => $appUrl,
            ],
            'remindExpire' => [
                'name' => $appName,
                'url' => $appUrl,
            ],
            'remindTraffic' => [
                'name' => $appName,
                'url' => $appUrl,
            ],
            'mailLogin' => [
                'name' => $appName,
                'link' => $appUrl . '/login?token=test-token',
                'url' => $appUrl,
            ],
            'ticketReply' => [
                'name' => $appName,
                'ticketSubject' => 'Connection assistance',
                'replyMessage' => 'We have reviewed your request. Please try again.',
                'url' => $appUrl,
            ],
            default => ['name' => $appName, 'url' => $appUrl],
        };
    }

    private function getDefaultSubject(string $name): string
    {
        return EnglishMailDefaults::subject($name);
    }

    private function getDefaultContent(string $name): string
    {
        return EnglishMailDefaults::content($name);
    }

    /** Content fingerprints avoid second-resolution timestamp conflicts without schema changes. */
    private static function version(?MailTemplate $template): string
    {
        return $template === null ? 'default' : hash('sha256', $template->subject . "\0" . $template->content);
    }
}
