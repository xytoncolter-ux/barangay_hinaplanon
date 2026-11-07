<?php
namespace App\ML;

/** Priority classification only. Failure returns null, never an invented fallback score. */
final class DecisionTreeApiClient {
    public static function predict(array $features): ?array {
        if (getenv('HINAPLANON_DECISION_TREE_API_ENABLED')==='0') return null;
        try {
            foreach (['disaster_type','severity_level','purok_risk_level'] as $key) {
                if (!isset($features[$key]) || !is_string($features[$key]) || $features[$key]==='' || strlen($features[$key])>80) return null;
            }
            foreach (['affected_households','affected_population','is_urgent'] as $key) {
                if (!isset($features[$key]) || !is_int($features[$key]) || $features[$key]<0 || $features[$key]>100000000) return null;
            }
            if ($features['is_urgent']>1 || !function_exists('curl_init')) return null;
            $url=rtrim(getenv('HINAPLANON_ML_API_BASE_URL')?:'http://127.0.0.1:8001','/');
            $parts=parse_url($url);
            if (!$parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || !in_array($parts['scheme']??'', ['http','https'],true)
                || ($parts['scheme']==='http' && !in_array($parts['host'],['127.0.0.1','localhost','[::1]'],true))) return null;
            $headers=['Content-Type: application/json','Accept: application/json'];
            $token=getenv('HINAPLANON_ML_API_TOKEN')?:'';
            if (preg_match('/[\r\n]/',$token)) return null;
            if ($token!=='') $headers[]='Authorization: Bearer '.$token;
            $body=''; $curl=curl_init($url.'/predict-decision-tree');
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($features,JSON_THROW_ON_ERROR),
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',
                CURLOPT_WRITEFUNCTION=>static function($h,$chunk) use(&$body) { $body.=$chunk; return strlen($body)>65536?0:strlen($chunk); }]);
            $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
            if ($ok===false || $status!==200) { error_log('Decision Tree API unavailable or returned HTTP '.$status); return null; }
            $r=json_decode($body,true,32,JSON_THROW_ON_ERROR); $classes=['Urgent','High','Medium','Low'];
            if (!is_array($r) || ($r['model_source']??null)!=='native_decision_tree_api' || ($r['exploratory']??null)!==true
                || ($r['algorithm']??null)!=='DecisionTreeClassifier (CART/Gini)' || !in_array($r['predicted_priority']??null,$classes,true)
                || !is_string($r['model_sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$r['model_sha256'])
                || !is_numeric($r['confidence']??null) || !is_finite((float)$r['confidence']) || $r['confidence']<0 || $r['confidence']>100
                || !is_array($r['probabilities']??null) || !is_array($r['decision_path']??null) || !is_array($r['feature_warnings']??null)) return null;
            $sum=0;
            foreach ($r['probabilities'] as $label=>$prob) {
                if (!in_array($label,$classes,true) || !is_numeric($prob) || !is_finite((float)$prob) || $prob<0 || $prob>1) return null;
                $sum+=(float)$prob;
            }
            $p=$r['probabilities'][$r['predicted_priority']]??null;
            if ($p===null || abs($sum-1)>0.001 || abs($p*100-$r['confidence'])>0.02 || $p+0.00001<max($r['probabilities'])) return null;
            foreach (array_merge($r['decision_path'],$r['feature_warnings']) as $text) if (!is_string($text) || strlen($text)>2000) return null;
            if (isset($r['artifact_saved_at']) && (!is_string($r['artifact_saved_at']) || strlen($r['artifact_saved_at'])>80)) return null;
            return $r;
        } catch (\Throwable $e) { error_log('Decision Tree API response rejected.'); return null; }
    }
}
