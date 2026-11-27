<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialMediaSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialMediaController extends Controller
{
    private const SETTING_ID = 1;

    private const PLATFORM_KEYS = [
        'facebook',
        'youtube',
        'linkedin',
        'instagram',
        'whatsapp',
    ];

    public function publicLinks(): JsonResponse
    {
        $setting = SocialMediaSetting::query()->find(self::SETTING_ID);

        return response()->json(['links' => $this->linksPayload($setting)]);
    }

    public function show(): JsonResponse
    {
        $setting = SocialMediaSetting::query()->find(self::SETTING_ID);

        return response()->json([
            'links' => $this->linksPayload($setting),
            'updated_at' => $setting?->updated_at?->format('c'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'facebook_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'youtube_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'linkedin_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'instagram_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
            'whatsapp_url' => ['nullable', 'string', 'url:http,https', 'max:500'],
        ]);

        $setting = DB::transaction(function () use ($validated, $request) {
            $setting = SocialMediaSetting::query()->find(self::SETTING_ID) ?? new SocialMediaSetting;
            $setting->id = self::SETTING_ID;

            foreach (self::PLATFORM_KEYS as $platform) {
                $setting->{"{$platform}_url"} = trim((string) ($validated["{$platform}_url"] ?? '')) ?: null;
            }

            $setting->updated_by = $request->user()->id;
            $setting->save();

            return $setting;
        });

        return response()->json([
            'message' => 'Social media links updated successfully.',
            'links' => $this->linksPayload($setting),
            'updated_at' => $setting->updated_at?->format('c'),
        ]);
    }

    private function linksPayload(?SocialMediaSetting $setting): array
    {
        return collect(self::PLATFORM_KEYS)
            ->mapWithKeys(fn (string $platform): array => [$platform => $setting?->{"{$platform}_url"} ?? ''])
            ->all();
    }
}
