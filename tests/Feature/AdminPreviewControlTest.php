<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPreviewControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_query_or_change_preview_control(): void
    {
        Http::preventStrayRequests();
        $this->getJson('/admin/preview/status')->assertUnauthorized();
        $this->postJson('/admin/preview/control', ['enabled' => true])->assertUnauthorized();

        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user)->getJson('/admin/preview/status')->assertForbidden();
        $this->postJson('/admin/preview/control', ['enabled' => true])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unconfigured_control_is_visible_but_cannot_change_preview(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->superAdmin())
            ->getJson('/admin/preview/status')
            ->assertOk()
            ->assertJson(['configured' => false, 'enabled' => null]);
        $this->postJson('/admin/preview/control', ['enabled' => true])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_super_admin_reads_and_changes_preview_over_signed_https(): void
    {
        $this->configureControl();
        Http::fake(function (Request $request) {
            $this->assertSame('https://vorschau.gkstocks.at/preview/control', $request->url());
            $this->assertValidSignature($request);

            return Http::response(['enabled' => $request->method() === 'POST'], 200, ['X-Stocks-Preview' => 'true']);
        });

        $this->actingAs($this->superAdmin())
            ->getJson('/admin/preview/status')
            ->assertOk()
            ->assertJson(['configured' => true, 'enabled' => false]);
        $this->postJson('/admin/preview/control', ['enabled' => true])
            ->assertOk()
            ->assertJson(['configured' => true, 'enabled' => true]);
        Http::assertSentCount(2);
    }

    public function test_remote_response_without_preview_identity_is_rejected(): void
    {
        $this->configureControl();
        Http::fake(['https://vorschau.gkstocks.at/preview/control' => Http::response(['enabled' => true])]);

        $this->actingAs($this->superAdmin())
            ->postJson('/admin/preview/control', ['enabled' => true])
            ->assertStatus(503);
    }

    private function configureControl(): void
    {
        config([
            'security.preview.control_url' => 'https://vorschau.gkstocks.at/preview/control',
            'security.preview.control_key' => str_repeat('a', 64),
        ]);
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin');
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function assertValidSignature(Request $request): void
    {
        $timestamp = $request->header('X-Stocks-Control-Time')[0];
        $nonce = $request->header('X-Stocks-Control-Nonce')[0];
        $signature = $request->header('X-Stocks-Control-Signature')[0];
        $this->assertLessThanOrEqual(60, abs(time() - (int) $timestamp));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $nonce);
        $message = $request->method()."\npreview/control\n".$timestamp."\n".$nonce."\n".hash('sha256', $request->body());
        $this->assertSame(hash_hmac('sha256', $message, hex2bin(str_repeat('a', 64))), $signature);
    }
}
