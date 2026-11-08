<?php
declare(strict_types=1);

const SF_PWA_SPLASH_META_KEY = 'site.pwa_splash';
const SF_PWA_SPLASH_SURFACES = ['launch', 'waiting'];
const SF_PWA_SPLASH_MAX_SLOTS = 4;
const SF_PWA_SPLASH_SLOT_BYTES = 512 * 1024;
const SF_PWA_SPLASH_IMAGE_BYTES = 1024 * 1024;
const SF_PWA_SPLASH_SLOT_EDGE = 1600;

final class SfPwaSplashConflict extends RuntimeException {}

function sf_pwa_splash_defaults(): array {
    $surface = ['mode'=>'default', 'assetId'=>0, 'asset'=>null, 'title'=>'', 'message'=>'', 'html'=>'', 'css'=>'', 'js'=>'', 'imageSlots'=>[]];
    return [
        'revision'=>0, 'backgroundColor'=>'', 'iconAssetId'=>0, 'startupAssetId'=>0,
        'icon'=>null, 'startup'=>null, 'launch'=>$surface, 'waiting'=>$surface,
    ];
}

function sf_pwa_splash_decode(string $value): array {
    $defaults = sf_pwa_splash_defaults();
    if ($value === '') {
        return ['draft'=>$defaults, 'published'=>$defaults];
    }
    $state = json_decode($value, true, 12, JSON_THROW_ON_ERROR);
    foreach (['draft', 'published'] as $key) {
        $snapshot = $state[$key] ?? null;
        if (!is_array($snapshot)) throw new UnexpectedValueException('Invalid splash snapshot.');
        foreach (SF_PWA_SPLASH_SURFACES as $surface) {
            $design = $snapshot[$surface] ?? null;
            if (!is_array($design) || !in_array($design['mode'] ?? null, ['default', 'image', 'code'], true)) {
                throw new UnexpectedValueException('Invalid splash design.');
            }
            foreach (['title', 'message', 'html', 'css', 'js'] as $field) {
                if (!is_string($design[$field] ?? null)) throw new UnexpectedValueException('Invalid splash text.');
            }
            $slots = $design['imageSlots'] ?? [];
            if (!is_array($slots) || !array_is_list($slots) || count($slots) > SF_PWA_SPLASH_MAX_SLOTS) {
                throw new UnexpectedValueException('Invalid splash image slots.');
            }
            $state[$key][$surface]['imageSlots'] = $slots;
        }
        if (!is_int($snapshot['revision'] ?? null) || !is_string($snapshot['backgroundColor'] ?? null)) {
            throw new UnexpectedValueException('Invalid splash settings.');
        }
    }
    return $state;
}

function sf_pwa_splash_state(): array {
    return sf_pwa_splash_decode(sf_installed() ? sf_meta_get(SF_PWA_SPLASH_META_KEY) : '');
}

function sf_pwa_splash_has_changes(array $state): bool {
    ['draft'=>$draft, 'published'=>$published] = $state;
    unset($draft['revision'], $published['revision']);
    return $draft !== $published;
}

function sf_pwa_splash_public_path(string $path): string {
    static $base;
    $base ??= sf_installed() ? trim((string)parse_url(sf_public_base_url(), PHP_URL_PATH), '/') : '';
    return '/'.($base === '' ? '' : $base.'/').$path;
}

function sf_pwa_splash_surface_url(string $surface, int $revision, bool $draft = false, bool $shell = false): string {
    return sf_pwa_splash_public_path('pwa-splash-preview.php?surface='.rawurlencode($surface)
        .'&revision='.$revision.($draft ? '&draft=1'.($shell ? '&shell=1' : '') : ''));
}

function sf_pwa_splash_media_url(?array $asset): string {
    return is_string($asset['uuid'] ?? null) && is_string($asset['variant'] ?? null)
        ? sf_pwa_splash_public_path(sf_media_managed_url($asset, $asset['variant'])) : '';
}

