<?php

namespace App\Services;

use InvalidArgumentException;

/** Single-language defaults shared by sending, template editing and initialization. */
final class EnglishMailDefaults
{
    public const SUBJECTS = [
        'verify' => '{{name}} email verification code',
        'mailLogin' => 'Log in to {{name}}',
        'notify' => '{{subject|Notification}}',
        'ticketReply' => 'Your {{name}} support ticket received a reply',
        'remindExpire' => '{{name}} subscription expiry reminder',
        'remindTraffic' => '{{name}} data usage reminder',
        'smtpTest' => '{{name}} test email',
    ];

    public static function subject(string $name): string
    {
        return self::SUBJECTS[$name] ?? throw new InvalidArgumentException('Unknown mail template');
    }

    /** Convert the maintained Blade view to the editable, non-executable placeholder format. */
    public static function content(string $name, ?string $viewRoot = null): string
    {
        self::subject($name);
        $root = $viewRoot ?? resource_path('views/mail/default');
        $content = file_get_contents($root . '/' . $name . '.blade.php');
        if ($content === false) throw new InvalidArgumentException('Mail template resource unavailable');
        $content = preg_replace('/\{\{\s*\$([a-zA-Z_]+)\s*\}\}/', '{{$1}}', $content);
        return preg_replace('/\{!!\s*nl2br\(\$content\)\s*!!\}/', '{{content}}', $content);
    }
}
