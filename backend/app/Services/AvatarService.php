<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Crops an uploaded photo to a square and resizes it to 256x256 JPEG using GD. */
class AvatarService
{
    public const SIZE = 256;

    public function store(User $user, UploadedFile $file): string
    {
        $source = imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        abort_if($source === false, 422, 'The image could not be read.');

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $avatar = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled(
            $avatar, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE,
            $side, $side
        );

        ob_start();
        imagejpeg($avatar, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($avatar);

        $path = "avatars/{$user->id}-".Str::random(8).'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->update(['avatar' => $path]);

        return $path;
    }
}