function sf_pwa_splash_present(array $snapshot, bool $draft = false, bool $admin = false): array {
    $icon = $snapshot['icon'] ?? null;
    $snapshot['iconUrl'] = sf_pwa_splash_media_url($icon);
    $snapshot['iconSize'] = (int)($icon['width'] ?? 0);
    $snapshot['iconMime'] = (string)($icon['mime'] ?? '');
    $snapshot['startupUrl'] = sf_pwa_splash_media_url($snapshot['startup'] ?? null);
    unset($snapshot['icon'], $snapshot['startup']);
    foreach (SF_PWA_SPLASH_SURFACES as $surface) {
        $design = &$snapshot[$surface];
        $design['assetUrl'] = $admin || $design['mode'] === 'image' ? sf_pwa_splash_media_url($design['asset'] ?? null) : '';
        $design['previewUrl'] = $admin || $design['mode'] === 'code'
            ? sf_pwa_splash_surface_url($surface, $snapshot['revision'], $draft, $draft) : '';
        unset($design['asset']);
        if ($admin) {
            $design['imageSlots'] = array_map(static fn(array $slot): array => [
                'name'=>$slot['name'],
                'assetId'=>$slot['assetId'],
                'assetUrl'=>sf_pwa_splash_media_url($slot['asset']),
            ], $design['imageSlots']);
        } else {
            unset($design['html'], $design['css'], $design['js'], $design['imageSlots']);
        }
        unset($design);
    }
    return $snapshot;
}

function sf_pwa_splash_published(): array {
    try {
        return sf_pwa_splash_present(sf_pwa_splash_state()['published']);
    } catch (Throwable $error) {
        error_log('[Stonefellow PWA splash settings] '.get_class($error));
        return sf_pwa_splash_present(sf_pwa_splash_defaults());
    }
}

function sf_pwa_splash_asset_id(mixed $value): int {
    if ($value === null || $value === '' || $value === 0 || $value === '0') return 0;
    $id = is_int($value) || is_string($value)
        ? filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : false;
    if ($id === false) throw new InvalidArgumentException('Choose a valid Media Library asset.');
    return $id;
}

function sf_pwa_splash_guard_asset(int $id, string $role): void {
    try {
        sf_media_guard_link_exposure($id, 'pwa_'.$role, true, false);
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException) throw $error;
        throw new InvalidArgumentException($error->getMessage(), 0, $error);
    }
}

function sf_pwa_splash_asset(int $id, string $role): ?array {
    if ($id === 0) return null;
    $asset = sf_media_asset($id, true);
    $label = match ($role) {
        'icon' => 'install icon',
        'startup' => 'static startup artwork',
        'launch' => 'app launch image',
        'waiting' => 'jukebox waiting image',
    };
    $types = match ($role) {
        'icon' => ['image/png'],
        'startup' => ['image/png', 'image/jpeg', 'image/webp'],
        default => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
    };
    $root = realpath(SF_ROOT.'/storage/media');
    $file = $asset ? realpath(SF_ROOT.'/'.ltrim($asset['stored_path'], '/')) : false;
    $image = $root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file)
        ? @getimagesize($file) : false;
    if (!$asset || $asset['category'] !== 'image' || !$image || $image['mime'] !== $asset['mime_type'] ||
        !in_array($image['mime'], $types, true)) {
        throw new InvalidArgumentException('Choose a supported Media Library image for the '.$label.'.');
    }
    if ($role === 'icon' && ($image[0] !== $image[1] || $image[0] < 192)) {
        throw new InvalidArgumentException('The install icon must be a square PNG at least 192 pixels wide.');
    }
    sf_pwa_splash_guard_asset($id, $role);
    $variant = 'original';
    if (in_array($role, SF_PWA_SPLASH_SURFACES, true) && $image['mime'] !== 'image/gif' &&
        isset($asset['variants']['large']['path']) && is_file(SF_ROOT.'/'.$asset['variants']['large']['path'])) {
        $variant = 'large';
    }
    return ['uuid'=>(string)$asset['uuid'], 'variant'=>$variant, 'width'=>$image[0], 'mime'=>$image['mime']];
}

