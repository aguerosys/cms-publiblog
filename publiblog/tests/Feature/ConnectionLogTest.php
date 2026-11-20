<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Services\Remote\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;

/** «Probar conexión»: si no se llega al servidor SSH, el panel es genérico pero el motivo real queda en el log. */
class ConnectionLogTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();
    }

    protected function tearDown(): void
    {
        $this->tearDownBlogs();
        parent::tearDown();
    }

    public function test_an_unreachable_server_is_reported_generically_and_the_reason_is_logged_without_credentials(): void
    {
        Log::spy();

        $blog = $this->makeBlog([
            'sftp_auth' => 'password', 'sftp_host' => '127.0.0.1', 'sftp_port' => 1,
            'sftp_user' => 'secreto-usuario', 'sftp_secret' => 'secreto-clave', 'status' => Blog::STATUS_PENDING,
        ]);

        $result = app(ConnectionTester::class)->test($blog);

        $this->assertFalse($result['ok']);
        $this->assertSame('connect', $result['code']);
        $this->assertSame('No se pudo conectar con el servidor.', $result['message']); // el panel no cambia

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($blog) {
            return str_contains($message, 'no llegó al servidor SSH')
                && $context['blog_id'] === $blog->id
                && $context['host'] === '127.0.0.1'
                && $context['port'] === 1
                && $context['reason'] !== ''
                && ! str_contains(json_encode($context), 'secreto');
        });
    }
}
