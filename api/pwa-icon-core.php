<?php
declare(strict_types=1);

require_once __DIR__.'/image-generation-core.php';
require_once __DIR__.'/pwa-splash-core.php';

final class SfPwaIconUnavailable extends RuntimeException {}

function sf_pwa_icon_prepare(array $source, int $adminId): array {
    $asset = $source['asset'];
    $info = @getimagesize($source['path']);
    if (!$info || $info['mime'] !== $asset['mime_type'] || min($info[0], $info[1]) < 1 ||
        max($info[0], $info[1]) > 4096 || $info[0] * $info[1] > 4194304) {
        throw new InvalidArgumentException('Choose a supported PNG, JPEG or WebP image up to four megapixels.');
    }
    if ($info['mime'] === 'image/png' && $info[0] === 512 && $info[1] === 512) return $asset;

    $folder = 'storage/media/pwa-icons';
    $directory = SF_ROOT.'/'.$folder;
    if ((!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) ||
        is_link($directory) || realpath($directory) !== realpath(SF_ROOT.'/storage/media').'/pwa-icons') {
        throw new RuntimeException('Install icon storage is unavailable.');
    }
    $key = hash('sha256', 'stonefellow-pwa-icon-512-contain-v1:'.$source['sha256']);
    $relative = $folder.'/'.$key.'.png';
    $destination = SF_ROOT.'/'.$relative;
    $lock = @fopen($directory.'/.stage.lock', 'c');
    if ($lock === false) throw new RuntimeException('Install icon storage is unavailable.');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Install icon storage is busy.');
        $saved = sf_db()->prepare('SELECT id FROM media_assets WHERE stored_path=? LIMIT 1');
        $saved->execute([$relative]);
        $existing = (int)$saved->fetchColumn();
        $saved->closeCursor();
        if ($existing > 0) {
            $candidate = sf_image_gen_reference_candidate($existing);
            $size = @getimagesize($candidate['path']);
            if (!$size || $size['mime'] !== 'image/png' || $size[0] !== 512 || $size[1] !== 512) {
                throw new RuntimeException('The existing install icon is invalid.');
            }
            return $candidate['asset'];
        }

        $decode = match ($info['mime']) {
            'image/png' => 'imagecreatefrompng',
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/webp' => 'imagecreatefromwebp',
        };
        if (!function_exists($decode) || !function_exists('imagecreatetruecolor') || !function_exists('imagepng') ||
            ($info['mime'] === 'image/webp' && !(imagetypes() & IMG_WEBP))) {
            throw new SfPwaIconUnavailable('Automatic conversion needs PHP GD with support for this image format and PNG. You can upload a square 512 × 512 PNG in PWA & Splash.');
        }
        $image = @$decode($source['path']);
        if (!$image || imagesx($image) !== $info[0] || imagesy($image) !== $info[1]) {
            throw new InvalidArgumentException('The approved artwork could not be decoded. Choose a different image or upload a square PNG in PWA & Splash.');
        }
        $icon = imagecreatetruecolor(512, 512);
        if (!$icon) throw new RuntimeException('Could not prepare the install icon.');
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
        $scale = 512 / max($info[0], $info[1]);
        $width = max(1, (int)round($info[0] * $scale));
        $height = max(1, (int)round($info[1] * $scale));
        if (!imagecopyresampled($icon, $image, intdiv(512 - $width, 2), intdiv(512 - $height, 2),
            0, 0, $width, $height, $info[0], $info[1])) {
            throw new RuntimeException('Could not resize the install icon.');
        }

        $temporary = tempnam($directory, '.icon-');
        if ($temporary === false) throw new RuntimeException('Could not prepare install icon storage.');
        try {
            if (!imagepng($icon, $temporary, 6) || !@rename($temporary, $destination)) {
                throw new RuntimeException('Could not store the install icon.');
            }
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
        // Media Library deduplicates identical bytes across all image placements.
        $prepared = sf_media_register_existing_file($relative, 'Stonefellow install icon.png', $adminId);
        if (!$prepared) throw new RuntimeException('Could not register the install icon.');
        if ($prepared['stored_path'] !== $relative) @unlink($destination);
        return $prepared;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function sf_pwa_icon_stage(int $jobId, int $revision, int $adminId): array {
    if (sf_pwa_splash_state()['draft']['revision'] !== $revision) {
        throw new SfPwaSplashConflict('The PWA draft changed in another tab. Use the icon again to stage it in the current draft.');
    }
    sf_image_gen_schema();
    $job = sf_image_gen_job($jobId);
    $assetId = (int)($job['media_asset_id'] ?? 0);
    if (($job['status'] ?? '') !== 'ready' || (int)($job['asset_approved_by'] ?? 0) < 1 || $assetId < 1) {
        throw new InvalidArgumentException('Approve the image in Image Studio before using it as the install icon.');
    }
    $source = sf_image_gen_reference_candidate($assetId);
    $iconId = (int)sf_pwa_icon_prepare($source, $adminId)['id'];
    $state = sf_pwa_splash_state();
    if ($state['draft']['revision'] !== $revision) {
        throw new SfPwaSplashConflict('The PWA draft changed in another tab. Use the icon again to stage it in the current draft.');
    }
    if ((int)$state['draft']['iconAssetId'] === $iconId) return $state;
    return sf_pwa_splash_update($adminId, 'save', $revision, ['iconAssetId'=>$iconId], []);
}
