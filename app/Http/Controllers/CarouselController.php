<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class CarouselController extends Controller
{
    public function index(): JsonResponse
    {
        $slides = Cache::remember('carousel_slides', 60, function () {
            $result = Cloudinary::searchApi()
                ->expression('public_id:carrossel/hero*')
                ->sortBy('public_id', 'asc')
                ->maxResults(10)
                ->execute();

            return collect($result['resources'])->map(function ($r) {
                $base = $r['secure_url'];
                $transform = fn (string $t) =>
                    str_replace('/upload/', "/upload/{$t}/", $base);

                return [
                    'id'           => $r['public_id'],
                    'desktopImage' => $transform('f_auto,q_auto,c_fill,g_auto,ar_21:9,w_1920'),
                    'mobileImage'  => $transform('f_auto,q_auto,c_fill,g_auto,ar_25:26,w_800'),
                    'link'         => '/produtos',
                ];
            })->values();
        });

        return response()->json($slides);
    }
}