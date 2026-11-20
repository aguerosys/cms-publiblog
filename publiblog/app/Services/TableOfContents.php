<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Anclas e índice de contenidos de un artículo.
 *
 * Al generar la página, cada <h2> y <h3> del cuerpo (ya saneado) recibe un `id` derivado de su texto
 * (`Qué es un agente de IA` → `que-es-un-agente-de-ia`, único dentro del artículo) y la plantilla recibe la
 * lista de los <h2> como `$article->toc` para pintar un índice con enlaces. El editor no escribe ids: se
 * calculan siempre, así que cambiar un título cambia su ancla y el índice nunca queda desfasado.
 */
class TableOfContents
{
    /**
     * @return array{0:string,1:list<object>} el HTML con los ids puestos y los <h2>: id, text, level
     */
    public function apply(string $html): array
    {
        $used = [];
        $items = [];

        $html = (string) preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function (array $m) use (&$used, &$items) {
            $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if ($text === '') {
                return $m[0];
            }

            $base = rtrim(substr(Str::slug($text), 0, 60), '-') ?: 'seccion';
            $id = $base;

            for ($n = 2; isset($used[$id]); $n++) {
                $id = $base.'-'.$n;
            }

            $used[$id] = true;

            if ($m[1] === '2') {
                $items[] = (object) ['id' => $id, 'text' => $text, 'level' => 2];
            }

            return '<h'.$m[1].' id="'.$id.'">'.$m[2].'</h'.$m[1].'>';
        }, $html);

        return [$html, $items];
    }
}