/** Read one bounded, verified raster rendition from managed storage. */
function sf_pwa_splash_slot_image(int $id, ?array $expected = null): array {
    $types = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
    $asset = sf_media_asset($id, true);
    if (!$asset || $asset['category'] !== 'image' || !in_array($asset['mime_type'], $types, true)) {
        throw new InvalidArgumentException('Choose a PNG, JPEG, WebP, or GIF from the Media Library for each slot.');
    }
    sf_pwa_splash_guard_asset($id, 'image_slot');
    $variants = ['original'=>$asset['stored_path']];
    if ($asset['mime_type'] !== 'image/gif') {
        foreach (['medium', 'thumb'] as $variant) {
            if (is_string($asset['variants'][$variant]['path'] ?? null)) $variants[$variant] = $asset['variants'][$variant]['path'];
        }
    }
    $root = realpath(SF_ROOT.'/storage/media');
    foreach ($variants as $variant => $path) {
        if ($expected !== null && ($expected['variant'] ?? null) !== $variant) continue;
        $file = realpath(SF_ROOT.'/'.ltrim($path, '/'));
        if (!$root || !$file || !str_starts_with($file, $root.DIRECTORY_SEPARATOR) || !is_file($file)) continue;
        $bytes = @file_get_contents($file, false, null, 0, SF_PWA_SPLASH_SLOT_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) > SF_PWA_SPLASH_SLOT_BYTES) continue;
        $image = @getimagesizefromstring($bytes);
        if (!$image || max($image[0], $image[1]) > SF_PWA_SPLASH_SLOT_EDGE) continue;
        $mime = $image['mime'];
        $hash = hash('sha256', $bytes);
        if ($variant === 'original' && ($mime !== $asset['mime_type'] || !hash_equals($asset['sha256'], $hash))) continue;
        if ($variant !== 'original' && !in_array($mime, ['image/jpeg', 'image/webp'], true)) continue;
        $rendition = ['uuid'=>(string)$asset['uuid'], 'variant'=>$variant, 'mime'=>$mime, 'sha256'=>$hash];
        if ($expected !== null && $expected !== $rendition) continue;
        return ['asset'=>$rendition, 'bytes'=>$bytes];
    }
    throw new InvalidArgumentException($expected === null
        ? 'Choose a splash slot image with a rendition up to 512 KiB and 1600 pixels per side.'
        : 'A saved splash slot image is unavailable. Select the image again to update it.');
}

/** Accept only explicit slot names and managed IDs; never accept client URLs or bytes. */
function sf_pwa_splash_image_slots(mixed $slots, bool $render = false): array {
    if (!is_array($slots) || !array_is_list($slots) || count($slots) > SF_PWA_SPLASH_MAX_SLOTS) {
        throw new InvalidArgumentException('Choose up to four Media Library image slots per splash.');
    }
    $result = [];
    $names = [];
    $size = 0;
    foreach ($slots as $slot) {
        $name = $slot['name'] ?? null;
        if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9-]{0,23}\z/', $name) || isset($names[$name])) {
            throw new InvalidArgumentException('Give each image slot a unique name using lowercase letters, numbers or hyphens. Start with a letter and use up to 24 characters.');
        }
        $names[$name] = true;
        $id = sf_pwa_splash_asset_id($slot['assetId'] ?? null);
        if (!$id) throw new InvalidArgumentException('Choose a Media Library image for the '.$name.' slot.');
        $expected = $slot['asset'] ?? null;
        if ($render && !is_array($expected)) throw new InvalidArgumentException('A saved splash slot image is unavailable.');
        $image = sf_pwa_splash_slot_image($id, $render ? $expected : null);
        $size += strlen($image['bytes']);
        if ($size > SF_PWA_SPLASH_IMAGE_BYTES) {
            throw new InvalidArgumentException('Splash image slots may use up to 1 MiB total per screen.');
        }
        $result[] = $render
            ? ['name'=>$name, 'url'=>'data:'.$image['asset']['mime'].';base64,'.base64_encode($image['bytes'])]
            : ['name'=>$name, 'assetId'=>$id, 'asset'=>$image['asset']];
    }
    return $result;
}

function sf_pwa_splash_image_slot_names(mixed $imageSlots): array {
    return array_column(sf_pwa_splash_image_slots($imageSlots), 'name');
}

function sf_pwa_splash_text(mixed $value, int $limit, string $field): string {
    if (!is_string($value) || preg_match('//u', $value) !== 1 ||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) !== 0) {
        throw new InvalidArgumentException('Enter valid text for '.$field.'.');
    }
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if (strlen($value) > $limit) throw new InvalidArgumentException('The '.$field.' is too long.');
    return $value;
}

