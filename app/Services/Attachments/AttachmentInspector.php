<?php

namespace App\Services\Attachments;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class AttachmentInspector
{
    private const MIMES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    ];

    public function inspect(UploadedFile $file, string $clientUuid, int $position): array
    {
        if (! $file->isValid() || ! is_readable($file->getRealPath()) || $file->getSize() < 1) {
            $this->fail('attachments', 'Every attachment must be a valid, non-empty readable upload.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        if (! isset(self::MIMES[$extension]) || ! in_array($mime, self::MIMES[$extension], true)) {
            $this->fail('attachments', 'An attachment extension does not match its detected file type.');
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $image = @getimagesize($file->getRealPath());
            if (! $image || ($image['mime'] ?? null) !== $mime) {
                $this->fail('attachments', 'An uploaded image is malformed.');
            }
        }
        if ($extension === 'pdf' && file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
            $this->fail('attachments', 'An uploaded PDF is malformed.');
        }
        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $this->validateOoxml($file->getRealPath(), $extension);
        }

        return ['file' => $file, 'client_uuid' => $clientUuid, 'position' => $position, 'original_name' => $this->safeName($file->getClientOriginalName(), $extension), 'extension' => $extension, 'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    private function validateOoxml(string $path, string $extension): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('attachments', 'An Office document is malformed or encrypted.');
        }
        $required = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$extension];
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('_rels/.rels') !== false && $zip->locateName($required) !== false;
        $zip->close();
        if (! $valid) {
            $this->fail('attachments', 'An Office document does not contain the expected document structure.');
        }
    }

    private function safeName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?: 'attachment.'.$extension;
        $name = trim(str_replace(['/', '\\'], '-', $name));
        if (mb_strlen($name) > config('message-attachments.max_filename_length')) {
            $suffix = '.'.$extension;
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, config('message-attachments.max_filename_length') - mb_strlen($suffix)).$suffix;
        }

        return $name ?: 'attachment.'.$extension;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
