<?php

namespace Tests\Feature;

use App\Mail\AdminLoginCodeMail;
use Tests\TestCase;

class AdminLoginCodeMailTest extends TestCase
{
    public function test_login_code_and_expiry_render_in_both_markdown_mail_formats(): void
    {
        $mail = new AdminLoginCodeMail('012345');

        $mail->assertSeeInHtml('Your login code');
        $mail->assertSeeInHtml('012345');
        $mail->assertSeeInHtml('This code expires in 10 minutes.');
        $mail->assertSeeInText('012345');
        $mail->assertSeeInText('This code expires in 10 minutes.');
    }

    public function test_custom_expiry_and_application_name_are_rendered_safely(): void
    {
        config(['app.name' => '<script>alert(1)</script>']);

        $mail = new AdminLoginCodeMail('654321', expiresInMinutes: 5);

        $mail->assertSeeInHtml('This code expires in 5 minutes.');
        $mail->assertSeeInText('This code expires in 5 minutes.');
        $mail->assertSeeInHtml('<script>alert(1)</script>');
        $mail->assertDontSeeInHtml('<script>', escape: false);
    }
}