function sf_pwa_splash_normalize(array $settings, array $previous): array {
    $snapshot = sf_pwa_splash_defaults();
    $color = $settings['backgroundColor'] ?? $previous['backgroundColor'];
    if (!is_string($color) || ($color !== '' && !preg_match('/\A#[a-f0-9]{6}\z/i', $color))) {
        throw new InvalidArgumentException('Choose a six-digit hex background color or clear it to use the site theme.');
    }
    $snapshot['backgroundColor'] = strtolower($color);
    foreach (['icon'=>'iconAssetId', 'startup'=>'startupAssetId'] as $role => $field) {
        $snapshot[$field] = sf_pwa_splash_asset_id(array_key_exists($field, $settings) ? $settings[$field] : $previous[$field]);
        $snapshot[$role] = sf_pwa_splash_asset($snapshot[$field], $role);
    }
    foreach (SF_PWA_SPLASH_SURFACES as $surface) {
        $changes = $settings[$surface] ?? [];
        if (!is_array($changes) || ($changes && array_is_list($changes))) {
            throw new InvalidArgumentException('Choose a valid splash design.');
        }
        $design = array_replace($previous[$surface], $changes);
        if (!in_array($design['mode'], ['default', 'image', 'code'], true)) {
            throw new InvalidArgumentException('Choose Default, Image, or Code for the splash.');
        }
        $id = sf_pwa_splash_asset_id($design['assetId']);
        $snapshot[$surface] = [
            'mode'=>$design['mode'], 'assetId'=>$id, 'asset'=>sf_pwa_splash_asset($id, $surface),
            'title'=>trim(sf_pwa_splash_text($design['title'], 400, 'title')),
            'message'=>trim(sf_pwa_splash_text($design['message'], 1200, 'message')),
            'html'=>sf_pwa_splash_text($design['html'], 12000, 'HTML'),
            'css'=>sf_pwa_splash_text($design['css'], 12000, 'CSS'),
            'js'=>sf_pwa_splash_text($design['js'], 12000, 'JavaScript'),
            'imageSlots'=>sf_pwa_splash_image_slots($design['imageSlots']),
        ];
        foreach (['title'=>100, 'message'=>300] as $field => $limit) {
            if (preg_match_all('/./us', $snapshot[$surface][$field]) > $limit) {
                throw new InvalidArgumentException('The '.$field.' is too long.');
            }
        }
    }
    return $snapshot;
}

function sf_pwa_splash_code(array $design): array {
    require_once __DIR__.'/agent-scene-core.php';
    $code = sf_agent_scene_code($design);
    if ($code === null) {
        throw new InvalidArgumentException('Use valid Agent Canvas markup, CSS and optional JavaScript in separate fields.');
    }
    $code['libraries'] = [];
    $locks = sf_canvas_library_declared($code['js'] ?? '');
    if ($locks) {
        $available = array_column(sf_canvas_library_available(), null, 'lock');
        foreach ($locks as $lock) {
            $library = $available[$lock] ?? null;
            if (!$library) throw new InvalidArgumentException('This design uses an Agent Canvas library that is unavailable.');
            $code['libraries'][] = $library;
        }
    }
    return $code;
}

function sf_pwa_splash_publish_check(array $snapshot): void {
    foreach (SF_PWA_SPLASH_SURFACES as $surface) {
        $design = $snapshot[$surface];
        if ($design['mode'] === 'image' && !$design['assetId']) {
            throw new InvalidArgumentException('Choose an image for the '.$surface.' splash.');
        }
        if ($design['mode'] === 'code') sf_pwa_splash_code($design);
    }
}

