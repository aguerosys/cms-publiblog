<?php

namespace Tests\Feature;

use App\Exceptions\PublishException;
use App\Models\Blog;
use App\Services\Remote\FtpsCertificateMismatch;
use App\Services\Remote\FtpsServerCertificate;
use App\Services\Remote\PinnedFtpConnectionProvider;
use App\Services\Remote\RemoteErrorMapper;
use App\Services\Remote\RemoteFilesystemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\UnableToAuthenticate as FtpUnableToAuthenticate;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use League\Flysystem\Ftp\UnableToResolveConnectionRoot;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;
use Tests\Support\InteractsWithBlogs;
use Tests\TestCase;
use Throwable;

/** FTPS (FTP con TLS explícito): conexión, certificado (huella o autoridad), errores y alta del blog en el panel. */
class FtpsTest extends TestCase
{
    use InteractsWithBlogs, RefreshDatabase;

    private const PIN_A = 'sha256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const PIN_B = 'sha256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

    private FakeFtpsCertificate $certificate;
    private FakeFtpsFactory $factory;

    /** @var list<array{process:resource, file:string}> */
    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBlogs();

        $this->certificate = new FakeFtpsCertificate();
        $this->factory = new FakeFtpsFactory($this->remoteDir);
        $this->app->instance(FtpsServerCertificate::class, $this->certificate);
        $this->app->instance(RemoteFilesystemFactory::class, $this->factory);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            @proc_terminate($server['process']);
            @proc_close($server['process']);
            @unlink($server['file']);
        }

        $this->tearDownBlogs();
        parent::tearDown();
    }

    private function ftpsBlog(array $overrides = []): Blog
    {
        return $this->makeBlog($overrides + [
            'protocol' => 'ftps', 'sftp_auth' => 'password', 'sftp_host' => 'ftp.cliente.com', 'sftp_port' => 21,
            'sftp_user' => 'blog', 'sftp_secret' => 'secreto', 'status' => Blog::STATUS_PENDING,
        ]);
    }

    private function confirm(Blog $blog, string $fingerprint): void
    {
        $this->post(route('blogs.confirm-fingerprint', $blog), ['fingerprint' => $fingerprint])->assertSessionHas('status');
    }

    // ───────── lectura real del certificado (proceso aparte que habla FTP+TLS) ─────────

    private function startServer(string $mode = 'ok', int $connections = 1): int
    {
        $file = storage_path('framework/testing/ftps-port-'.bin2hex(random_bytes(4)));
        @mkdir(dirname($file), 0777, true);

        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/fake_ftps_server.php'), $file, base_path('tests/Fixtures/ftps/server.pem'), $mode, (string) $connections],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->servers[] = ['process' => $process, 'file' => $file];

        for ($i = 0; $i < 100 && ! is_file($file); $i++) {
            usleep(50_000);
        }

        $this->assertFileExists($file, 'el servidor FTPS falso no arrancó');

        return (int) file_get_contents($file);
    }

    private function fixtureFingerprint(): string
    {
        $pem = file_get_contents(base_path('tests/Fixtures/ftps/server.pem'));
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m);

        return 'sha256:'.rtrim(base64_encode((string) openssl_x509_fingerprint($m[0], 'sha256', true)), '=');
    }

    public function test_the_certificate_fingerprint_is_read_from_a_real_tls_handshake(): void
    {
        $port = $this->startServer('ok', 2);
        $reader = new FtpsServerCertificate();

        $this->assertSame($this->fixtureFingerprint(), $reader->fingerprint('127.0.0.1', $port, 5));
        $this->assertMatchesRegularExpression('/^sha256:[A-Za-z0-9+\/]{43}$/', $this->fixtureFingerprint()); // mismo formato que la huella SSH
    }

    public function test_a_self_signed_certificate_is_not_valid_for_the_trusted_authorities(): void
    {
        $port = $this->startServer('ok', 1);

        $this->assertFalse((new FtpsServerCertificate())->verifiesAgainstAuthorities('127.0.0.1', $port, 5));
    }

    public function test_reading_the_certificate_fails_with_a_clear_reason_when_the_server_is_not_ftps(): void
    {
        $reader = new FtpsServerCertificate();

        $noTls = $this->startServer('no-tls');
        $this->assertReasonContains('no ofrece TLS', fn () => $reader->fingerprint('127.0.0.1', $noTls, 5));

        $ssh = $this->startServer('ssh');
        $this->assertReasonContains('no saludó como un servidor FTP', fn () => $reader->fingerprint('127.0.0.1', $ssh, 5));

        $closed = $this->startServer('ok', 0); // escucha, pero cierra enseguida al terminar el proceso: puerto sin servicio
        proc_terminate($this->servers[array_key_last($this->servers)]['process']);
        usleep(300_000);
        $this->assertReasonContains('no se pudo abrir la conexión', fn () => $reader->fingerprint('127.0.0.1', $closed, 2));
    }

    private function assertReasonContains(string $needle, callable $call): void
    {
        try {
            $call();
            $this->fail('Debía fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    // ───────── el certificado se comprueba antes de enviar usuario y contraseña ─────────

    private function provider(?string $fingerprint, string $trust): PinnedFtpConnectionProvider
    {
        return new PinnedFtpConnectionProvider($this->certificate, $fingerprint, $trust);
    }

    public function test_pinned_certificate_must_match_the_confirmed_fingerprint(): void
    {
        $this->certificate->fingerprint = self::PIN_A;
        $this->provider(self::PIN_A, 'pin')->assertTrusted('h', 21, 5); // coincide: sin excepción
        $this->addToAssertionCount(1);

        $this->certificate->fingerprint = self::PIN_B;
        $this->expectException(FtpsCertificateMismatch::class);
        $this->provider(self::PIN_A, 'pin')->assertTrusted('h', 21, 5);
    }

    public function test_pinned_certificate_without_a_confirmed_fingerprint_is_refused(): void
    {
        $this->expectException(FtpsCertificateMismatch::class);
        $this->provider(null, 'pin')->assertTrusted('h', 21, 5);
    }

    public function test_authority_certificate_survives_a_renewal_but_not_losing_its_validity(): void
    {
        $this->certificate->ca = true;
        $this->certificate->fingerprint = self::PIN_B; // renovado: otra huella
        $this->provider(self::PIN_A, 'ca')->assertTrusted('h', 21, 5);
        $this->addToAssertionCount(1);

        $this->certificate->ca = false; // caducado, autofirmado o de otro nombre
        $this->expectException(FtpsCertificateMismatch::class);
        $this->provider(self::PIN_A, 'ca')->assertTrusted('h', 21, 5);
    }

    public function test_an_unreachable_server_is_a_connection_failure_not_a_changed_certificate(): void
    {
        $this->certificate->unreachable = new RuntimeException('puerto cerrado');

        foreach (['pin', 'ca'] as $trust) {
            try {
                $this->provider(self::PIN_A, $trust)->assertTrusted('h', 21, 5);
                $this->fail('Debía fallar');
            } catch (UnableToConnectToFtpHost $e) {
                $this->assertStringContainsString('puerto cerrado', $e->getMessage());
            }
        }
    }

    public function test_a_certificate_mismatch_stops_before_any_ftp_connection_is_attempted(): void
    {
        if (! function_exists('ftp_ssl_connect')) {
            $this->markTestSkipped('Falta la extensión PHP ftp.');
        }

        $this->certificate->fingerprint = self::PIN_B;
        $options = FtpConnectionOptions::fromArray(['host' => '127.0.0.1', 'root' => '/', 'username' => 'u', 'password' => 'p', 'port' => 1, 'ssl' => true]);

        // El puerto 1 está cerrado: si se intentara abrir la conexión FTP el fallo sería otro.
        $this->expectException(FtpsCertificateMismatch::class);
        $this->provider(self::PIN_A, 'pin')->createConnection($options);
    }

    // ───────── errores ─────────

    public function test_ftp_errors_map_to_the_same_codes_as_sftp(): void
    {
        $code = fn (Throwable $e) => RemoteErrorMapper::map($e)->errorCode;

        $this->assertSame(PublishException::AUTH, $code(UnableToWriteFile::atLocation('a', 'x', new FtpUnableToAuthenticate())));
        $this->assertSame(PublishException::HOSTKEY, $code(UnableToWriteFile::atLocation('a', 'x', new FtpsCertificateMismatch('cambió'))));
        $this->assertSame(PublishException::CONNECT, $code(UnableToConnectToFtpHost::forHost('h', 21, true, 'Connection refused')));
        $this->assertSame(PublishException::TIMEOUT, $code(UnableToConnectToFtpHost::forHost('h', 21, true, 'Connection timed out')));
        $this->assertSame(PublishException::PERMISSION, $code(UnableToResolveConnectionRoot::itDoesNotExist('/blog', 'no existe')));
        $this->assertSame(PublishException::AUTH, $code(new FtpUnableToAuthenticate()));
    }

    public function test_the_credentials_message_no_longer_says_sftp(): void
    {
        $this->assertStringNotContainsString('SFTP', PublishException::make(PublishException::AUTH)->getMessage());
        $this->assertStringContainsString('certificado', PublishException::make(PublishException::HOSTKEY)->getMessage());
    }

    // ───────── la conexión real que se monta ─────────

    public function test_the_ftps_adapter_is_always_encrypted_passive_and_uses_the_blogs_settings(): void
    {
        if (! function_exists('ftp_ssl_connect')) {
            $this->markTestSkipped('Falta la extensión PHP ftp.');
        }

        $blog = $this->ftpsBlog(['remote_dir' => '/httpdocs/blog/', 'sftp_port' => 0]);

        $adapter = (new RemoteFilesystemFactory())->ftpsAdapter($blog);
        $options = (new \ReflectionProperty(FtpAdapter::class, 'connectionOptions'))->getValue($adapter);

        $this->assertInstanceOf(FtpAdapter::class, $adapter);
        $this->assertTrue($options->ssl(), 'nunca FTP sin cifrar');
        $this->assertTrue($options->passive());
        $this->assertTrue($options->ignorePassiveAddress());
        $this->assertSame(21, $options->port());
        $this->assertSame('ftp.cliente.com', $options->host());
        $this->assertSame('blog', $options->username());
        $this->assertSame('secreto', $options->password());
        $this->assertSame('/httpdocs/blog', $options->root());
    }

    // ───────── panel: alta y edición ─────────

    private function blogForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Cliente FTPS', 'domain' => 'ftps.cliente.com', 'base_url' => 'https://ftps.cliente.com', 'remote_dir' => '/httpdocs/blog/',
            'locale' => 'es', 'timezone' => 'Europe/Madrid', 'posts_per_page' => 10, 'template' => 'demoblog', 'verify_http' => '0',
            'protocol' => 'ftps', 'sftp_auth' => 'password', 'sftp_host' => 'ftp.cliente.com', 'sftp_port' => '', 'sftp_user' => 'blog', 'sftp_secret' => 'secreto',
        ];
    }

    public function test_a_ftps_blog_is_created_from_the_panel_with_port_21_by_default(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('blogs.store'), $this->blogForm())->assertSessionHasNoErrors();

        $blog = Blog::where('domain', 'ftps.cliente.com')->sole();
        $this->assertSame('ftps', $blog->protocol);
        $this->assertSame(21, (int) $blog->sftp_port);
        $this->assertTrue($blog->isFtps());
        $this->assertNull($blog->host_key_fingerprint);
        $this->assertSame('secreto', $blog->sftp_secret);
    }

    public function test_sftp_stays_the_default_with_port_22_and_ftps_does_not_accept_a_private_key(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('blogs.store'), $this->blogForm(['domain' => 'sftp.cliente.com', 'protocol' => 'sftp']))->assertSessionHasNoErrors();
        $sftp = Blog::where('domain', 'sftp.cliente.com')->sole();
        $this->assertSame('sftp', $sftp->protocol);
        $this->assertSame(22, (int) $sftp->sftp_port);

        // Un formulario antiguo, sin el campo protocolo, sigue siendo SFTP.
        $this->post(route('blogs.store'), array_diff_key($this->blogForm(['domain' => 'viejo.cliente.com']), ['protocol' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('sftp', Blog::where('domain', 'viejo.cliente.com')->sole()->protocol);

        $this->post(route('blogs.store'), $this->blogForm(['domain' => 'clave.cliente.com', 'sftp_auth' => 'key']))->assertSessionHasErrors('sftp_auth');
        $this->assertDatabaseMissing('blogs', ['domain' => 'clave.cliente.com']);
    }

    public function test_changing_the_protocol_server_or_port_forgets_the_confirmed_certificate(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog([
            'host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'ca', 'status' => Blog::STATUS_ACTIVE,
            'domain' => 'ftps.cliente.com', 'base_url' => 'https://ftps.cliente.com', 'remote_dir' => '/httpdocs/blog/', 'posts_per_page' => 10,
        ]);

        // Mismo servidor y protocolo: se conserva.
        $this->put(route('blogs.update', $blog), $this->blogForm(['sftp_secret' => '', 'sftp_port' => '21']))->assertSessionHasNoErrors();
        $this->assertSame(self::PIN_A, $blog->fresh()->host_key_fingerprint);
        $this->assertSame('ca', $blog->fresh()->ftps_trust);
        $this->assertSame(Blog::STATUS_ACTIVE, $blog->fresh()->status);

        // Otro protocolo: hay que volver a confirmar y probar.
        $this->put(route('blogs.update', $blog), $this->blogForm(['sftp_secret' => '', 'protocol' => 'sftp', 'sftp_port' => '22']))->assertSessionHasNoErrors();
        $fresh = $blog->fresh();
        $this->assertNull($fresh->host_key_fingerprint);
        $this->assertNull($fresh->ftps_trust);
        $this->assertSame(Blog::STATUS_PENDING, $fresh->status);
    }

    public function test_the_blog_page_shows_the_protocol_and_the_certificate_fingerprint(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog(['host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'pin']);

        $this->get(route('blogs.show', $blog))->assertOk()
            ->assertSee('FTPS (FTP con TLS)')
            ->assertSee('Huella del certificado del servidor')
            ->assertSee(self::PIN_A)
            ->assertSee('Certificado fijado');
    }

    // ───────── probar conexión, confirmar y publicar ─────────

    public function test_first_connection_shows_the_certificate_and_whether_an_authority_vouches_for_it(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog();
        $this->certificate->fingerprint = self::PIN_A;
        $this->certificate->ca = false;

        $this->post(route('blogs.test-connection', $blog))
            ->assertSessionHas('pending_fingerprint', self::PIN_A)
            ->assertSessionHas('pending_ca_valid', false);

        $this->get(route('blogs.show', $blog))->assertSee('autofirmado o para otro nombre');
        $this->assertNull($blog->fresh()->host_key_fingerprint); // nada se guarda sin confirmar
        $this->assertSame(Blog::STATUS_PENDING, $blog->fresh()->status);

        $this->certificate->ca = true;
        $this->post(route('blogs.test-connection', $blog))->assertSessionHas('pending_ca_valid', true);
        $this->get(route('blogs.show', $blog))->assertSee('autoridades de confianza');
    }

    public function test_confirming_a_self_signed_certificate_pins_it_and_activates_the_blog(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog();
        $this->certificate->fingerprint = self::PIN_A;
        $this->certificate->ca = false;

        $this->confirm($blog, self::PIN_A);

        $fresh = $blog->fresh();
        $this->assertSame(self::PIN_A, $fresh->host_key_fingerprint);
        $this->assertSame('pin', $fresh->ftps_trust);
        $this->assertSame(Blog::STATUS_ACTIVE, $fresh->status);
        $this->assertSame('pin', $this->factory->lastTrust);
    }

    public function test_confirming_an_authority_certificate_trusts_the_authority_instead_of_the_fingerprint(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog();
        $this->certificate->fingerprint = self::PIN_A;
        $this->certificate->ca = true;

        $this->confirm($blog, self::PIN_A);

        $this->assertSame('ca', $blog->fresh()->ftps_trust);
        $this->assertSame(Blog::STATUS_ACTIVE, $blog->fresh()->status);
    }

    public function test_a_certificate_that_changes_between_showing_and_confirming_is_not_accepted(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog();
        $this->certificate->fingerprint = self::PIN_B; // el servidor ya presenta otro distinto del que se enseñó

        $this->post(route('blogs.confirm-fingerprint', $blog), ['fingerprint' => self::PIN_A])->assertSessionHas('error');

        $this->assertNull($blog->fresh()->host_key_fingerprint);
        $this->assertSame(Blog::STATUS_PENDING, $blog->fresh()->status);
    }

    public function test_a_pinned_certificate_that_changes_blocks_the_blog_until_an_admin_confirms_it(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog(['host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'pin', 'status' => Blog::STATUS_ACTIVE]);
        $this->certificate->fingerprint = self::PIN_B;

        $this->post(route('blogs.test-connection', $blog))->assertSessionHas('pending_fingerprint', self::PIN_B);

        $this->assertSame(Blog::STATUS_HOSTKEY_CHANGED, $blog->fresh()->status);
        $this->assertFalse($blog->fresh()->canPublish());
        $this->get(route('blogs.show', $blog))->assertSee('certificado del servidor ha cambiado');

        $this->confirm($blog, self::PIN_B);
        $this->assertSame(self::PIN_B, $blog->fresh()->host_key_fingerprint);
        $this->assertSame(Blog::STATUS_ACTIVE, $blog->fresh()->status);
    }

    public function test_an_authority_certificate_renewed_keeps_the_blog_working_and_updates_the_stored_fingerprint(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog(['host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'ca', 'status' => Blog::STATUS_ACTIVE]);
        $this->certificate->fingerprint = self::PIN_B;
        $this->certificate->ca = true;

        $this->post(route('blogs.test-connection', $blog))->assertSessionHas('status')->assertSessionMissing('pending_fingerprint');

        $this->assertSame(self::PIN_B, $blog->fresh()->host_key_fingerprint);
        $this->assertSame(Blog::STATUS_ACTIVE, $blog->fresh()->status);
    }

    public function test_an_authority_certificate_that_stops_being_valid_blocks_the_blog(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog(['host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'ca', 'status' => Blog::STATUS_ACTIVE]);
        $this->certificate->fingerprint = self::PIN_B;
        $this->certificate->ca = false;

        $this->post(route('blogs.test-connection', $blog))->assertSessionHas('pending_fingerprint', self::PIN_B);

        $this->assertSame(Blog::STATUS_HOSTKEY_CHANGED, $blog->fresh()->status);
    }

    public function test_wrong_credentials_and_unreachable_servers_are_reported_like_with_sftp(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog(['host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'pin', 'status' => Blog::STATUS_ACTIVE]);
        $this->certificate->fingerprint = self::PIN_A;

        $this->factory->failWith = UnableToWriteFile::atLocation('.probe', 'x', new FtpUnableToAuthenticate());
        $this->post(route('blogs.test-connection', $blog))->assertSessionHas('error', 'Credenciales rechazadas por el servidor.');
        $this->assertSame(Blog::STATUS_CONNECTION_ERROR, $blog->fresh()->status);

        $this->factory->failWith = null;
        $blog->update(['status' => Blog::STATUS_ACTIVE]);
        Log::spy();
        $this->certificate->unreachable = new RuntimeException('Connection refused');

        $this->post(route('blogs.test-connection', $blog))->assertSessionHas('error', 'No se pudo conectar con el servidor.');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => str_contains($m, 'servidor FTPS')
            && $c['port'] === 21 && $c['host'] === 'ftp.cliente.com' && str_contains($c['reason'], 'Connection refused') && ! str_contains(json_encode($c), 'secreto'));
    }

    public function test_when_the_ftps_login_works_but_the_write_fails_the_real_reason_is_logged_for_diagnosis(): void
    {
        $this->actingAs($this->admin());
        $blog = $this->ftpsBlog(['host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'pin', 'status' => Blog::STATUS_ACTIVE]);
        $this->certificate->fingerprint = self::PIN_A;
        $this->factory->failWith = UnableToWriteFile::atLocation('.probe', 'writing the file failed', new RuntimeException('ftp_fput(): No connection established'));
        Log::spy();

        $this->post(route('blogs.test-connection', $blog))->assertSessionHas('error');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => str_contains($m, 'escritura FTPS')
            && str_contains($c['reason'], 'No connection established') && $c['port'] === 21 && ! str_contains(json_encode($c), 'secreto'));
    }

    public function test_a_ftps_blog_without_a_confirmed_certificate_cannot_publish(): void
    {
        $blog = $this->ftpsBlog(['status' => Blog::STATUS_ACTIVE]); // activo pero sin huella confirmada

        $this->expectException(PublishException::class);
        app(\App\Services\ArticlePublisher::class)->guardBlog($blog);
    }

    public function test_publishing_goes_through_the_ftps_filesystem_of_the_blog(): void
    {
        $blog = $this->ftpsBlog(['host_key_fingerprint' => self::PIN_A, 'ftps_trust' => 'pin', 'status' => Blog::STATUS_ACTIVE, 'verify_http' => false, 'template' => 'demoblog']);
        $article = app(\App\Services\ArticleService::class)->createDraft($blog);
        app(\App\Services\ArticleService::class)->save($article, $this->chooseCategory($blog) + [
            'title' => 'Por FTPS', 'body_html' => '<p>Hola</p>', 'published_at' => '2026-11-20T10:00', 'version' => $article->version,
        ]);

        \App\Jobs\PublishArticle::dispatchSync($article->id);

        $this->assertSame('published', $article->fresh()->status);
        $this->assertFileExists($this->remoteDir.'/general/por-ftps.html');
        $this->assertFileExists($this->remoteDir.'/.htaccess');
    }
}

/** Certificado falso: se le dice qué huella presenta, si lo avalan las autoridades o si el servidor no responde. */
class FakeFtpsCertificate extends FtpsServerCertificate
{
    public string $fingerprint = 'sha256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    public bool $ca = false;
    public ?Throwable $unreachable = null;

    public function fingerprint(string $host, int $port, int $timeout): string
    {
        if ($this->unreachable) {
            throw $this->unreachable;
        }

        return $this->fingerprint;
    }

    public function verifiesAgainstAuthorities(string $host, int $port, int $timeout): bool
    {
        return $this->ca;
    }
}

/** En vez de abrir una conexión FTPS, escribe en una carpeta local; así se prueba todo lo demás sin un servidor FTPS. */
class FakeFtpsFactory extends RemoteFilesystemFactory
{
    public ?Throwable $failWith = null;
    public ?string $lastTrust = null;

    public function __construct(private string $dir)
    {
    }

    public function ftpsFilesystem(Blog $blog, ?string $fingerprint = null, ?string $trust = null): FilesystemOperator
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $this->lastTrust = $trust;

        return new Filesystem(new LocalFilesystemAdapter($this->dir));
    }
}
