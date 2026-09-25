<?php

namespace Goldnead\StatamicInbox\Http\Controllers\Cp;

use Goldnead\StatamicInbox\Models\Attachment;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attachments live on a private disk and leave only through here. Images and
 * PDFs open in the browser; everything else (HTML above all) is a download,
 * so a stranger's file never renders inside the CP's origin.
 */
class AttachmentsController extends Controller
{
    public function show(int $inboxAttachment): StreamedResponse
    {
        Gate::authorize('view inbox');

        $attachment = Attachment::query()->findOrFail($inboxAttachment);
        $disk = Storage::disk((string) config('inbox.attachments.disk', 'local'));

        abort_unless($disk->exists($attachment->path), 404);

        $inline = $attachment->isInline();

        return $disk->response($attachment->path, $attachment->filename, [
            'Content-Type' => $inline ? $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}
