<?php

namespace App\Services;

use App\Models\Blog;
use DOMDocument;
use DOMElement;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\File;

/**
 * Lista blanca de HTML para body_html. Se aplica al guardar y otra vez al generar
 * (para cubrir JSON creados por el importador XML).
 *
 * Permitido: p, h2-h4, strong, em, ul, ol, li, a, img, table y sus partes,
 * blockquote, br, hr, figure, figcaption.
 * Prohibido: script, style, iframe, atributos on*, URLs javascript:, formularios.
 */
class HtmlSanitizer
{
    private const ALLOWED = 'p,h2,h3,h4,strong,em,ul,ol,li,a[href|title|rel],img[src|alt|width|height|title],'
        .'table,thead,tbody,tfoot,tr,th[colspan|rowspan],td[colspan|rowspan],blockquote,br,hr,figure,figcaption';

    private ?HTMLPurifier $purifier = null;

    public function sanitize(?string $html, ?Blog $blog = null): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $clean = $this->purifier()->purify($html);

        return $this->postProcess($clean, $blog);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier) {
            return $this->purifier;
        }

        $cache = storage_path('framework/purifier');
        File::ensureDirectoryExists($cache);

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedRel', ['noopener', 'nofollow', 'noreferrer']);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('Cache.SerializerPath', $cache);
        $config->set('HTML.DefinitionID', 'publiblog');
        $config->set('HTML.DefinitionRev', 1);

        if ($def = $config->maybeGetRawHTMLDefinition()) {
            // HTML5 mínimo que Tiptap y las plantillas usan y HTMLPurifier no conoce.
            $def->addElement('figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
        }

        return $this->purifier = new HTMLPurifier($config);
    }

    /** Descarta imágenes que no sean del propio blog y marca los enlaces externos. */
    private function postProcess(string $html, ?Blog $blog): string
    {
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementsByTagName('div')->item(0);

        if (! $root) {
            return $html;
        }

        $imgPattern = '#^/'.preg_quote(config('publiblog.public_prefix'), '#').'/img/\d{4}/[A-Za-z0-9._-]+$#';

        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            if (preg_match($imgPattern, $img->getAttribute('src')) !== 1) {
                $img->parentNode?->removeChild($img);
            }
        }

        foreach ($doc->getElementsByTagName('a') as $a) {
            /** @var DOMElement $a */
            if ($this->isExternal($a->getAttribute('href'), $blog)) {
                $rel = array_filter(array_unique([...preg_split('/\s+/', $a->getAttribute('rel')) ?: [], 'noopener']));
                $a->setAttribute('rel', implode(' ', $rel));
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private function isExternal(string $href, ?Blog $blog): bool
    {
        if (! preg_match('#^https?://#i', $href)) {
            return false;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $own = strtolower((string) $blog?->domain);

        return $own === '' || ($host !== $own && $host !== 'www.'.$own && 'www.'.$host !== $own);
    }
}
