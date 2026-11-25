<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebsiteVideoSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class WebsiteVideoController extends Controller
{
    private const SETTING_ID = 1;

    public function publicVideo(): JsonResponse
    {
        $setting = WebsiteVideoSetting::query()->find(self::SETTING_ID);

        return response()->json($setting ? $this->publicPayload($setting) : null);
    }

    public function show(): JsonResponse
    {
        $setting = WebsiteVideoSetting::query()->find(self::SETTING_ID);

        return response()->json(['video' => $setting ? $this->adminPayload($setting) : null]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($request->input('video_type') === 'upload') {
            $uploadedVideo = $request->file('video');

            if (!$uploadedVideo) {
                return response()->json([
                    'message' => 'Please select an MP4 or WebM video to upload.',
                    'errors' => ['video' => ['No video file reached Laravel. Check PHP upload limits and the temporary upload folder.']],
                ], 422);
            }

            if (!$uploadedVideo->isValid()) {
                $message = match ($uploadedVideo->getError()) {
                    UPLOAD_ERR_INI_SIZE => 'The video exceeds PHP upload_max_filesize ('.ini_get('upload_max_filesize').').',
                    UPLOAD_ERR_FORM_SIZE => 'The video exceeds the form upload limit.',
                    UPLOAD_ERR_PARTIAL => 'The video upload was interrupted. Please retry.',
                    UPLOAD_ERR_NO_FILE => 'No video file was received.',
                    UPLOAD_ERR_NO_TMP_DIR => 'PHP upload temporary folder is missing or unavailable.',
                    UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded video to its temporary folder.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the video upload.',
                    default => 'The video upload failed before Laravel validation. Check PHP upload settings.',
                };

                return response()->json(['message' => $message, 'errors' => ['video' => [$message]]], 422);
            }
        }

        $validated = $request->validate([
            'video_type' => ['required', Rule::in(['upload', 'youtube'])],
            'video' => [
                'nullable',
                'file',
                'mimes:mp4,webm',
                'mimetypes:video/mp4,video/webm,video/x-m4v,application/mp4',
                'max:'.config('website_video.max_size_kb'),
            ],
            'youtube_url' => ['nullable', 'string', 'max:500'],
        ]);

        $setting = WebsiteVideoSetting::query()->find(self::SETTING_ID);
        $videoType = $validated['video_type'];
        $newPath = null;
        $oldPath = $setting?->video_path;

        if ($videoType === 'upload') {
            if (!$request->hasFile('video')) {
                return response()->json(['message' => 'Please select an MP4 or WebM video to upload.'], 422);
            }

            $newPath = $request->file('video')->store('website', 'public');
        }

        $youtubeVideoId = null;
        $youtubeUrl = null;
        if ($videoType === 'youtube') {
            $youtubeUrl = trim((string) ($validated['youtube_url'] ?? ''));
            $youtubeVideoId = $this->youtubeVideoId($youtubeUrl);

            if (!$youtubeVideoId) {
                if ($newPath) Storage::disk('public')->delete($newPath);

                return response()->json(['message' => 'Enter a valid YouTube watch, embed, shorts, or youtu.be URL.'], 422);
            }
        }

        try {
            $setting = DB::transaction(function () use ($setting, $videoType, $newPath, $youtubeUrl, $youtubeVideoId, $request) {
                $setting ??= new WebsiteVideoSetting;
                $setting->id = self::SETTING_ID;
                $setting->video_type = $videoType;
                $setting->video_path = $newPath;
                $setting->youtube_url = $youtubeUrl;
                $setting->youtube_video_id = $youtubeVideoId;
                $setting->updated_by = $request->user()->id;
                $setting->save();

                return $setting;
            });
        } catch (Throwable $exception) {
            if ($newPath) Storage::disk('public')->delete($newPath);

            throw $exception;
        }

        if ($oldPath && $oldPath !== $setting->video_path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Website introduction video updated successfully.',
            'video' => $this->adminPayload($setting),
        ]);
    }

    private function publicPayload(WebsiteVideoSetting $setting): array
    {
        if ($setting->video_type === 'upload' && $setting->video_path) {
            return [
                'video_type' => 'upload',
                'video_url' => Storage::disk('public')->url($setting->video_path),
            ];
        }

        if ($setting->video_type === 'youtube' && $setting->youtube_video_id) {
            return [
                'video_type' => 'youtube',
                'youtube_video_id' => $setting->youtube_video_id,
                'youtube_embed_url' => $this->youtubeEmbedUrl($setting->youtube_video_id),
            ];
        }

        return [];
    }

    private function adminPayload(WebsiteVideoSetting $setting): array
    {
        return array_merge($this->publicPayload($setting), [
            'video_filename' => $setting->video_path ? basename($setting->video_path) : null,
            'youtube_url' => $setting->youtube_url,
            // Carbon's isoFormat/toISOString requires PHP intl, which is not
            // enabled in every XAMPP deployment. Native DateTime formatting
            // keeps this API response portable.
            'updated_at' => $setting->updated_at?->format('c'),
        ]);
    }

    private function youtubeVideoId(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        $path = trim((string) ($parts['path'] ?? ''), '/');

        if ($host === 'youtu.be') {
            $id = explode('/', $path)[0] ?? '';
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $segments = $path === '' ? [] : explode('/', $path);
            $id = match ($segments[0] ?? '') {
                'watch' => $query['v'] ?? '',
                'embed', 'shorts' => $segments[1] ?? '',
                default => '',
            };
        } else {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $id) ? $id : null;
    }

    private function youtubeEmbedUrl(string $videoId): string
    {
        return 'https://www.youtube.com/embed/'.rawurlencode($videoId).'?rel=0';
    }
}
