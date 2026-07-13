<?php
namespace App\Http\Controllers;

class SpeedProbeController extends Controller
{
    public function probe()
    {
        return response(str_repeat("X", 65536), 200)
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Length', '65536')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }
}
