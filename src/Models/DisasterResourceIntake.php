<?php
namespace App\Models;

use App\Core\Database;
use App\Support\ResourceRequestInput as Input;
use App\Support\ResourceRequestSchema;
use PDO;

/** Supplemental draft/review metadata for the existing incident/resource-request workflow. */
final class DisasterResourceIntake {
    private static function actor(int $id, array $roles): array {
        $q=Database::getInstance()->prepare('SELECT * FROM users WHERE user_id=? AND deleted_at IS NULL');
        $q->execute([$id]); $u=$q->fetch(PDO::FETCH_ASSOC);
        if (!$u || !in_array($u['user_type'],$roles,true)) throw new \DomainException('You are not authorized for this action.');
        return $u;
    }
    private static function raw(int $id): array {
        $q=Database::getInstance()->prepare('SELECT * FROM disaster_resource_intakes WHERE intake_id=?');
        $q->execute([$id]); $r=$q->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new \DomainException('Resource request not found.');
        $r['payload']=json_decode($r['payload_json'],true,32,JSON_THROW_ON_ERROR);
        $r['line_ids']=json_decode($r['line_ids_json'],true,16,JSON_THROW_ON_ERROR);
        $r['ml_result']=$r['ml_result_json']?json_decode($r['ml_result_json'],true,32,JSON_THROW_ON_ERROR):null;
        return $r;
    }
    public static function getFor(int $id, int $actor): array {
        $u=self::actor($actor,['Barangay Head','ICDRRMO']); $r=self::raw($id);
        if (($u['user_type']==='Barangay Head' && (int)$r['owner_id']!==$actor) || ($u['user_type']==='ICDRRMO' && $r['state']==='Draft')) throw new \DomainException('This request is not available to your account.');
        return $r;
    }
    public static function linkedToIncident(int $id): ?int {
        if (!ResourceRequestSchema::ready()) return null;
        $q=Database::getInstance()->prepare('SELECT intake_id FROM disaster_resource_intakes WHERE disaster_request_id=?');
        $q->execute([$id]); $n=$q->fetchColumn(); return $n===false?null:(int)$n;
    }
    public static function listing(int $actor): array {
        $u=self::actor($actor,['Barangay Head','ICDRRMO']);
        if (!ResourceRequestSchema::ready()) return [];
        $sql='SELECT * FROM disaster_resource_intakes WHERE '.($u['user_type']==='Barangay Head'?'owner_id=?':"state<>'Draft'").' ORDER BY intake_id DESC';
        $q=Database::getInstance()->prepare($sql); $q->execute($u['user_type']==='Barangay Head'?[$actor]:[]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function puroks(): array {
        return Database::getInstance()->query("SELECT p.*,b.barangay_name FROM purok p JOIN barangay b ON b.barangay_id=p.barangay_id
            WHERE p.deleted_at IS NULL AND b.deleted_at IS NULL AND LOWER(TRIM(b.barangay_name)) IN ('hinaplanon','barangay hinaplanon')
            AND LENGTH(TRIM(COALESCE(p.hazard_source_reference,'')))>0 ORDER BY p.purok_name")->fetchAll(PDO::FETCH_ASSOC);
    }
    private static function purok(?int $id): ?array {
        foreach (self::puroks() as $p) if ((int)$p['purok_id']===$id) return $p;
        return null;
    }
    private static function json(array $data): string { return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    private static function operation(string $key, int $actor, string $action, string $hash, int $id=0): ?int {
        if (!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new \DomainException('Invalid action key. Reload the form.');
        $q=Database::getInstance()->prepare('SELECT * FROM disaster_resource_history WHERE operation_key=?'); $q->execute([$key]); $h=$q->fetch(PDO::FETCH_ASSOC);
        if (!$h) return null;
        $d=json_decode($h['details_json'],true);
        if ((int)$h['actor_id']!==$actor || $h['action']!==$action || ($d['operation_hash']??null)!==$hash || ($id && (int)$h['intake_id']!==$id)) throw new \DomainException('This action key was already used for another operation. Reload the page.');
        return (int)$h['intake_id'];
    }
    private static function claim(array $r, int $revision, array $states, string $next): void {
        if (!in_array($r['state'],$states,true) || $revision!==(int)$r['revision']) throw new \DomainException('The request changed or this action is no longer allowed. Reload before continuing.');
        $q=Database::getInstance()->prepare('UPDATE disaster_resource_intakes SET state=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE intake_id=? AND revision=? AND state=?');
        $q->execute([$next,$r['intake_id'],$revision,$r['state']]);
        if ($q->rowCount()!==1) throw new \DomainException('Another officer updated this request. Reload the page.');
    }
    private static function event(int $id, array $u, string $action, string $from, string $to, string $notes, array $details=[], ?string $key=null): void {
        $q=Database::getInstance()->prepare('INSERT INTO disaster_resource_history (intake_id,actor_id,action,previous_state,new_state,notes,details_json,operation_key) VALUES (?,?,?,?,?,?,?,?)');
        $q->execute([$id,$u['user_id'],$action,$from,$to,$notes,self::json($details),$key]);
        AuditLog::log('Resource request: '.$action,'disaster_resource_intakes',$id,$from.' -> '.$to,(int)$u['user_id'],$u['firstname'].' '.$u['lastname'],$u['user_type']);
    }
    private static function transaction(callable $work) {
        $db=Database::getInstance();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite') throw new \DomainException('This resource workflow requires the verified SQLite migration.');
        $db->beginTransaction();
        try { $result=$work($db); $db->commit(); return $result; }
        catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public static function save(array $input, int $id, int $revision, string $key, bool $submit, int $actor): int {
        $u=self::actor($actor,['Barangay Head']);
        $validation=Input::validate($input,$submit);
        if ($validation['errors']) throw new \DomainException(implode(' ',array_unique($validation['errors'])));
        $data=$validation['data']; $p=self::purok($data['purok_id']);
        if ($data['purok_id']!==null && !$p) throw new \DomainException('Select a source-referenced Barangay Hinaplanon purok.');
        $action=$submit?'submit':'draft'; $hash=hash('sha256',self::json($data));
        return self::transaction(function($db) use($data,$p,$id,$revision,$key,$submit,$actor,$u,$action,$hash) {
            if ($old=self::operation($key,$actor,$action,$hash,$id)) return $old;
            $new=$id===0;
            if ($new) {
                $ref='DRR-'.(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Manila')))->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(16)));
                $q=$db->prepare("INSERT INTO disaster_resource_intakes (reference_no,owner_id,creation_key,state,payload_json) VALUES (?,?,?,'Draft',?)");
                $q->execute([$ref,$actor,$key,self::json($data)]); $id=(int)$db->lastInsertId();
            }
            $r=self::getFor($id,$actor);
            self::claim($r,$new?1:$revision,['Draft'],$submit?'Submitted':'Draft');
            $q=$db->prepare('UPDATE disaster_resource_intakes SET payload_json=? WHERE intake_id=?'); $q->execute([self::json($data),$id]);
            if ($submit) {
                $incident=DisasterRequest::create(array_merge($data,['user_id'=>$actor,'barangay_id'=>(int)$p['barangay_id'],'reference_no'=>$r['reference_no']]));
                $lines=[];
                foreach (Input::RESOURCES as $field=>$spec) if ($data[$field]>0) {
                    $lines[$field]=ResourceRequest::create(['disaster_request_id'=>$incident,'barangay_id'=>(int)$p['barangay_id'],'purok_id'=>$data['purok_id'],
                        'user_id'=>$actor,'requested_by'=>$u['firstname'].' '.$u['lastname'],'request_date'=>$data['disaster_date'],
                        'request_quantity'=>$data[$field],'remarks'=>$spec['label'].'; requested unit: '.$data[$field.'_unit'].'; intake '.$r['reference_no']]);
                }
                $q=$db->prepare("UPDATE disaster_resource_intakes SET disaster_request_id=?,line_ids_json=?,submitted_at=CURRENT_TIMESTAMP,ml_status='Pending' WHERE intake_id=?");
                $q->execute([$incident,self::json($lines),$id]);
                Notification::notifyRole('ICDRRMO','resource_request_review','Resource request awaiting review',
                    $r['reference_no'].' was submitted by Barangay Head. Open Disaster Request Review to approve or reject.',$data['is_urgent']?'Urgent':'Normal','disaster_request_form',$incident);
            }
            self::event($id,$u,$action,$new?'None':'Draft',$submit?'Submitted':'Draft',$submit?'Submitted for independent ICDRRMO review.':'Private draft saved.',
                ['operation_hash'=>$hash,'payload_sha256'=>$hash],$key);
            return $id;
        });
    }

    public static function history(int $id, int $actor): array {
        self::getFor($id,$actor);
        $q=Database::getInstance()->prepare('SELECT h.*,u.firstname,u.lastname,u.user_type FROM disaster_resource_history h JOIN users u ON u.user_id=h.actor_id WHERE intake_id=? ORDER BY history_id');
        $q->execute([$id]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function allocations(int $id, int $actor): array {
        $out=[];
        foreach (self::history($id,$actor) as $h) if ($h['action']==='allocate') {
            $d=json_decode($h['details_json'],true); $out[]=array_merge($d,['actor'=>$h['firstname'].' '.$h['lastname'],'at'=>$h['created_at']]);
        }
        return $out;
    }

    public static function review(int $id,int $revision,string $key,string $decision,string $notes,?string $priority,int $actor): void {
        $u=self::actor($actor,['ICDRRMO']);
        if (!in_array($decision,['approve','reject'],true) || strlen(trim($notes))<5 || strlen($notes)>2000 || ($decision==='approve' && !in_array($priority,['Urgent','High','Medium','Low'],true))) throw new \DomainException('Choose a valid decision, human-reviewed priority and written justification (5–2000 characters).');
        $hash=hash('sha256',self::json([$decision,$notes,$priority]));
        self::transaction(function($db) use($id,$revision,$key,$decision,$notes,$priority,$actor,$u,$hash) {
            if (self::operation($key,$actor,'review',$hash,$id)) return;
            $r=self::getFor($id,$actor);
            if ((int)$r['owner_id']===$actor) throw new \DomainException('A submitter cannot approve their own request.');
            $next=$decision==='approve'?'Approved':'Rejected'; self::claim($r,$revision,['Submitted'],$next);
            $q=$db->prepare('UPDATE disaster_resource_intakes SET approved_priority=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,review_notes=? WHERE intake_id=?');
            $q->execute([$decision==='approve'?$priority:null,$actor,$notes,$id]);
            foreach ($r['line_ids'] as $line) ResourceRequest::updateStatus((int)$line,$next);
            DisasterRequest::updateStatus((int)$r['disaster_request_id'],$next,$actor,$notes);
            self::event($id,$u,'review',$r['state'],$next,$notes,['operation_hash'=>$hash,'human_priority'=>$priority],$key);
        });
    }

    public static function allocate(int $id,int $revision,string $key,string $field,int $supplyId,int $quantity,string $notes,int $actor): void {
        $u=self::actor($actor,['Barangay Head','ICDRRMO']);
        if (!isset(Input::RESOURCES[$field]) || $quantity<=0 || $quantity>100000000 || strlen(trim($notes))<5 || strlen($notes)>2000) throw new \DomainException('Select a valid requested resource, positive quantity and written allocation reason.');
        $hash=hash('sha256',self::json([$field,$supplyId,$quantity,$notes]));
        self::transaction(function($db) use($id,$revision,$key,$field,$supplyId,$quantity,$notes,$actor,$u,$hash) {
            if (self::operation($key,$actor,'allocate',$hash,$id)) return;
            $r=self::getFor($id,$actor); self::claim($r,$revision,['Approved','Partially Allocated'],'Partially Allocated');
            $line=(int)($r['line_ids'][$field]??0); if (!$line) throw new \DomainException('This resource was not requested.');
            $allocated=[]; foreach (self::allocations($id,$actor) as $a) $allocated[$a['field']]=($allocated[$a['field']]??0)+(int)$a['quantity'];
            $s=Supply::findById($supplyId); $p=self::purok($r['payload']['purok_id']);
            if (!$s || !$p || (int)$s['barangay_id']!==(int)$p['barangay_id'] || $s['supply_type']!==Input::RESOURCES[$field]['type']
                || Input::unit($s['unit_of_measure'])!==Input::unit($r['payload'][$field.'_unit'])) throw new \DomainException('Inventory category and unit must match the requested resource. No automatic unit conversion is allowed.');
            $remaining=(int)$r['payload'][$field]-($allocated[$field]??0);
            if ($quantity>$remaining) throw new \DomainException('Allocation exceeds the outstanding requested quantity.');
            // The revision UPDATE above holds SQLite's write lock before stock is read/deducted.
            Supply::deductStock($supplyId,$quantity);
            $allocated[$field]=($allocated[$field]??0)+$quantity;
            $q=$db->prepare('UPDATE resource_request SET supply_id=?,request_status=?,updated_at=CURRENT_TIMESTAMP WHERE request_id=? AND disaster_request_id=?');
            $q->execute([$supplyId,$allocated[$field]>=(int)$r['payload'][$field]?'Fulfilled':'Approved',$line,$r['disaster_request_id']]);
            $all=true; foreach ($r['line_ids'] as $f=>$unused) if (($allocated[$f]??0)<(int)$r['payload'][$f]) $all=false;
            $next=$all?'Allocated':'Partially Allocated';
            $q=$db->prepare('UPDATE disaster_resource_intakes SET state=? WHERE intake_id=?'); $q->execute([$next,$id]);
            RequestLog::create(['request_id'=>$line,'recommendation_id'=>null,'recommended_by'=>'Human authorization after ICDRRMO review','action_taken'=>'Resource Allocated',
                'action_by'=>$u['firstname'].' '.$u['lastname'],'remarks'=>$r['reference_no'].': '.$quantity.' '.$s['unit_of_measure'].' of '.$s['supply_name'].'. '.$notes]);
            self::event($id,$u,'allocate',$r['state'],$next,$notes,['operation_hash'=>$hash,'field'=>$field,'resource_request_id'=>$line,'supply_id'=>$supplyId,
                'supply_name'=>$s['supply_name'],'quantity'=>$quantity,'unit'=>$s['unit_of_measure']],$key);
            IncidentHistory::record((int)$r['disaster_request_id'],$actor,$r['state'],$next,'Authorized resource allocation recorded; not proof of distribution or claim.');
            Notification::notifyUser((int)$r['owner_id'],'request_status_update','Resource allocation updated',$r['reference_no'].': '.$next,'Normal','disaster_request_form',(int)$r['disaster_request_id']);
        });
    }

    public static function complete(int $id,int $revision,string $key,string $notes,int $actor): void {
        $u=self::actor($actor,['Barangay Head','ICDRRMO']);
        if (strlen(trim($notes))<5 || strlen($notes)>2000) throw new \DomainException('Provide completion notes (5–2000 characters).');
        $hash=hash('sha256',$notes);
        self::transaction(function($db) use($id,$revision,$key,$notes,$actor,$u,$hash) {
            if (self::operation($key,$actor,'complete',$hash,$id)) return;
            $r=self::getFor($id,$actor); self::claim($r,$revision,$r['line_ids']?['Allocated']:['Approved'],'Completed');
            DisasterRequest::updateStatus((int)$r['disaster_request_id'],'Completed',$actor,$notes);
            self::event($id,$u,'complete',$r['state'],'Completed',$notes,['operation_hash'=>$hash],$key);
        });
    }

    public static function classify(int $id,int $actor,bool $retry=false): void {
        $u=self::actor($actor,['Barangay Head','ICDRRMO']); $r=self::getFor($id,$actor);
        if ($r['state']==='Draft' || $r['state']==='Rejected' || $r['state']==='Completed') throw new \DomainException('This request is not eligible for assessment.');
        if (!$retry && $r['ml_status']!=='Pending') return;
        if ($retry) {
            $q=Database::getInstance()->prepare("UPDATE disaster_resource_intakes SET ml_status='Pending' WHERE intake_id=? AND ml_status='Unavailable'");
            $q->execute([$id]); if ($q->rowCount()!==1) return;
        }
        $p=self::purok($r['payload']['purok_id']); if (!$p) throw new \DomainException('Purok source is no longer available.');
        $features=array_intersect_key($r['payload'],array_flip(['disaster_type','severity_level','is_urgent','affected_households','affected_population']));
        $features['purok_risk_level']=$p['risk_classification'];
        $result=\App\ML\DecisionTreeApiClient::predict($features);
        self::transaction(function($db) use($id,$features,$result,$u) {
            $current=self::raw($id);
            $q=$db->prepare("UPDATE disaster_resource_intakes SET ml_status=?,ml_result_json=?,ml_attempted_at=CURRENT_TIMESTAMP WHERE intake_id=? AND ml_status='Pending' AND state NOT IN ('Draft','Rejected','Completed')");
            $q->execute([$result?'Available':'Unavailable',$result?self::json(['features'=>$features,'prediction'=>$result]):null,$id]);
            if ($q->rowCount()) self::event($id,$u,'assessment',$current['state'],$current['state'],$result?'Experimental Decision Tree assessment stored; not an approval.':'Decision Tree API unavailable or invalid. Request preserved without prediction.');
        });
    }
}
