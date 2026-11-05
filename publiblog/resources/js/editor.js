import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import { Table, TableRow, TableHeader, TableCell } from '@tiptap/extension-table';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function slugify(text, max = 80) {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, max)
        .replace(/-+$/, '');
}

async function uploadImage(form, file) {
    const max = parseInt(form.dataset.maxBytes || '0', 10);
    if (max && file.size > max) {
        throw new Error(`La imagen pesa ${(file.size / 1048576).toFixed(1)} MB y el máximo permitido es ${(max / 1048576).toFixed(1)} MB.`);
    }

    const body = new FormData();
    body.append('image', file);
    body.append('article_id', form.dataset.articleId);

    const res = await fetch(form.dataset.uploadUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        body,
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        throw new Error(data.message || 'No se pudo subir la imagen.');
    }

    return data;
}

function initArticleForm(form) {
    const holder = form.querySelector('[data-editor]');
    const input = form.querySelector('[name="body_html"]');
    const toolbar = form.querySelector('[data-toolbar]');
    const status = form.querySelector('[data-upload-status]');

    const say = (msg, error = false) => {
        if (!status) return;
        status.textContent = msg;
        status.className = 'text-sm ' + (error ? 'text-red-600' : 'text-gray-500');
    };

    const editor = new Editor({
        element: holder,
        content: input.value || '',
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3, 4] },
                // Fuera de la lista blanca del servidor:
                code: false,
                codeBlock: false,
                strike: false,
                underline: false,
                link: { openOnClick: false, autolink: true, HTMLAttributes: { rel: null, target: null } },
            }),
            Image.configure({ allowBase64: false }),
            Table.configure({ resizable: false }),
            TableRow,
            TableHeader,
            TableCell,
            Placeholder.configure({ placeholder: 'Escribe el artículo…' }),
        ],
        onUpdate: ({ editor }) => {
            input.value = editor.isEmpty ? '' : editor.getHTML();
        },
        onSelectionUpdate: refresh,
        onTransaction: refresh,
    });

    input.value = editor.isEmpty ? '' : editor.getHTML();

    const commands = {
        bold: () => editor.chain().focus().toggleBold().run(),
        italic: () => editor.chain().focus().toggleItalic().run(),
        h2: () => editor.chain().focus().toggleHeading({ level: 2 }).run(),
        h3: () => editor.chain().focus().toggleHeading({ level: 3 }).run(),
        ul: () => editor.chain().focus().toggleBulletList().run(),
        ol: () => editor.chain().focus().toggleOrderedList().run(),
        quote: () => editor.chain().focus().toggleBlockquote().run(),
        link: () => {
            const prev = editor.getAttributes('link').href ?? '';
            const url = window.prompt('Dirección del enlace (http, https o mailto):', prev);
            if (url === null) return;
            if (url === '') return editor.chain().focus().extendMarkRange('link').unsetLink().run();
            if (!/^(https?:\/\/|mailto:)/i.test(url)) return window.alert('Solo se admiten enlaces http, https o mailto.');
            editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
        },
        table: () => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
        addRow: () => editor.chain().focus().addRowAfter().run(),
        addCol: () => editor.chain().focus().addColumnAfter().run(),
        delTable: () => editor.chain().focus().deleteTable().run(),
        undo: () => editor.chain().focus().undo().run(),
        redo: () => editor.chain().focus().redo().run(),
        image: () => form.querySelector('[data-body-image]').click(),
        alt: () => {
            if (!editor.isActive('image')) return window.alert('Selecciona primero una imagen del texto (haz clic sobre ella).');
            const prev = editor.getAttributes('image').alt ?? '';
            const alt = window.prompt('Texto alternativo de la imagen (describe qué se ve):', prev);
            if (alt === null) return;
            editor.chain().focus().updateAttributes('image', { alt: alt.trim() }).run();
        },
    };

    const active = {
        bold: () => editor.isActive('bold'),
        italic: () => editor.isActive('italic'),
        h2: () => editor.isActive('heading', { level: 2 }),
        h3: () => editor.isActive('heading', { level: 3 }),
        ul: () => editor.isActive('bulletList'),
        ol: () => editor.isActive('orderedList'),
        quote: () => editor.isActive('blockquote'),
        link: () => editor.isActive('link'),
    };

    function refresh() {
        toolbar?.querySelectorAll('[data-cmd]').forEach((btn) => {
            const name = btn.dataset.cmd;
            btn.classList.toggle('is-active', !!active[name]?.());
        });
    }

    toolbar?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-cmd]');
        if (!btn) return;
        e.preventDefault();
        commands[btn.dataset.cmd]?.();
    });

    // Imagen dentro del cuerpo
    form.querySelector('[data-body-image]')?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;

        say('Subiendo y reduciendo la imagen…');
        try {
            const media = await uploadImage(form, file);
            editor.chain().focus().setImage({ src: media.url, alt: '' }).run();
            say('Imagen añadida. Recuerda describirla con texto alternativo si es relevante.');
        } catch (err) {
            say(err.message, true);
        }
    });

    // Imagen destacada
    const featuredInput = form.querySelector('[data-featured-file]');
    featuredInput?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        e.target.value = '';
        if (!file) return;

        say('Subiendo y reduciendo la imagen destacada…');
        try {
            const media = await uploadImage(form, file);
            form.querySelector('[name="featured_media_id"]').value = media.id;
            const img = form.querySelector('[data-featured-preview]');
            img.src = media.url;
            img.classList.remove('hidden');
            form.querySelector('[data-featured-remove]')?.classList.remove('hidden');
            say(`Imagen destacada lista (${media.width}×${media.height}).`);
        } catch (err) {
            say(err.message, true);
        }
    });

    form.querySelector('[data-featured-remove]')?.addEventListener('click', (e) => {
        e.preventDefault();
        form.querySelector('[name="featured_media_id"]').value = '';
        form.querySelector('[data-featured-preview]').classList.add('hidden');
        e.currentTarget.classList.add('hidden');
    });

    // Slug propuesto a partir del título, mientras no se edite a mano
    const title = form.querySelector('[name="title"]');
    const slug = form.querySelector('[name="slug"]');

    if (title && slug && !slug.readOnly) {
        let manual = slug.value !== '' && slug.value !== slugify(title.value);
        slug.addEventListener('input', () => (manual = slug.value !== slugify(title.value)));
        title.addEventListener('input', () => {
            if (!manual) slug.value = slugify(title.value);
        });
    }

    // Vista previa de contenido (no replica el diseño final)
    const previewBtn = form.querySelector('[data-preview-toggle]');
    const preview = form.querySelector('[data-preview]');

    previewBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        const showing = !preview.classList.contains('hidden');
        if (showing) {
            preview.classList.add('hidden');
            holder.closest('[data-editor-wrap]').classList.remove('hidden');
            previewBtn.textContent = 'Vista previa';
            return;
        }
        preview.querySelector('[data-preview-title]').textContent = title.value || 'Sin título';
        preview.querySelector('[data-preview-body]').innerHTML = editor.getHTML();
        preview.classList.remove('hidden');
        holder.closest('[data-editor-wrap]').classList.add('hidden');
        previewBtn.textContent = 'Volver al editor';
    });

    // Antes de enviar, el HTML del editor va al campo oculto
    form.addEventListener('submit', () => {
        input.value = editor.isEmpty ? '' : editor.getHTML();
    });

    refresh();
}

document.querySelectorAll('[data-article-form]').forEach(initArticleForm);
