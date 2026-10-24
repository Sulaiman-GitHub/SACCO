<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Sacco;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The installable-app files, the markup that wires them into every layout, and the rule that the
 * offline worker never stores pages or touches writes.
 */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    private function publicFile(string $path): string
    {
        $full = public_path($path);
        $this->assertFileExists($full);

        return (string) file_get_contents($full);
    }

    public function test_the_manifest_describes_an_installable_app(): void
    {
        $manifest = json_decode($this->publicFile('manifest.webmanifest'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('SACCO Credit Intelligence', $manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('any', $manifest['orientation']);
        $this->assertSame('#0F172A', $manifest['theme_color']);
        $this->assertSame('#F5F6F7', $manifest['background_color']);

        $purposes = [];
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            $purposes[$icon['sizes'].'|'.$icon['purpose']] = true;
        }

        $this->assertArrayHasKey('192x192|any', $purposes);
        $this->assertArrayHasKey('512x512|any', $purposes);
        $this->assertArrayHasKey('512x512|maskable', $purposes);
    }

    public function test_icons_have_the_declared_sizes(): void
    {
        foreach (['icon-192.png' => 192, 'icon-512.png' => 512, 'icon-maskable-512.png' => 512, 'apple-touch-icon.png' => 180] as $file => $size) {
            [$width, $height] = getimagesize(public_path('icons/'.$file));
            $this->assertSame([$size, $size], [$width, $height], $file);
        }

        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_the_offline_page_is_self_contained_and_says_nothing_was_saved(): void
    {
        $html = $this->publicFile('offline.html');

        $this->assertStringContainsString("You're offline", $html);
        $this->assertStringContainsString('Nothing you were entering was saved.', $html);
        $this->assertStringContainsString('Try again', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $html);
    }

    public function test_the_service_worker_never_stores_pages_or_intercepts_writes(): void
    {
        $worker = $this->publicFile('sw.js');

        $this->assertMatchesRegularExpression("/const VERSION = 'v\\d+'/", $worker);
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        $this->assertStringContainsString("pathname.indexOf('/api') === 0", $worker);
        $this->assertStringContainsString('no-store', $worker);
        $this->assertStringContainsString('CLEAR_RUNTIME_CACHES', $worker);

        // Navigations go to the network and fall back only to the stored offline page; nothing is put in a cache for them.
        $this->assertMatchesRegularExpression("/mode === 'navigate'.*?fetch\\(request\\)\\.catch/s", $worker);
        $this->assertSame(1, substr_count($worker, 'cache.put('), 'Only the static-asset path may write to a cache.');
    }

    public function test_staff_pages_include_the_app_tags_and_scripts(): void
    {
        $sacco = Sacco::factory()->create(['status' => 'active']);
        $admin = User::factory()->create(['sacco_id' => $sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);

        $response = $this->actingAs($admin)->get(route('tenant.dashboard'));

        $response->assertOk();
        $this->assertAppTags($response->getContent());
        $response->assertSee('data-install-wrap', false);
        $response->assertDontSee('cdn.jsdelivr.net', false);
    }

    public function test_member_portal_pages_include_the_app_tags_and_scripts(): void
    {
        $sacco = Sacco::factory()->create(['status' => 'active']);
        $admin = User::factory()->create(['sacco_id' => $sacco->id, 'role' => 'sacco_admin', 'status' => 'active']);
        $member = Member::factory()->consented($admin)->create(['sacco_id' => $sacco->id, 'status' => 'active']);
        $member->issuePortalCredentials();
        $member->forceFill(['portal_must_change_password' => false])->save();

        $this->actingAs($member->fresh(), 'member');
        Auth::shouldUse('web');

        $response = $this->get(route('portal.dashboard'));

        $response->assertOk();
        $this->assertAppTags($response->getContent());
        $response->assertSee('data-install-wrap', false);
    }

    public function test_sign_in_pages_include_the_app_tags_but_no_install_prompt(): void
    {
        foreach ([route('login'), route('portal.login')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertAppTags($response->getContent());
            $response->assertDontSee('data-install-wrap', false);
        }
    }

    private function assertAppTags(string $html): void
    {
        $this->assertStringContainsString('<link rel="manifest" href="', $html);
        $this->assertStringContainsString('manifest.webmanifest', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#0F172A">', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-title"', $html);
        $this->assertMatchesRegularExpression('#js/offline-guard\.js\?v=\d+#', $html);
        $this->assertMatchesRegularExpression('#js/pwa\.js\?v=\d+#', $html);
    }

    public function test_an_expired_form_returns_to_the_page_with_the_typed_values_and_a_plain_message(): void
    {
        // The framework skips the CSRF check while tests run, so a route that raises the same 419 stands in for it.
        Route::post('/expired-form-probe', fn () => abort(419))->middleware('web');

        $response = $this->from(route('login'))
            ->post('/expired-form-probe', ['_token' => 'stale', 'amount' => '25000', 'password' => 'secret']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('session_expired');
        $this->assertSame('25000', session()->getOldInput('amount'));
        $this->assertNull(session()->getOldInput('password'));
    }
}
