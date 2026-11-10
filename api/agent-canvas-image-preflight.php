<?php
declare(strict_types=1);

require_once __DIR__.'/agent-canvas-image-skill.php';
require_once __DIR__.'/agent-canvas-music-core.php';
require_once __DIR__.'/canvas-resource-core.php';

function sf_agent_canvas_image_preflight(array $user,string $brief,bool $editing=false): array {
    [$brief]=sf_agent_canvas_music_selection($brief);
    $briefLimit=$editing?1800:SF_CUSTOM_CANVAS_BRIEF_MAX_BYTES;
    if(strlen($brief)>$briefLimit||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$brief)!==0)
        throw new InvalidArgumentException('Provide a canvas brief within '.$briefLimit.' UTF-8 bytes.');
    $count=$editing?1:sf_agent_canvas_requested_image_count($brief);
    $quota=sf_image_gen_user_quota((int)$user['id']);
    $review=$count!==null&&$count>1&&preg_match('/\b(?:choose|pick|select|compare)\b.{0,50}\b(?:one|which|best|variations?|images?|options?)\b|\b(?:one|which|best)\b.{0,40}\b(?:choose|pick|select)\b/iu',$brief)===1;
    return ['requested_count'=>$count,'max_count'=>$editing?1:SF_AGENT_CANVAS_IMAGE_LIMIT,'review_images'=>$review,
        'generation_enabled'=>sf_agent_canvas_image_available($user)]+$quota;
}
