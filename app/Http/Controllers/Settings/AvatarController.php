<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TaikoGameVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AvatarRequest;
use App\Models\GameCard;
use App\Models\PlayerCosmetic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AvatarController extends Controller
{
    /** Max output dimension for the stored avatar PNG. */
    private const MAX_SIZE = 512;

    public function edit(Request $request): Response
    {
        $version = $request->attributes->get('taikoGameVersion');
        $card = GameCard::query()
            ->whereHas('player', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('player')
            ->first();

        $user = $request->user();
        $faces = $this->faces();

        // Prefer the settings a saved avatar was generated from; otherwise seed from the
        // equipped costume and the player's stock Don colours (face 0 / body 1 / limb 3).
        $costume = $user->avatar_costume;
        if ($costume === null && $card !== null && $version instanceof TaikoGameVersion) {
            $costume = (int) PlayerCosmetic::resolve($card->player->baid, $version)->costume_1;
        }

        return Inertia::render('settings/DonChanAvatar', [
            'hasAvatar' => $user->avatar !== null,
            'avatar' => $user->avatar,
            'versionLabel' => $version?->label() ?? '',
            'kigurumiSheet' => $this->kigurumiSheet($version),
            'faces' => $faces,
            'defaults' => [
                'costume' => $costume ?? 0,
                'colorFace' => $user->avatar_color_face ?? $card?->player->color_face ?? 0,
                'colorBody' => $user->avatar_color_body ?? $card?->player->color_body ?? 1,
                'colorLimb' => $user->avatar_color_limb ?? $card?->player->color_limb ?? 3,
                'face' => $user->avatar_face ?? ($faces[0] ?? null),
                'faceFrame' => $user->avatar_face_frame ?? 0,
            ],
        ]);
    }

    public function update(AvatarRequest $request): RedirectResponse
    {
        $user = $request->user();

        $raw = base64_decode(
            substr((string) $request->validated('image'), strlen('data:image/png;base64,')),
            true
        );

        // Re-decode with GD so any non-image payload smuggled into the data URL is
        // dropped: only real raster pixels survive imagecreatefromstring/imagepng.
        $source = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($source === false) {
            return back()->withErrors(['image' => __('Invalid image data.')]);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_SIZE / max($width, $height));
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        Storage::disk('public')->put("avatars/{$user->id}.png", $png);
        $user->forceFill([
            'avatar_updated_at' => now(),
            'avatar_costume' => (int) $request->validated('costume'),
            'avatar_color_face' => (int) $request->validated('color_face'),
            'avatar_color_body' => (int) $request->validated('color_body'),
            'avatar_color_limb' => (int) $request->validated('color_limb'),
            'avatar_face' => $request->validated('face'),
            'avatar_face_frame' => (int) $request->validated('face_frame'),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return to_route('avatar.edit');
    }

    /**
     * Kigurumi picker spritesheet for the current version. The slot ids double as the
     * cos/{id}.glb filenames the viewer loads (see scripts/donchan/export_web_assets.py).
     *
     * @return array{url: string, cell: int, width: int, height: int, items: array<int, array{id: int, x: int, y: int}>}|null
     */
    private function kigurumiSheet(?TaikoGameVersion $version): ?array
    {
        if (! $version instanceof TaikoGameVersion) {
            return null;
        }

        $path = public_path("costumes/{$version->value}/sheet.json");
        if (! File::exists($path)) {
            return null;
        }

        /** @var array{cell: int, sheet: array{0: int, 1: int}, slots: array<string, array<int, array{id: int, x: int, y: int}>>} $data */
        $data = json_decode(File::get($path), true);

        return [
            'url' => "/costumes/{$version->value}/sheet.png",
            'cell' => $data['cell'],
            'width' => $data['sheet'][0],
            'height' => $data['sheet'][1],
            'items' => $data['slots']['kigurumi'] ?? [],
        ];
    }

    /**
     * Face expression sheets available to the viewer (12 stacked 128x128 frames each).
     *
     * @return array<int, string>
     */
    private function faces(): array
    {
        $dir = public_path('donchan/face');
        if (! File::isDirectory($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($file): bool => $file->getExtension() === 'png')
            ->map(fn ($file): string => $file->getFilename())
            ->sort()
            ->values()
            ->all();
    }
}
