<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Models\DisasterResourceIntake as Intake;
use App\Models\Supply;
use App\Support\ResourceRequestInput as Input;
use App\Support\ResourceRequestSchema;

final class DisasterResourceController {
    private function allow(array $roles): bool {
        Auth::requireRole($roles);
        return Auth::hasRole($roles);
    }
    private function csrf(): bool {
        $token=Request::post('csrf_token');
        if (!Request::isPost() || !is_string($token) || !Auth::validateCsrf($token)) {
            http_response_code(403); echo 'Security verification failed. Reload the form and try again.'; return false;
        }
        return true;
    }
    private function integer($value,int $min=0): int {
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>PHP_INT_MAX]]);
        if ($n===false) throw new \DomainException('Invalid request identifier or revision.');
        return $n;
    }
    private function text($value): string { return is_string($value)?trim($value):''; }

    public function form(): void {
        if (!$this->allow(['Barangay Head'])) return;
        $ready=ResourceRequestSchema::ready(); $values=[]; $errors=[]; $intake=null;
        if (!$ready) http_response_code(503);
        try {
            $id=$this->integer(Request::isPost()?Request::post('intake_id',0):Request::get('id',0));
            if ($id && $ready) {
                $intake=Intake::getFor($id,(int)Auth::id());
                if ($intake['state']!=='Draft') { Auth::redirect('index.php?route=disaster-requests-resource-show&id='.$id); return; }
                $values=$intake['payload'];
            }
            if (Request::isPost()) {
                if (!$this->csrf()) return;
                if (!$ready) throw new \DomainException('Resource request storage has not been migrated yet.');
                $intent=$this->text(Request::post('intent'));
                if (!in_array($intent,['draft','submit'],true)) throw new \DomainException('Choose Save Draft or Submit Request.');
                $validation=Input::validate($_POST,$intent==='submit'); $values=$validation['data']; $errors=$validation['errors'];
                if ($values['purok_id']!==null && !in_array($values['purok_id'],array_map('intval',array_column(Intake::puroks(),'purok_id')),true)) $errors['purok_id']='Choose a source-referenced Barangay Hinaplanon purok.';
                if (!$errors) {
                    $saved=Intake::save($values,$id,$this->integer(Request::post('revision',0)),$this->text(Request::post('operation_key')),$intent==='submit',(int)Auth::id());
                    if ($intent==='submit') {
                        try { Intake::classify($saved,(int)Auth::id()); }
                        catch (\Throwable $e) { error_log('Saved resource request assessment could not complete.'); }
                    }
                    Auth::setFlash('success',$intent==='submit'?'Request submitted to ICDRRMO for review. AI output does not approve or allocate resources.':'Private draft saved. It has not been sent to ICDRRMO.');
                    Auth::redirect('index.php?route=disaster-requests-resource-show&id='.$saved); return;
                }
                http_response_code(422);
            }
        } catch (\DomainException $e) { $errors['form']=$e->getMessage(); http_response_code(422); }
        catch (\Throwable $e) { error_log('Resource request save error: '.$e->getMessage()); $errors['form']='The request could not be saved. Reload and try again; do not assume it was submitted.'; http_response_code(500); }
        View::render('disaster_requests.create',['title'=>'Disaster Resource Request Form','puroks'=>Intake::puroks(),
            'values'=>$values,'errors'=>$errors,'intake'=>$intake,'storageReady'=>$ready,'operationKey'=>bin2hex(random_bytes(16))]);
    }

    public function show(): void {
        if (!$this->allow(['Barangay Head','ICDRRMO'])) return;
        if (!ResourceRequestSchema::ready()) { http_response_code(503); echo 'Resource request storage is not installed yet.'; return; }
        try {
            $id=$this->integer(Request::get('id'),1); $r=Intake::getFor($id,(int)Auth::id());
            $purok=null; foreach (Intake::puroks() as $p) if ((int)$p['purok_id']===$r['payload']['purok_id']) $purok=$p;
            $supplies=array_values(array_filter(Supply::all(),fn($s)=>$purok && (int)$s['barangay_id']===(int)$purok['barangay_id']));
            View::render('disaster_requests.resource_show',['title'=>'Resource Request '.$r['reference_no'],'intake'=>$r,'purok'=>$purok,
                'allocations'=>Intake::allocations($id,(int)Auth::id()),'history'=>Intake::history($id,(int)Auth::id()),'supplies'=>$supplies]);
        } catch (\DomainException $e) { Auth::denyAccess($e->getMessage()); return; }
    }

    private function action(string $action,array $roles): void {
        if (!$this->allow($roles) || !$this->csrf()) return;
        $id=0;
        try {
            $id=$this->integer(Request::post('intake_id'),1);
            $rev=$this->integer(Request::post('revision'),1);
            $key=$this->text(Request::post('operation_key')); $notes=$this->text(Request::post('notes'));
            $actor=(int)Auth::id();
            if ($action==='review') Intake::review($id,$rev,$key,$this->text(Request::post('decision')),$notes,$this->text(Request::post('approved_priority'))?:null,$actor);
            elseif ($action==='allocate') Intake::allocate($id,$rev,$key,$this->text(Request::post('resource_field')),$this->integer(Request::post('supply_id'),1),$this->integer(Request::post('quantity'),1),$notes,$actor);
            elseif ($action==='complete') Intake::complete($id,$rev,$key,$notes,$actor);
            else Intake::classify($id,$actor,true);
            Auth::setFlash('success',$action==='retry'?'Assessment attempt finished. Check the recorded AI status below.':'Resource request action recorded.');
        } catch (\DomainException $e) { Auth::setFlash('danger',$e->getMessage()); }
        catch (\Throwable $e) { error_log('Resource request action failed: '.$e->getMessage()); Auth::setFlash('danger','The operation failed and its database transaction was rolled back. Reload before retrying.'); }
        Auth::redirect($id?'index.php?route=disaster-requests-resource-show&id='.$id:'index.php?route=disaster-requests');
    }
    public function review(): void { $this->action('review',['ICDRRMO']); }
    public function allocate(): void { $this->action('allocate',['Barangay Head','ICDRRMO']); }
    public function complete(): void { $this->action('complete',['Barangay Head','ICDRRMO']); }
    public function retry(): void { $this->action('retry',['Barangay Head','ICDRRMO']); }
}
