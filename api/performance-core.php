<?php
declare(strict_types=1);

function sf_performance_metric_limits(): array {
    return [
      'LCP'=>['max'=>120000.0,'good'=>2500.0,'poor'=>4000.0,'unit'=>'ms'],
      'CLS'=>['max'=>10.0,'good'=>0.1,'poor'=>0.25,'unit'=>'score'],
      'INP'=>['max'=>120000.0,'good'=>200.0,'poor'=>500.0,'unit'=>'ms'],
      'FCP'=>['max'=>120000.0,'good'=>1800.0,'poor'=>3000.0,'unit'=>'ms'],
      'TTFB'=>['max'=>120000.0,'good'=>800.0,'poor'=>1800.0,'unit'=>'ms'],
    ];
}
function sf_performance_rating(string $metric,float $value): string {
    $l=sf_performance_metric_limits()[$metric]??null;if(!$l)return 'unknown';
    return $value<=$l['good']?'good':($value<=$l['poor']?'needs_improvement':'poor');
}
function sf_performance_normalize_event(array $raw): array {
    $metric=strtoupper(sf_clean_text($raw['metric']??'',8));$limits=sf_performance_metric_limits();if(!isset($limits[$metric]))throw new InvalidArgumentException('Unsupported performance metric.');
    $value=(float)($raw['value']??-1);if(!is_finite($value)||$value<0||$value>$limits[$metric]['max'])throw new InvalidArgumentException('Performance value is out of range.');
    $routes=['home','track','release','show','store','story','video','photo','search','music','releases','shows','videos','photos','about','contact','archive','campaign','press'];
    $route=sf_clean_text($raw['route_type']??'home',40);if(!in_array($route,$routes,true))$route='other';
    $nav=sf_clean_text($raw['navigation_type']??'',30);if(!in_array($nav,['navigate','reload','back_forward','prerender'],true))$nav='';
    return ['metric'=>$metric,'value'=>round($value,$metric==='CLS'?4:1),'rating'=>sf_performance_rating($metric,$value),'unit'=>$limits[$metric]['unit'],'route_type'=>$route,'navigation_type'=>$nav];
}
function sf_performance_record(array $raw): array {
    $e=sf_performance_normalize_event($raw);
    sf_log_site_event('info','web_vital','Public Core Web Vital',[
      'metric'=>$e['metric'],'value'=>$e['value'],'rating'=>$e['rating'],'unit'=>$e['unit'],'route_type'=>$e['route_type'],'navigation_type'=>$e['navigation_type'],
    ]);
    return $e;
}
function sf_performance_percentile(array $values,float $p=.75): float {
    $values=array_values(array_filter(array_map('floatval',$values),fn($v)=>is_finite($v)&&$v>=0));if(!$values)return 0.0;sort($values,SORT_NUMERIC);$i=max(0,min(count($values)-1,(int)ceil(count($values)*$p)-1));return (float)$values[$i];
}
function sf_performance_summary(int $days=30): array {
    sf_ops_ensure_schema();$days=max(1,min(365,$days));$since=gmdate('c',time()-$days*86400);$q=sf_db()->prepare("SELECT details_json,created_at FROM site_event_log WHERE event_type='web_vital' AND created_at>=? ORDER BY id DESC LIMIT 10000");$q->execute([$since]);
    $by=[];$routes=[];foreach($q->fetchAll() as $row){$d=json_decode((string)$row['details_json'],true);if(!is_array($d))continue;$metric=(string)($d['metric']??'');if(!isset(sf_performance_metric_limits()[$metric]))continue;$value=(float)($d['value']??-1);if($value<0)continue;$rating=(string)($d['rating']??sf_performance_rating($metric,$value));$by[$metric]['values'][]=$value;$by[$metric]['ratings'][$rating]=($by[$metric]['ratings'][$rating]??0)+1;$route=(string)($d['route_type']??'home');$routes[$route]=($routes[$route]??0)+1;}
    $metrics=[];foreach(sf_performance_metric_limits() as $metric=>$limits){$values=$by[$metric]['values']??[];$p75=sf_performance_percentile($values,.75);$metrics[$metric]=['count'=>count($values),'p75'=>$p75,'rating'=>count($values)?sf_performance_rating($metric,$p75):'unknown','unit'=>$limits['unit'],'ratings'=>$by[$metric]['ratings']??[]];}
    arsort($routes);$routeRows=[];foreach($routes as $route=>$count)$routeRows[]=['route_type'=>$route,'count'=>$count];
    return ['days'=>$days,'since'=>$since,'metrics'=>$metrics,'routes'=>array_slice($routeRows,0,20),'sample_count'=>array_sum(array_map(fn($x)=>(int)$x['count'],$metrics))];
}
