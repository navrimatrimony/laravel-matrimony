<?php

header('Content-Type: text/plain');

$tmpDir = sys_get_temp_dir();
$tmpFile = $tmpDir.DIRECTORY_SEPARATOR.'gd_write_test_'.uniqid().'.png';

echo 'sys_get_temp_dir: '.$tmpDir.PHP_EOL;
echo 'is_writable(tmp_dir): '.(is_writable($tmpDir) ? 'yes' : 'no').PHP_EOL;
echo 'extension_loaded(gd): '.(extension_loaded('gd') ? 'yes' : 'no').PHP_EOL;
echo 'function_exists(imagecreatetruecolor): '.(function_exists('imagecreatetruecolor') ? 'yes' : 'no').PHP_EOL;
echo 'function_exists(imagepng): '.(function_exists('imagepng') ? 'yes' : 'no').PHP_EOL;

$img = imagecreatetruecolor(2, 2);
$white = imagecolorallocate($img, 255, 255, 255);
imagefill($img, 0, 0, $white);

$wrote = @imagepng($img, $tmpFile);
imagedestroy($img);

echo 'imagepng_write_ok: '.($wrote ? 'yes' : 'no').PHP_EOL;
echo 'file_created: '.(is_file($tmpFile) ? 'yes' : 'no').PHP_EOL;
echo 'filesize: '.(is_file($tmpFile) ? filesize($tmpFile) : '0').PHP_EOL;

if (is_file($tmpFile)) {
    @unlink($tmpFile);
}
