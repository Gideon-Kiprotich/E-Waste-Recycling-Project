<?php

namespace App\Support\Collection;

use App\Support\Devices\PrivatePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivateEvidence
{
    public function store(UploadedFile $file): array
    {
        if ($file->getMimeType() !== 'application/pdf') {
            return app(PrivatePhoto::class)->store($file, 'directory_evidence', 'evidence');
        }
        $bytes = file_get_contents($file->getPathname());
        if (strlen($bytes) > 5242880 || ! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -1024), '%%EOF') || preg_match('/<(?:\?php|script\b|svg\b|html\b)|\/(?:JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b/i', $bytes)) {
            throw ValidationException::withMessages(['evidence' => 'Use a supported JPEG/PNG or non-active PDF up to 5 MiB.']);
        }
        $key = 'documents/'.Str::uuid().'.pdf';
        if (! Storage::disk('directory_evidence')->put($key, $bytes, ['visibility' => 'private'])) {
            Storage::disk('directory_evidence')->delete($key);
            throw ValidationException::withMessages(['evidence' => 'Evidence could not be saved. Please retry.']);
        }

        return ['storage_key' => $key, 'mime' => 'application/pdf', 'size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes)];
    }

    public function link(array $stored, string $actor, string $event, string $kind, string $id): void
    {
        $attachment = Str::uuid()->toString();
        DB::table('attachments')->insert($stored + ['id' => $attachment, 'uploaded_by' => $actor, 'privacy_class' => 'private']);
        DB::table('evidence_links')->insert(['id' => Str::uuid()->toString(), 'attachment_id' => $attachment, 'event_id' => $event, 'record_kind' => $kind, 'record_id' => $id]);
    }
}
