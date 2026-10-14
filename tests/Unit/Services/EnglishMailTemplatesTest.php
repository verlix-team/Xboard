<?php

namespace Tests\Unit\Services;

use App\Models\MailTemplate;
use App\Services\EnglishMailDefaults;
use App\Services\MailService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EnglishMailTemplatesTest extends TestCase
{
    public function test_all_seven_templates_have_english_defaults_in_both_themes(): void
    {
        $this->assertCount(7, MailTemplate::TEMPLATES);
        $this->assertEqualsCanonicalizing(MailTemplate::getNames(), array_keys(EnglishMailDefaults::SUBJECTS));
        foreach (['default', 'classic'] as $theme) {
            foreach (MailTemplate::getNames() as $name) {
                $content = EnglishMailDefaults::content($name, dirname(__DIR__, 3) . '/resources/views/mail/' . $theme);
                $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', $content);
                $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', EnglishMailDefaults::subject($name));
                $this->assertStringContainsString('Please do not reply', $content);
                $this->assertSame([], MailTemplate::validateContent($name, $content));
                $this->assertSame([], MailTemplate::validateSubject($name, EnglishMailDefaults::subject($name)));
                $this->assertStringNotContainsString('{{$', $content);
                $this->assertStringNotContainsString('{!!', $content);
            }
        }
    }

    public function test_ticket_template_owns_labels_and_escapes_untranslated_user_text(): void
    {
        $vars = (new ReflectionMethod(MailService::class, 'buildSafeVars'))->invoke(null, [
            'name' => 'Hop', 'url' => 'https://example.invalid', 'ticketSubject' => '用户问题 <script>', 'replyMessage' => '客服回复 <img src=x>',
        ]);
        $content = EnglishMailDefaults::content('ticketReply', dirname(__DIR__, 3) . '/resources/views/mail/default');
        $html = (new ReflectionMethod(MailService::class, 'renderPlaceholders'))->invoke(null, $content, $vars);
        $this->assertStringContainsString('Subject: 用户问题 &lt;script&gt;', $html);
        $this->assertStringContainsString('Reply: 客服回复 &lt;img src=x&gt;', $html);
        $this->assertStringNotContainsString('回复内容：', $html);
    }

    public function test_notification_default_preserves_administrator_subject_and_required_body(): void
    {
        $render = new ReflectionMethod(MailService::class, 'renderPlaceholders');
        $subject = $render->invoke(null, EnglishMailDefaults::subject('notify'), ['subject' => 'Service maintenance & updates']);
        $this->assertSame('Service maintenance & updates', $subject);
        $this->assertNotEmpty(MailTemplate::validateSubject('notify', 'Fixed title'));
        $this->assertNotEmpty(MailTemplate::validateContent('notify', 'Missing body'));
    }

    public function test_required_placeholders_and_sensitive_subjects_are_rejected(): void
    {
        foreach (['verify', 'mailLogin', 'ticketReply'] as $name) $this->assertNotEmpty(MailTemplate::validateContent($name, '<p>Missing variables</p>'));
        foreach (["Hello\r\nBcc: bad", '{{code}}', '{{link}}'] as $subject) $this->assertNotEmpty(MailTemplate::validateSubject('verify', $subject));
    }

    public function test_unknown_template_cannot_read_arbitrary_view_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnglishMailDefaults::content('../../config', dirname(__DIR__, 3) . '/resources/views/mail/default');
    }
}
