<?php
/**
 * Secure File Upload Handler (MIME finfo check + Safe Storage)
 */

class Uploader
{
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const ALLOWED_DOC_MIMES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    public static function uploadImage(array $file, string $subdir = 'general', int $maxBytes = 3145728): array
    {
        return self::process($file, $subdir, self::ALLOWED_IMAGE_MIMES, $maxBytes);
    }

    public static function uploadProofOrAttachment(array $file, string $subdir = 'proofs', int $maxBytes = 5242880): array
    {
        return self::process($file, $subdir, self::ALLOWED_DOC_MIMES, $maxBytes);
    }

    private static function process(array $file, string $subdir, array $mimeMap, int $maxBytes): array
    {
        if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file uploaded.'];
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'File upload error code: ' . (int)$file['error']];
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
            return ['ok' => false, 'error' => 'File size exceeds allowed limit (' . round($maxBytes / 1048576, 1) . ' MB).'];
        }

        $tmp = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp) && !file_exists($tmp)) {
            return ['ok' => false, 'error' => 'Invalid upload source.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        if (!isset($mimeMap[$mime])) {
            return ['ok' => false, 'error' => 'Unsupported file format (' . $mime . ').'];
        }

        $safeSubdir = preg_replace('/[^a-z0-9_-]/i', '', $subdir);
        $destDir = UPLOADS_PATH . '/' . $safeSubdir;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $ext = $mimeMap[$mime];
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $fullPath = $destDir . '/' . $filename;

        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $fullPath) : rename($tmp, $fullPath);
        if (!$moved) {
            return ['ok' => false, 'error' => 'Failed to store uploaded file.'];
        }

        return [
            'ok'   => true,
            'path' => '/storage/uploads/' . $safeSubdir . '/' . $filename,
        ];
    }
}
