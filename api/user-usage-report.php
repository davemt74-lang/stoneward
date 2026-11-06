<?php
declare(strict_types=1);

/**
 * Bounded, read-only aggregate report. Callers MUST establish administrator
 * authorization before returning this information to a browser or Agent.
 * No customer identifiers, conversations, or individual balances leave here.
 */
function sf_admin_user_usage_report(int $days=30): array {
    if (!in_array($days,[7,30,90],true)) throw new InvalidArgumentException('Unsupported reporting period.');
    sf_entitlements_ensure_schema();
    $pdo = sf_db();
    $since = gmdate('c',time()-$days*86400);
    $accounts = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN status='active' THEN 1 ELSE 0 END),0) active, COALESCE(SUM(CASE WHEN status='disabled' THEN 1 ELSE 0 END),0) disabled FROM users")->fetch() ?: [];
    $low = $pdo->query("SELECT COUNT(*) FROM users u LEFT JOIN user_token_balances b ON b.user_id=u.id WHERE u.role='user' AND u.status='active' AND COALESCE(b.balance,0)<=1000")->fetchColumn();
    $q = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN entry_type='usage' AND delta<0 THEN -delta ELSE 0 END),0) tokens_consumed,
        COALESCE(SUM(CASE WHEN entry_type='admin_grant' AND delta>0 THEN delta ELSE 0 END),0) free_tokens_granted,
        COALESCE(SUM(CASE WHEN entry_type='monthly_grant' AND delta>0 THEN delta ELSE 0 END),0) package_tokens_granted,
        COALESCE(SUM(CASE WHEN entry_type='usage' THEN input_tokens ELSE 0 END),0) input_tokens,
        COALESCE(SUM(CASE WHEN entry_type='usage' THEN output_tokens ELSE 0 END),0) output_tokens,
        COALESCE(SUM(CASE WHEN entry_type='usage' THEN voice_chars ELSE 0 END),0) voice_chars,
        COALESCE(SUM(CASE WHEN entry_type='usage' THEN 1 ELSE 0 END),0) usage_events
        FROM token_ledger WHERE created_at>=?");
    $q->execute([$since]);
    $totals = $q->fetch() ?: [];
    $providers = $pdo->prepare("SELECT provider,model,
        COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) consumed,
        COALESCE(SUM(input_tokens),0) input_tokens,
        COALESCE(SUM(output_tokens),0) output_tokens,
        COALESCE(SUM(voice_chars),0) voice_chars
        FROM token_ledger WHERE created_at>=? AND entry_type='usage'
        GROUP BY provider,model ORDER BY consumed DESC LIMIT 20");
    $providers->execute([$since]);
    // Sources are mapped to safe feature labels. Raw source values are never
    // sent to the aggregate Agent report (some future sources may contain IDs).
    $features=$pdo->prepare("SELECT CASE
        WHEN source IN ('agent-chat','agent-llm-intent') THEN 'Agent Chat + Routing'
        WHEN source IN ('agent-jev-route','agent-jev-eval') THEN 'Agent Brain + Evaluation'
        WHEN source='agent-voice' THEN 'Voice Synthesis'
        WHEN source='song-section-transcription' THEN 'Song Transcription'
        WHEN source='song-section-analysis' THEN 'Lyric Analysis'
        WHEN source='agent-listening-room' THEN 'Canvas Design'
        WHEN source='image-generation' THEN 'Image Generation'
        WHEN source='image-analysis' THEN 'Image Analysis'
        WHEN source='image-prompt-composer' THEN 'Image Prompt Refinement'
        ELSE 'Other AI' END feature,
        COUNT(*) events,
        COALESCE(SUM(CASE WHEN delta<0 THEN -delta ELSE 0 END),0) consumed,
        COALESCE(SUM(input_tokens),0) input_tokens,
        COALESCE(SUM(output_tokens),0) output_tokens,
        COALESCE(SUM(voice_chars),0) voice_chars
        FROM token_ledger WHERE created_at>=? AND entry_type='usage'
        GROUP BY feature ORDER BY consumed DESC LIMIT 12");
    $features->execute([$since]);
    return [
        'days'=>$days,'as_of_utc'=>gmdate('c'),
        'accounts'=>['total'=>(int)($accounts['total']??0),'active'=>(int)($accounts['active']??0),'disabled'=>(int)($accounts['disabled']??0),'low_balance'=>(int)$low],
        'usage'=>array_map('intval',$totals),
        'providers'=>array_map(static function(array $row):array {
            return ['provider'=>(string)$row['provider'],'model'=>(string)$row['model'],
                'consumed'=>(int)$row['consumed'],'input_tokens'=>(int)$row['input_tokens'],
                'output_tokens'=>(int)$row['output_tokens'],'voice_chars'=>(int)$row['voice_chars']];
        },$providers->fetchAll()),
        'features'=>array_map(static function(array $row):array {
            return ['feature'=>(string)$row['feature'],'events'=>(int)$row['events'],
                'consumed'=>(int)$row['consumed'],'input_tokens'=>(int)$row['input_tokens'],
                'output_tokens'=>(int)$row['output_tokens'],'voice_chars'=>(int)$row['voice_chars']];
        },$features->fetchAll()),
        'read_only'=>true,'source'=>'stonefellow.admin.token-ledger'
    ];
}
