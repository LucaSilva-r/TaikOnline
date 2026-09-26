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

        $cosmetic = ($card !== null && $version instanceof TaikoGameVersion)
            ? PlayerCosmetic::resolve($card->player->baid, $version)
            : null;

        // Prefer the settings a saved avatar was generated from; otherwise seed from the
        // equipped costume parts and the player's stock Don colours (face 0 / body 1 / limb 3).
        return Inertia::render('settings/DonChanAvatar', [
            'hasAvatar' => $user->avatar !== null,
            'avatar' => $user->avatar,
            'versionLabel' => $version?->label() ?? '',
            'sheet' => $this->sheet(),
            'puchiSheet' => $this->puchiSheet(),
            'faces' => $faces,
            'defaults' => [
                'costume' => $user->avatar_costume ?? (int) ($cosmetic?->costume_1 ?? 0),
                'head' => $user->avatar_head ?? (int) ($cosmetic?->costume_2 ?? 0),
                'body' => $user->avatar_body ?? (int) ($cosmetic?->costume_3 ?? 0),
                'puchi' => $user->avatar_puchi ?? (int) ($cosmetic?->costume_5 ?? 0),
                'puchiFrame' => $user->avatar_puchi_frame ?? 0,
                'puchiX' => $user->avatar_puchi_x ?? 0.78,
                'puchiY' => $user->avatar_puchi_y ?? 0.78,
                'puchiScale' => $user->avatar_puchi_scale ?? 1.0,
                'colorFace' => $user->avatar_color_face ?? $card?->player->color_face ?? 0,
                'colorBody' => $user->avatar_color_body ?? $card?->player->color_body ?? 1,
                'colorLimb' => $user->avatar_color_limb ?? $card?->player->color_limb ?? 3,
                'face' => $user->avatar_face ?? ($faces[0] ?? null),
                'faceFrame' => $user->avatar_face_frame ?? 0,
                'animation' => $user->avatar_animation,
                'animationFrame' => $user->avatar_animation_frame ?? 0.0,
                'cameraYaw' => $user->avatar_camera_yaw ?? 0.0,
                'cameraPitch' => $user->avatar_camera_pitch ?? 0.0,
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

        Storage::disk('public')->put("avatars/{$user->id}.png", $png);
        $user->forceFill([
            'avatar_updated_at' => now(),
            'avatar_costume' => (int) $request->validated('costume'),
            'avatar_color_face' => (int) $request->validated('color_face'),
            'avatar_color_body' => (int) $request->validated('color_body'),
            'avatar_color_limb' => (int) $request->validated('color_limb'),
            'avatar_head' => (int) $request->validated('head'),
            'avatar_body' => (int) $request->validated('body'),
            'avatar_puchi' => (int) $request->validated('puchi'),
            'avatar_puchi_frame' => (int) $request->validated('puchi_frame'),
            'avatar_puchi_x' => (float) $request->validated('puchi_x'),
            'avatar_puchi_y' => (float) $request->validated('puchi_y'),
            'avatar_puchi_scale' => (float) $request->validated('puchi_scale'),
            'avatar_face' => $request->validated('face'),
            'avatar_face_frame' => (int) $request->validated('face_frame'),
            'avatar_animation' => $request->validated('animation'),
            'avatar_animation_frame' => (float) $request->validated('animation_frame'),
            'avatar_camera_yaw' => (float) $request->validated('camera_yaw'),
            'avatar_camera_pitch' => (float) $request->validated('camera_pitch'),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return to_route('avatar.edit');
    }

    /**
     * Costume picker spritesheet for the exported Don-chan 3D assets. The slot ids
     * double as the cos|head|body/{id}.glb filenames the viewer loads.
     *
     * @return array{url: string, cell: int, width: int, height: int, slots: array<string, array<int, array{id: int, x: int, y: int}>>}|null
     */
    private function sheet(): ?array
    {
        $path = public_path('donchan/sheet.json');
        if (! File::exists($path)) {
            return null;
        }

        /** @var array{cell: int, sheet: array{0: int, 1: int}, slots: array<string, array<int, array{id: int, x: int, y: int}>>} $data */
        $data = json_decode(File::get($path), true);

        return [
            'url' => '/donchan/sheet.png',
            'cell' => $data['cell'],
            'width' => $data['sheet'][0],
            'height' => $data['sheet'][1],
            'slots' => $data['slots'],
        ];
    }

    /**
     * Puchi-chara picker spritesheet. Each item stores the top-left of a
     * two-frame strip, with frames laid out horizontally.
     *
     * @return array{url: string, frameWidth: int, frameHeight: int, width: int, height: int, items: array<int, array{id: int, x: int, y: int}>}|null
     */
    private function puchiSheet(): ?array
    {
        $path = public_path('donchan/puchi-sheet.json');
        if (! File::exists($path)) {
            return null;
        }

        /** @var array{frameWidth: int, frameHeight: int, sheet: array{0: int, 1: int}, items: array<int, array{id: int, x: int, y: int}>} $data */
        $data = json_decode(File::get($path), true);

        return [
            'url' => '/donchan/puchi-sheet.png',
            'frameWidth' => $data['frameWidth'],
            'frameHeight' => $data['frameHeight'],
            'width' => $data['sheet'][0],
            'height' => $data['sheet'][1],
            'items' => $data['items'],
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
