<?php

declare(strict_types=1);

require_once __DIR__ . '/JasBinaryCompiler.php';
require_once __DIR__ . '/JasNativeCompiler.php';

use Jah\JasBinaryCompiler;
use Jah\JasNativeCompiler;

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$binary = JasBinaryCompiler::compileExit(42);
$expect(JasBinaryCompiler::validate($binary), 'JAS bytecode checksum and header');
$expect(JasBinaryCompiler::execute($binary) === 42, 'JAS bytecode execution');
$expect(!JasBinaryCompiler::validate($binary . 'corruption'), 'corrupted bytecode rejection');

$validSource = "<?php\necho 'hello';\n";
$invalidSource = "<?php\necho ; broken syntax\n";
$expect(JasNativeCompiler::validate($validSource), 'valid PHP parser check');
$expect(!JasNativeCompiler::validate($invalidSource), 'invalid PHP parser rejection');

$output = tempnam(sys_get_temp_dir(), 'jah-native-test-');
if ($output === false) {
    $failures[] = 'temporary compilation target';
} else {
    @unlink($output);
    $expect(JasNativeCompiler::compile($validSource, $output), 'atomic PHP artifact compilation');
    $expect(is_file($output) && file_get_contents($output) === $validSource, 'compiled artifact contents');
    @unlink($output);
}

echo 'SUMMARY ' . (7 - count($failures)) . '/7' . PHP_EOL;
exit($failures === [] ? 0 : 1);