function sf_pwa_splash_import(int $adminId, array $settings, array $request): array {
    require_once __DIR__.'/agent-scene-core.php';
    $surface = $request['surface'] ?? null;
    if (!in_array($surface, SF_PWA_SPLASH_SURFACES, true)) throw new InvalidArgumentException('Choose a splash screen.');
    $id = $request['canvasId'] ?? null;
    $id = is_int($id) || is_string($id) ? filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : false;
    if ($id === false) throw new InvalidArgumentException('Choose one of your saved Agent Canvases.');
    $scene = sf_agent_scene_history_get($adminId, $id);
    if (!$scene || $scene['canvas_kind'] !== 'custom') throw new InvalidArgumentException('Saved custom Agent Canvas not found.');
    $scene['html'] = sf_agent_scene_strip_legacy_player($scene['html']);
    $scene = sf_agent_scene_scrub_copy($scene);
    $code = sf_pwa_splash_code($scene);
    $design = $settings[$surface] ?? [];
    if (!is_array($design)) throw new InvalidArgumentException('Choose a valid splash design.');
    $settings[$surface] = array_replace($design, [
        'mode'=>'code', 'html'=>$code['html'], 'css'=>$code['css'], 'js'=>$code['js'] ?? '',
    ]);
    return $settings;
}

/** Keep selected assets in Media Library usage and expose only live artwork. */
function sf_pwa_splash_sync_media(PDO $db, array $snapshot, bool $draft): void {
    $entity = $draft ? 'pwa-splash-draft' : 'pwa-splash-published';
    $db->prepare("DELETE FROM media_links WHERE entity_type='site' AND entity_id=?")->execute([$entity]);
    $assets = ['icon'=>$snapshot['iconAssetId'], 'startup'=>$snapshot['startupAssetId']];
    foreach (SF_PWA_SPLASH_SURFACES as $surface) {
        $design = $snapshot[$surface];
        if ($draft || $design['mode'] === 'image') $assets[$surface] = $design['assetId'];
        foreach ($design['imageSlots'] as $slot) {
            // The frame exposes the published bytes. Ordinary Media Library requests remain private.
            sf_media_attach($slot['assetId'], 'site', $entity, 'pwa_'.$surface.'_slot_'.$slot['name']);
        }
    }
    foreach ($assets as $role => $id) {
        if ($id) sf_media_attach($id, 'site', $entity, 'pwa_'.$role, ['public_visible'=>!$draft]);
    }
}

function sf_pwa_splash_update(int $adminId, string $action, int $revision, array $settings, array $request): array {
    if ($action === 'import') $settings = sf_pwa_splash_import($adminId, $settings, $request);
    sf_site_settings_ensure_schema();
    sf_media_ensure_schema();
    sf_ops_ensure_schema();
    $db = sf_db();
    $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $insert = $sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $db->prepare($insert.' INTO app_meta(meta_key,meta_value,updated_at) VALUES(?,?,?)')
        ->execute([SF_PWA_SPLASH_META_KEY, '', gmdate('c')]);
    $db->beginTransaction();
    try {
        if ($sqlite) {
            // Acquire the writer lock before reading the revision.
            $db->prepare('UPDATE app_meta SET updated_at=updated_at WHERE meta_key=?')->execute([SF_PWA_SPLASH_META_KEY]);
        }
        $read = $db->prepare('SELECT meta_value FROM app_meta WHERE meta_key=?'.($sqlite ? '' : ' FOR UPDATE'));
        $read->execute([SF_PWA_SPLASH_META_KEY]);
        $state = sf_pwa_splash_decode((string)$read->fetchColumn());
        $read->closeCursor();
        if ($revision !== $state['draft']['revision']) {
            throw new SfPwaSplashConflict('The draft changed in another tab. Reload before saving again.');
        }
        $draft = $action === 'restore' ? $state['published'] : sf_pwa_splash_normalize($settings, $state['draft']);
        $draft['revision'] = $revision + 1;
        $state['draft'] = $draft;
        if ($action === 'publish') {
            sf_pwa_splash_publish_check($draft);
            $state['published'] = $draft;
            sf_pwa_splash_sync_media($db, $draft, false);
        }
        sf_pwa_splash_sync_media($db, $draft, true);
        $db->prepare('UPDATE app_meta SET meta_value=?,updated_at=? WHERE meta_key=?')->execute([
            json_encode($state, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            gmdate('c'), SF_PWA_SPLASH_META_KEY,
        ]);
        sf_log_admin_action($adminId, 'pwa_splash_'.$action, 'site', 'pwa_splash', ['revision'=>$draft['revision']]);
        $db->commit();
        return $state;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
