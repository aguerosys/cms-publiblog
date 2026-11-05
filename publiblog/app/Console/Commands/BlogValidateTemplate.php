<?php

namespace App\Console\Commands;

use App\Services\TemplateValidator;
use Illuminate\Console\Command;

class BlogValidateTemplate extends Command
{
    protected $signature = 'blog:validate-template {template : Nombre de la plantilla (resources/views/blogs/{plantilla})}';

    protected $description = 'Renderiza una plantilla con artículos de ejemplo y comprueba el contrato de salida';

    public function handle(TemplateValidator $validator): int
    {
        $template = $this->argument('template');
        $errors = $validator->validate($template);

        if ($errors) {
            $this->error("La plantilla «{$template}» no es válida:");

            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $this->info("La plantilla «{$template}» es válida.");

        return self::SUCCESS;
    }
}
