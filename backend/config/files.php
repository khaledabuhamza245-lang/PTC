<?php

return [
    'max_size_mb' => (int) env('FILE_MAX_SIZE_MB', 2048),
    'upload_url_minutes' => (int) env('FILE_UPLOAD_URL_MINUTES', 15),
    'download_url_minutes' => (int) env('FILE_DOWNLOAD_URL_MINUTES', 10),
    'progress_max_kb' => (int) env('PROGRESS_MAX_KB', 512),
    'allowed_mime_types' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-rar-compressed',
        'text/plain',
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/webm',
    ],
];
