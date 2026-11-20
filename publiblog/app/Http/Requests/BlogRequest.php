<?php

namespace App\Http\Requests;

use App\Models\Blog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperadmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => strtolower(trim((string) $this->input('domain'))),
            'base_url' => rtrim(trim((string) $this->input('base_url')), '/'),
        ]);
    }

    public function rules(): array
    {
        /** @var Blog|null $blog */
        $blog = $this->route('blog');
        $auths = app()->environment('production') ? ['password', 'key'] : ['password', 'key', 'local'];
        $isLocal = $this->input('sftp_auth') === 'local';
        $secretRule = $blog?->hasSecret() ? 'nullable' : ($isLocal ? 'nullable' : 'required');

        return [
            'name' => ['required', 'string', 'max:120'],
            'domain' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d+)?$/'],
            'base_url' => ['required', 'url:http,https', 'max:190'],
            'remote_dir' => ['required', 'string', 'max:255', 'not_regex:/\.\./'],
            'locale' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'timezone:all'],
            'posts_per_page' => ['required', 'integer', 'min:1', 'max:100'],
            'template' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/', function ($a, $v, $fail) {
                foreach (['article', 'index'] as $view) {
                    if (! view()->exists("blogs.{$v}.{$view}")) {
                        return $fail("A la plantilla le falta resources/views/blogs/{$v}/{$view}.blade.php.");
                    }
                }
            }],
            'protocol' => ['nullable', Rule::in(['sftp', 'ftps'])],
            'sftp_auth' => ['required', Rule::in($auths), function ($attribute, $value, $fail) {
                if ($this->input('protocol') === 'ftps' && $value === 'key') {
                    $fail('FTPS solo admite contraseña: la clave privada es de SFTP.');
                }
            }],
            'sftp_host' => [Rule::requiredIf(! $isLocal), 'nullable', 'string', 'max:190'],
            'sftp_port' => ['nullable', 'integer', 'between:1,65535'],
            'sftp_user' => [Rule::requiredIf(! $isLocal), 'nullable', 'string', 'max:190'],
            'sftp_secret' => [$secretRule, 'string', 'max:8000'],
            'sftp_passphrase' => ['nullable', 'string', 'max:500'],
            'verify_http' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'domain' => 'dominio',
            'base_url' => 'URL base',
            'remote_dir' => 'directorio remoto',
            'protocol' => 'protocolo',
            'sftp_host' => 'servidor',
            'sftp_user' => 'usuario',
            'sftp_secret' => 'contraseña o clave',
            'posts_per_page' => 'artículos por página',
            'timezone' => 'zona horaria',
        ];
    }
}
