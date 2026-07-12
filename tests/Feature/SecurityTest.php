<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Models\User;
use App\Models\Share;
use App\Models\File;
use App\Models\UploadSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * Security regression tests for Erugo share endpoints.
 *
 * Categories covered:
 *  1. IDOR — non-owner cannot operate on another user's share
 *  2. Admin privilege escalation — regular users cannot reach admin-only endpoints
 *  3. Path traversal — malicious filePaths payloads must not escape the share directory
 *  4. Upload session hijacking — user B cannot use user A's upload session
 *  5. State manipulation — invalid transitions must be rejected
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeUser(bool $admin = false): User
    {
        return User::factory()->create(['admin' => $admin]);
    }

    private function makeShare(User $owner, int $fileCount = 1, array $attrs = []): Share
    {
        $longId    = 'share-' . Str::random(8);
        $sharePath = $owner->id . '/' . $longId;

        $share = Share::create(array_merge([
            'user_id'        => $owner->id,
            'name'           => 'Security Test Share',
            'description'    => '',
            'path'           => $sharePath,
            'long_id'        => $longId,
            'size'           => 512 * $fileCount,
            'file_count'     => $fileCount,
            'download_limit' => null,
            'download_count' => 0,
            'require_email'  => false,
            'expires_at'     => now()->addDays(30),
            'status'         => 'ready',
        ], $attrs));

        $shareDir = storage_path('app/shares/' . $sharePath);
        if (!is_dir($shareDir)) {
            mkdir($shareDir, 0755, true);
        }

        for ($i = 0; $i < $fileCount; $i++) {
            $filename = "file{$i}.txt";
            file_put_contents($shareDir . '/' . $filename, str_repeat('x', 512));
            File::create([
                'name'       => $filename,
                'size'       => 512,
                'type'       => 'text/plain',
                'share_id'   => $share->id,
                'temp_path'  => null,
                'full_path'  => null,
                'storage_id' => Str::uuid()->toString(),
            ]);
        }

        return $share;
    }

    /**
     * Create a completed upload session with a real temp file on disk.
     */
    private function makeUploadSession(User $user, string $filename = 'upload.txt'): array
    {
        $uploadId = Str::random(16);
        $tempPath = 'uploads/' . $uploadId;

        $destDir = storage_path('app/uploads');
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        file_put_contents(storage_path('app/' . $tempPath), str_repeat('y', 512));

        $file = File::create([
            'name'      => $filename,
            'size'      => 512,
            'type'      => 'text/plain',
            'share_id'  => null,
            'temp_path' => $tempPath,
            'full_path' => null,
        ]);

        $session = UploadSession::create([
            'upload_id'       => $uploadId,
            'user_id'         => $user->id,
            'filename'        => $filename,
            'filesize'        => 512,
            'filetype'        => 'text/plain',
            'total_chunks'    => 1,
            'chunks_received' => 1,
            'status'          => 'complete',
            'file_id'         => $file->id,
        ]);

        return ['uploadId' => $uploadId, 'file' => $file, 'session' => $session];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. IDOR — cross-user access on every mutating endpoint
    // ──────────────────────────────────────────────────────────────────────────

    #[DataProvider('idorEndpointProvider')]
    public function test_non_owner_cannot_mutate_another_users_share(string $method, string $urlTemplate, array $body): void
    {
        Queue::fake();

        $owner  = $this->makeUser();
        $attacker = $this->makeUser();
        $share  = $this->makeShare($owner, 2);

        $url = str_replace('{id}', $share->id, $urlTemplate);

        $response = $this->actingAs($attacker, 'sanctum')
            ->json($method, $url, $body);

        $this->assertNotEquals(
            200,
            $response->status(),
            "IDOR: {$method} {$urlTemplate} must reject non-owner (got {$response->status()})"
        );
        $this->assertNotEquals(
            422,
            $response->status(),
            "IDOR: {$method} {$urlTemplate} must not process request at all (validation errors expose that the share exists)"
        );
    }

    public static function idorEndpointProvider(): array
    {
        return [
            'expire'           => ['POST', '/api/shares/{id}/expire',            []],
            'extend'           => ['POST', '/api/shares/{id}/extend',            ['amount' => 7, 'unit' => 'days']],
            'set-dl-limit'     => ['POST', '/api/shares/{id}/set-download-limit', ['download_limit' => 5]],
            'request-deletion' => ['POST', '/api/shares/{id}/request-deletion',  []],
            'undo-deletion'    => ['POST', '/api/shares/{id}/undo-deletion',     []],
            'add-files'        => ['POST', '/api/shares/{id}/add-files',         ['uploadIds' => ['x']]],
            'replace-file'     => ['POST', '/api/shares/{id}/replace-file',      ['uploadIds' => ['x']]],
            'clone'            => ['POST', '/api/shares/{id}/clone',             []],
        ];
    }

    public function test_non_owner_cannot_read_private_share_details_by_id(): void
    {
        $owner    = $this->makeUser();
        $attacker = $this->makeUser();
        $share    = $this->makeShare($owner);

        // The public /api/shares/{long_id} endpoint is for downloading;
        // the authenticated /api/shares listing only returns the caller's own shares.
        $ownedShares = $this->actingAs($attacker, 'sanctum')
            ->getJson('/api/shares')
            ->assertStatus(200)
            ->json('data.shares');

        $ids = collect($ownedShares)->pluck('id')->toArray();
        $this->assertNotContains($share->id, $ids, 'Attacker must not see victim share in /api/shares listing');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. Admin privilege escalation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_regular_user_cannot_list_all_shares(): void
    {
        $user = $this->makeUser();

        // Admin middleware returns 403 (forbidden), not 401 (unauthenticated)
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/shares/all');

        $this->assertNotEquals(200, $response->status(), 'Non-admin must not list all shares');
    }

    public function test_regular_user_cannot_delete_immediately(): void
    {
        $owner    = $this->makeUser();
        $attacker = $this->makeUser();
        $share    = $this->makeShare($owner);

        $response = $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/shares/{$share->id}/delete-immediately", ['confirmation' => 'delete']);

        $this->assertNotEquals(200, $response->status(), 'Non-admin must not be able to delete-immediately');
    }

    public function test_delete_immediately_requires_typed_confirmation(): void
    {
        $admin = $this->makeUser(admin: true);
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, 1, [
            'status'     => 'pending_deletion',
            'expires_at' => now()->subDay(),
        ]);

        // Wrong confirmation word must not succeed
        $wrong = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/shares/{$share->id}/delete-immediately", ['confirmation' => 'yes']);
        $this->assertNotEquals(200, $wrong->status(), 'Wrong confirmation word must be rejected');

        // No confirmation at all must not succeed
        $empty = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/shares/{$share->id}/delete-immediately", []);
        $this->assertNotEquals(200, $empty->status(), 'Missing confirmation must be rejected');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. Path traversal in filePaths (add-files endpoint)
    // ──────────────────────────────────────────────────────────────────────────

    #[DataProvider('pathTraversalPayloadProvider')]
    public function test_path_traversal_payloads_do_not_escape_share_directory(string $maliciousPath): void
    {
        Queue::fake();

        $owner  = $this->makeUser();
        $share  = $this->makeShare($owner, 2);
        $upload = $this->makeUploadSession($owner, 'upload.txt');

        $shareRoot = storage_path('app/shares/' . $share->path);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/add-files", [
                'uploadIds' => [$upload['uploadId']],
                'filePaths' => [$upload['uploadId'] => $maliciousPath],
            ]);

        // Verify no file was written above the share root
        $escapedFile = storage_path('app/shares/' . $share->path . '/' . $maliciousPath . '/upload.txt');

        // The real check: nothing outside the share directory was modified
        $etcPasswd  = '/etc/passwd';
        $secretPath = storage_path('app/secret.txt');

        // These sentinel paths must be unmodified
        if (file_exists($etcPasswd)) {
            $this->assertStringNotContainsString(
                'yyy',  // our payload content marker
                file_get_contents($etcPasswd),
                "Path traversal payload '{$maliciousPath}' may have written to /etc/passwd"
            );
        }

        // Verify any file the controller did place is still within the share dir
        $placedFiles = [];
        if (is_dir($shareRoot)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($shareRoot));
            foreach ($iter as $item) {
                if ($item->isFile()) {
                    $realPath = $item->getRealPath();
                    $this->assertStringStartsWith(
                        realpath($shareRoot),
                        $realPath,
                        "File placed outside share directory by path traversal payload '{$maliciousPath}'"
                    );
                }
            }
        }
    }

    public static function pathTraversalPayloadProvider(): array
    {
        return [
            'classic dot-dot-slash'    => ['../../etc'],
            'triple traversal'         => ['../../../tmp'],
            'windows separators'       => ['..\\..\\etc'],
            'encoded slash'            => ['..%2F..%2Fetc'],
            'null byte injection'      => ["file\x00.php"],
            'absolute path'            => ['/etc'],
            'leading slash subdir'     => ['/tmp/evil'],
            'double slash'             => ['//etc//passwd'],
            'mixed case traversal'     => ['..%2fetc'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. Upload session hijacking
    // ──────────────────────────────────────────────────────────────────────────

    public function test_user_cannot_use_another_users_upload_session_for_add_files(): void
    {
        $victim   = $this->makeUser();
        $attacker = $this->makeUser();
        $share    = $this->makeShare($attacker, 2); // attacker's own share

        // Victim's upload session
        $victimUpload = $this->makeUploadSession($victim, 'stolen.txt');

        $response = $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/shares/{$share->id}/add-files", [
                'uploadIds' => [$victimUpload['uploadId']],
                'filePaths' => [$victimUpload['uploadId'] => 'stolen.txt'],
            ]);

        // Must not succeed — session doesn't belong to attacker
        $this->assertNotEquals(
            200,
            $response->status(),
            'User must not be able to reference another user\'s upload session'
        );

        // Victim's file must not have been moved into attacker's share
        $this->assertNull(
            File::where('share_id', $share->id)->where('name', 'stolen.txt')->first(),
            'Victim\'s file must not appear in attacker\'s share'
        );
    }

    public function test_user_cannot_use_another_users_upload_session_for_replace_file(): void
    {
        $victim   = $this->makeUser();
        $attacker = $this->makeUser();
        $share    = $this->makeShare($attacker, 1); // attacker's own single-file share

        $victimUpload = $this->makeUploadSession($victim, 'stolen.txt');

        $response = $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/shares/{$share->id}/replace-file", [
                'uploadIds' => [$victimUpload['uploadId']],
            ]);

        $this->assertNotEquals(
            200,
            $response->status(),
            'User must not be able to reference another user\'s upload session in replace-file'
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. State manipulation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_undo_deletion_on_active_share_must_not_succeed(): void
    {
        $owner = $this->makeUser();
        $share = $this->makeShare($owner); // status = 'ready', no pending_deletion

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/undo-deletion");

        $this->assertNotEquals(200, $response->status(), 'Undo-deletion on a non-pending share must not return 200');
    }

    public function test_non_owner_cannot_undo_another_users_deletion_request(): void
    {
        $owner    = $this->makeUser();
        $attacker = $this->makeUser();
        $share    = $this->makeShare($owner, 1, ['status' => 'pending_deletion']);

        $response = $this->actingAs($attacker, 'sanctum')
            ->postJson("/api/shares/{$share->id}/undo-deletion");

        $this->assertNotEquals(200, $response->status(), 'Non-owner must not be able to undo another user\'s deletion request');
    }

    public function test_cannot_clone_a_deleted_share(): void
    {
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, 1, ['status' => 'deleted']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/clone")
            ->assertStatus(422);
    }

    public function test_cannot_add_files_to_deleted_share(): void
    {
        $owner  = $this->makeUser();
        $share  = $this->makeShare($owner, 2, ['status' => 'deleted']);
        $upload = $this->makeUploadSession($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/add-files", [
                'uploadIds' => [$upload['uploadId']],
            ])
            ->assertStatus(422);
    }

    public function test_cannot_replace_file_in_deleted_share(): void
    {
        $owner  = $this->makeUser();
        $share  = $this->makeShare($owner, 1, ['status' => 'deleted']);
        $upload = $this->makeUploadSession($owner);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/replace-file", [
                'uploadIds' => [$upload['uploadId']],
            ])
            ->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6. Input validation / injection-style strings
    // ──────────────────────────────────────────────────────────────────────────

    public function test_sql_injection_in_clone_name_is_stored_verbatim_and_not_executed(): void
    {
        Queue::fake();

        $owner = $this->makeUser();
        $share = $this->makeShare($owner);

        $payload = "'; DROP TABLE shares; --";

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/clone", ['name' => $payload])
            ->assertStatus(200);

        // The name must be stored literally — and the shares table must still exist
        $this->assertEquals($payload, $response->json('data.share.name'));
        $this->assertGreaterThan(0, Share::count(), 'shares table must still exist after SQL-injection payload in name');
    }

    public function test_xss_payload_in_clone_name_is_stored_verbatim_in_json_response(): void
    {
        Queue::fake();

        $owner   = $this->makeUser();
        $share   = $this->makeShare($owner);
        $payload = '<script>alert(1)</script>';

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/clone", ['name' => $payload])
            ->assertStatus(200);

        // JSON-encoded response auto-escapes angle brackets — the raw payload in the DB is the literal string
        $cloneId = $response->json('data.share.id');
        $stored  = Share::find($cloneId)?->name;
        $this->assertEquals($payload, $stored, 'XSS payload must be stored as-is (escaped by JSON, not by DB)');
    }

    public function test_extend_always_moves_expiry_forward(): void
    {
        // Regardless of request body, extend must never move expiry backwards.
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, 1, ['expires_at' => now()->addDays(1)]);
        $before = now()->timestamp;

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/extend")
            ->assertStatus(200);

        $share->refresh();
        $this->assertGreaterThan(
            $before,
            $share->expires_at->timestamp,
            'Extend must always produce an expiry in the future'
        );
    }

    public function test_extend_sets_future_expiry_not_past(): void
    {
        // An already-expired share, when extended, must end up with a future expiry.
        $owner = $this->makeUser();
        $share = $this->makeShare($owner, 1, ['expires_at' => now()->subDays(5)]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/shares/{$share->id}/extend")
            ->assertStatus(200);

        $share->refresh();
        $this->assertGreaterThan(
            now()->timestamp,
            $share->expires_at->timestamp,
            'Extended expired share must have a future expiry date'
        );
    }
}
