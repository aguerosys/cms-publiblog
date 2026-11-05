<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function store(Request $request, Blog $blog, MediaService $service): JsonResponse
    {
        // Si el cuerpo supera post_max_size, PHP lo descarta entero y Laravel solo vería «falta la imagen».
        $limit = MediaService::maxUploadBytes();
        $declared = (int) $request->server('CONTENT_LENGTH');
        $file = $request->file('image');

        if (($declared > $limit + 65536) || ($file && ! $file->isValid() && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true))) {
            return response()->json([
                'message' => 'La imagen es demasiado pesada. El máximo es '.MediaService::humanBytes($limit).'.',
            ], 413);
        }

        $request->validate([
            'image' => ['required', 'file'],
            'article_id' => ['required', 'integer'],
        ], ['image.required' => 'Selecciona una imagen.']);

        $article = $blog->articles()->findOrFail($request->integer('article_id'));

        try {
            $media = $service->store($blog, $article, $request->file('image'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $media->id,
            'url' => $media->previewUrl(),
            'width' => $media->width,
            'height' => $media->height,
            'bytes' => $media->bytes,
        ]);
    }

    /** Vista previa protegida: las imágenes solo se ven dentro del CMS hasta publicarse. */
    public function show(Blog $blog, Media $media): StreamedResponse
    {
        return Storage::disk('blogs')->response($media->local_path, null, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
