<?php
declare(strict_types=1);

// Pure PHP: no Nextcloud bootstrap, database, real archive, user or secret.
// All passwords below are public synthetic boundary data.
$app = getenv('ARCHIVE_TOOLS_APP_PATH') ?: dirname(__DIR__) . '/app';
require_once $app . '/lib/Service/AdmissionService.php';
require_once $app . '/lib/Service/ExtractionService.php';

use OCA\ArchiveTools\Service\AdmissionService;
use OCA\ArchiveTools\Service\ExtractionService;

$checks = 0;
function passwordCheck(string $name, callable $operation): void {
    global $checks;
    $operation();
    ++$checks;
    echo 'PASS ' . $name . PHP_EOL;
}

function passwordRejected(callable $operation): void {
    try { $operation(); }
    catch (InvalidArgumentException $error) {
        if ($error->getMessage() !== 'The archive password exceeds the supported byte length.') {
            throw new RuntimeException('Password rejection must use a fixed message without input data.');
        }
        return;
    }
    throw new RuntimeException('An oversized password was accepted.');
}

passwordCheck('null password remains valid for unencrypted archives', static fn () => AdmissionService::validatePassword(null));
passwordCheck('empty password remains valid for unencrypted archives', static fn () => AdmissionService::validatePassword(''));
passwordCheck('ASCII password at 4095 bytes is accepted', static fn () => AdmissionService::validatePassword(str_repeat('x', 4095)));
passwordCheck('ASCII password at 4096 bytes is rejected safely', static fn () => passwordRejected(
    static fn () => AdmissionService::validatePassword(str_repeat('x', 4096))));
passwordCheck('two-byte UTF-8 password at 4095 bytes is accepted', static fn () => AdmissionService::validatePassword(str_repeat('я', 2047) . 'x'));
passwordCheck('two-byte UTF-8 password at 4096 bytes is rejected', static fn () => passwordRejected(
    static fn () => AdmissionService::validatePassword(str_repeat('я', 2048))));
passwordCheck('four-byte UTF-8 password at 4095 bytes is accepted', static fn () => AdmissionService::validatePassword(str_repeat('🔒', 1023) . 'abc'));
passwordCheck('four-byte UTF-8 password at 4096 bytes is rejected', static fn () => passwordRejected(
    static fn () => AdmissionService::validatePassword(str_repeat('🔒', 1023) . 'abcd')));

// Deliberately leave every dependency uninitialized. Accessing a lifecycle,
// archive reader, queue or crypto service before rejection makes these tests
// fail with Error instead of the expected fixed InvalidArgumentException.
$service = (new ReflectionClass(ExtractionService::class))->newInstanceWithoutConstructor();
passwordCheck('schedule rejects oversized ASCII before any dependency access', static fn () => passwordRejected(
    static fn () => $service->schedule('own-fixture', 1, null, 'Output', [], str_repeat('x', 4096))));
passwordCheck('schedule rejects oversized UTF-8 before reader or crypto access', static fn () => passwordRejected(
    static fn () => $service->schedule('own-fixture', 1, null, 'Output', [], str_repeat('я', 2048))));

passwordCheck('valid scheduling password reaches the normal dependency boundary', static function () use ($service): void {
    try { $service->schedule('own-fixture', 1, null, 'Output', [], str_repeat('x', 4095)); }
    catch (Error $error) {
        if (str_contains($error->getMessage(), 'must not be accessed before initialization')) { return; }
        throw $error;
    }
    throw new RuntimeException('Expected the deliberately uninitialized scheduling dependencies.');
});

echo 'All ' . $checks . ' admission password tests passed.' . PHP_EOL;
