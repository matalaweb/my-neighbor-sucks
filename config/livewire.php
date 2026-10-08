<?php

return [
    /*
    | Livewire temporary uploads (calibration and placement attachments) go
    | to the local disk so browsers never need direct access to the private
    | bucket. Files are moved to private object storage with a SHA-256.
    */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TMP_DISK', 'local'),
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => 'livewire-tmp',
        'max_upload_time' => 5,
    ],
];
