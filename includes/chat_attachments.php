<?php

function chatAttachmentMimeType(string $filename): ?string {
    $types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'webm' => 'video/webm',
    ];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (isset($types[$extension])) {
        return $types[$extension];
    }

    return match ($extension) {
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
        'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed',
        default => null,
    };
}

function chatNotificationText(string $body, ?string $attachmentName): string {
    $body = trim($body);
    if ($body !== '') {
        return $body;
    }

    return 'Zpráva obsahuje přílohu: ' . ($attachmentName ?? 'soubor');
}

function receiveChatAttachment(array $files): array {
    $upload = null;
    foreach (['attachment', 'camera_attachment'] as $field) {
        if (isset($files[$field]) && (int)($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = $files[$field];
            break;
        }
    }

    if ($upload === null) {
        return ['path' => null, 'name' => null, 'error' => null];
    }

    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['path' => null, 'name' => null, 'error' => 'Přílohu se nepodařilo nahrát. Zkontrolujte její velikost a zkuste to znovu.'];
    }
    if ((int)($upload['size'] ?? 0) <= 0 || (int)$upload['size'] > 50 * 1024 * 1024) {
        return ['path' => null, 'name' => null, 'error' => 'Příloha musí mít velikost do 50 MB.'];
    }

    $originalName = basename((string)($upload['name'] ?? ''));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
        'zip', 'rar', '7z', 'mp4', 'mov', 'avi', 'mkv', 'webm', 'txt', 'ppt', 'pptx',
    ];
    if (!in_array($extension, $allowedExtensions, true)) {
        return ['path' => null, 'name' => null, 'error' => 'Tento typ souboru není povolen.'];
    }

    $tmpName = (string)($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($tmpName)) {
        return ['path' => null, 'name' => null, 'error' => 'Nahraný soubor se nepodařilo ověřit.'];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $expectedMime = chatAttachmentMimeType($originalName);
    $zipBasedExtensions = ['docx', 'xlsx', 'pptx'];
    $allowedMimes = [$expectedMime];
    if (in_array($extension, $zipBasedExtensions, true)) {
        $allowedMimes = array_merge($allowedMimes, ['application/zip', 'application/x-zip', 'application/x-zip-compressed']);
    } elseif (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
        $allowedMimes[] = 'application/x-ole-storage';
    } elseif ($extension === 'csv') {
        $allowedMimes = array_merge($allowedMimes, ['text/plain', 'application/vnd.ms-excel']);
    } elseif ($extension === 'txt') {
        $allowedMimes[] = 'application/octet-stream';
    } elseif ($extension === 'rar') {
        $allowedMimes[] = 'application/x-rar-compressed';
    }
    if ($mime === false || !in_array($mime, $allowedMimes, true)) {
        return ['path' => null, 'name' => null, 'error' => 'Obsah souboru neodpovídá jeho typu.'];
    }

    $uploadDir = dirname(__DIR__) . '/uploads/messages/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['path' => null, 'name' => null, 'error' => 'Přílohu se nepodařilo uložit.'];
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($tmpName, $uploadDir . $storedName)) {
        return ['path' => null, 'name' => null, 'error' => 'Přílohu se nepodařilo uložit.'];
    }

    return [
        'path' => $storedName,
        'name' => mb_substr($originalName, 0, 255, 'UTF-8'),
        'error' => null,
    ];
}
