<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit('CLI only'); }
spl_autoload_register(static function($class) {
    if (str_starts_with($class,'App\\')) {
        $file=__DIR__.'/../../src/'.str_replace('\\','/',substr($class,4)).'.php';
        if (is_file($file)) require_once $file;
    }
});
App\Support\ResourceRequestSchema::migrate();
echo "Resource-request extension tables ready; existing records were not rewritten.\n";
