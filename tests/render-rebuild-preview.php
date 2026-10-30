<?php
require __DIR__ . '/bootstrap.php';
function checked($a, $b, $echo = true) { return $a == $b ? 'checked="checked"' : ''; }
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rebuild controls test</title><style>body{font:16px/1.5 Arial;margin:20px;color:#151515;background:white}.button{display:inline-block;padding:8px}';
echo file_get_contents(dirname(__DIR__) . '/assets/admin-rebuild.css');
echo '</style></head><body><main><h1>uams search</h1>';
\UAMS_Search\Admin::rebuild_controls();
echo '</main><script>window.UAMSRebuild={url:"https://uams-search.test/admin-ajax.php",nonce:"test",job:null};</script><script>';
echo file_get_contents(dirname(__DIR__) . '/assets/admin-rebuild.js');
echo '</script></body></html>';
