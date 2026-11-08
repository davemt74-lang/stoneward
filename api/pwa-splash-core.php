<?php
declare(strict_types=1);

const SF_PWA_SPLASH_META_KEY = 'site.pwa_splash';
const SF_PWA_SPLASH_SURFACES = ['launch', 'waiting'];

final class SfPwaSplashConflict extends RuntimeException {}

function sf_pwa_splash_defaults(): array {
    $surface = ['mode'=>'default', 'assetId'=>0, 'asset'=>null, 'title'=>'', 'message'=>'', 'html'=>'', 'css'=>'', 'js'=>''];
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

function sf_pwa_splash_surface_url(string $surface, int $revision, bool $draft = false): string {
    return sf_pwa_splash_public_path('pwa-splash-preview.php?surface='.rawurlencode($surface)
        .'&revision='.$revision.($draft ? '&draft=1' : ''));
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
            ? sf_pwa_splash_surface_url($surface, $snapshot['revision'], $draft) : '';
        unset($design['asset']);
        if (!$admin) unset($design['html'], $design['css'], $design['js']);
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
    try {
        sf_media_guard_link_exposure($id, 'pwa_'.$role, true, false);
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException) throw $error;
        throw new InvalidArgumentException($error->getMessage(), 0, $error);
    }
    $variant = 'original';
    if (in_array($role, SF_PWA_SPLASH_SURFACES, true) && $image['mime'] !== 'image/gif' &&
        isset($asset['variants']['large']['path']) && is_file(SF_ROOT.'/'.$asset['variants']['large']['path'])) {
        $variant = 'large';
    }
    return ['uuid'=>(string)$asset['uuid'], 'variant'=>$variant, 'width'=>$image[0], 'mime'=>$image['mime']];
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
