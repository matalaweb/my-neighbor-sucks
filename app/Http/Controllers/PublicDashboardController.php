<?php

namespace App\Http\Controllers;

use App\Services\Devices\DeviceSharingService;
use Illuminate\Http\Response;

class PublicDashboardController extends Controller
{
    /**
     * Read-only dashboard for a device an owner shared publicly. The link is
     * the only credential, so the page is kept out of search indexes and
     * never sends the token onward in a Referer header.
     */
    public function show(string $token, DeviceSharingService $sharing): Response
    {
        $device = $sharing->resolve($token);

        abort_if($device === null, 404);

        return response()
            ->view('share.show', ['token' => $token, 'title' => $device->publicTitle()])
            ->withHeaders([
                'X-Robots-Tag' => 'noindex, nofollow',
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'no-store, private',
            ]);
    }
}
