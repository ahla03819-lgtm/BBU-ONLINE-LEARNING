<?php

namespace App\Services\Coursework;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class CourseworkAttachmentInspector
{
    private const MIMES = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip']];

    public function inspect(UploadedFile $file, string $clientUuid, int $position): array
    {
        if (! $file->isValid() || ! is_readable($file->getRealPath()) || $file->getSize() < 1) {
            $this->fail('Every attachment must be a valid, non-empty readable upload.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());
        if (! isset(self::MIMES[$extension]) || ! in_array($mime, self::MIMES[$extension], true)) {
            $this->fail('An attachment extension does not match its detected file type.');
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && (! ($image = @getimagesize($file->getRealPath())) || ($image['mime'] ?? null) !== $mime)) {
            $this->fail('An uploaded image is malformed.');
        }
        if ($extension === 'pdf' && file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
            $this->fail('An uploaded PDF is malformed.');
        }
        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $this->validateOffice($file->getRealPath(), $extension);
        }
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?: 'attachment.'.$extension);
        if (mb_strlen($name) > config('coursework-attachments.max_filename_length')) {
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 150).'.'.$extension;
        }

        return ['file' => $file, 'client_uuid' => $clientUuid, 'position' => $position, 'original_name' => $name, 'extension' => $extension, 'mime_type' => $mime, 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    private function validateOffice(string $path, string $extension): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('An Office document is malformed or encrypted.');
        }
        $required = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$extension];
        $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('_rels/.rels') !== false && $zip->locateName($required) !== false;
        $zip->close();
        if (! $valid) {
            $this->fail('An Office document does not contain the expected structure.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['attachments' => $message]);
    }
}
