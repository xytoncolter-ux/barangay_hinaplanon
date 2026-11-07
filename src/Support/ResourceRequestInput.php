<?php
namespace App\Support;

final class ResourceRequestInput {
    public const TYPES=['Flood','Flash Flood','Typhoon','Landslide','Fire','Storm Surge','Earthquake','Other'];
    public const SEVERITIES=['Critical','High','Medium','Low'];
    public const RESOURCES=[
        'food_packs'=>['label'=>'Food packs','type'=>'Relief Goods','units'=>['packs','food packs']],
        'drinking_water'=>['label'=>'Drinking water','type'=>'Clean Water','units'=>['jugs','cases','bottles','litres']],
        'hygiene_kits'=>['label'=>'Hygiene kits','type'=>'Hygiene Kits','units'=>['packs','kits']],
        'medical_kits'=>['label'=>'Medical kits','type'=>'Medical Supplies','units'=>['kits']],
    ];

    public static function validate(array $input, bool $submit): array {
        $data=[]; $errors=[];
        foreach (['weather_name'=>120,'description'=>5000,'additional_needs'=>2000,'disaster_date'=>10,'disaster_type'=>40,'severity_level'=>20] as $key=>$max) {
            $v=$input[$key]??'';
            if (!is_string($v) || strlen($v)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) { $errors[$key]='Invalid or overly long text.'; $v=''; }
            $data[$key]=trim($v);
        }
        foreach (['purok_id','affected_households','affected_population','children','seniors','pwds','pregnant_women'] as $key) {
            $v=$input[$key]??'';
            if ($v==='' || $v===null) { $data[$key]=null; continue; }
            $n=is_scalar($v)?filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>$key==='purok_id'?1:0,'max_range'=>100000000]]):false;
            if ($n===false) $errors[$key]='Enter a whole, non-negative number.';
            $data[$key]=$n===false?null:$n;
        }
        $urgent=$input['is_urgent']??'';
        if ($urgent!=='' && $urgent!==null && !in_array($urgent,['0','1',0,1],true)) $errors['is_urgent']='Select normal or urgent.';
        $data['is_urgent']=in_array($urgent,['0','1',0,1],true)?(int)$urgent:null;
        if ($submit && $data['is_urgent']===null) $errors['is_urgent']='Select normal or urgent.';
        foreach (['latitude'=>90,'longitude'=>180] as $key=>$limit) {
            $v=$input[$key]??''; $data[$key]=null;
            if ($v!=='' && $v!==null) {
                if (!is_scalar($v) || !is_numeric($v) || !is_finite((float)$v) || abs((float)$v)>$limit) $errors[$key]='Invalid coordinate.';
                else $data[$key]=(float)$v;
            }
        }
        if (($data['latitude']===null)!==($data['longitude']===null)) $errors['latitude']='Supply both coordinates or leave both blank.';
        if ($data['disaster_type']!=='' && !in_array($data['disaster_type'],self::TYPES,true)) $errors['disaster_type']='Select a listed disaster type.';
        if ($data['severity_level']!=='' && !in_array($data['severity_level'],self::SEVERITIES,true)) $errors['severity_level']='Select a listed severity.';
        if ($data['disaster_date']!=='') {
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$data['disaster_date'],new \DateTimeZone('Asia/Manila'));
            if (!$d || $d->format('Y-m-d')!==$data['disaster_date'] || (int)$d->format('Y')<1 || ($submit && $d>new \DateTimeImmutable('today',new \DateTimeZone('Asia/Manila')))) $errors['disaster_date']='Use a valid incident date, not a future date.';
        }
        if ($submit) foreach (['purok_id','affected_households','affected_population','weather_name','description','disaster_date','disaster_type','severity_level'] as $key) {
            if ($data[$key]===null || $data[$key]==='') $errors[$key]='This field is required for submission.';
        }
        if ($data['affected_population']!==null) {
            foreach (['children','seniors','pwds','pregnant_women','affected_households'] as $key) {
                if ($data[$key]!==null && $data[$key]>$data['affected_population']) $errors[$key]='Cannot exceed the recorded number of individuals.';
            }
            if ($data['children']!==null && $data['seniors']!==null && $data['children']+$data['seniors']>$data['affected_population']) $errors['children']='Children and seniors together cannot exceed total individuals.';
        }
        foreach (self::RESOURCES as $key=>$spec) {
            $v=$input[$key]??'';
            $n=$v==='' || $v===null?null:(is_scalar($v)?filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000000]]):false);
            if ($n===false) { $errors[$key]='Enter a whole, non-negative quantity.'; $n=null; }
            $data[$key]=$n;
            $unit=$input[$key.'_unit']??'';
            $data[$key.'_unit']=is_string($unit)?trim($unit):'';
            if ($data[$key.'_unit']!=='' && !in_array($data[$key.'_unit'],$spec['units'],true)) $errors[$key.'_unit']='Select a supported unit.';
            if ($submit && $n>0 && $data[$key.'_unit']==='') $errors[$key.'_unit']='Choose the requested unit; no conversion is assumed.';
        }
        if ($submit && !array_filter(array_intersect_key($data,self::RESOURCES),fn($v)=>$v>0) && $data['additional_needs']==='') $errors['additional_needs']='Request at least one resource or describe additional needs.';
        return ['data'=>$data,'errors'=>$errors];
    }

    public static function unit(string $unit): string { $unit=strtolower(trim($unit)); return $unit==='food packs'?'packs':$unit; }
}
