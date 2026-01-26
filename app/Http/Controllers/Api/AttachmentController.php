<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
// We don't need StreamedResponse for this simpler method.

class AttachmentController extends Controller
{
    public function download($filename)
    {
        $timestamp = (int) substr($filename, 0, strpos($filename, '_'));
        if ($timestamp === 0) {
            abort(404, 'Invalid filename format.');
        }

        $originalName = substr($filename, strpos($filename, '_') + 1);
        $encryptedFilename = sha1($filename);
        $folderPath = date('Y/m/d', $timestamp);
        $filePath = $folderPath . '/' . $encryptedFilename;

        $disk = Storage::disk('rukovoditel_attachments');

        if (!$disk->exists($filePath)) {
            abort(404, 'File not found.');
        }

        // ✅ FIX: Use the universal response()->file() helper.
        // This method is guaranteed to work.

        // 1. Get the full, absolute path to the file from the disk.
        $absolutePath = $disk->path($filePath);

        // 2. Create the headers for the download.
        $headers = [
            'Content-Type' => $disk->mimeType($filePath),
            'Content-Disposition' => 'attachment; filename="' . $originalName . '"',
        ];

        // 3. Return the file as a response.
        return response()->file($absolutePath, $headers);
    }
}
