<?php
declare(strict_types=1);

// Offline validation against a pinned official App Store schema.
$app = getenv('ARCHIVE_TOOLS_APP_PATH') ?: '/var/www/html/custom_apps/archive_tools';
$schema = __DIR__ . '/appstore-info.xsd';
$provenance = json_decode((string)file_get_contents(__DIR__ . '/appstore-schema.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Metadata validation failed: ' . $label);
    }
    $checks++;
    echo 'PASS ' . $label . PHP_EOL;
};
$check(hash_file('sha256', $schema) === $provenance['sha256'], 'pinned official schema fingerprint');
libxml_use_internal_errors(true);
$document = new DOMDocument();
$loaded = $document->load($app . '/appinfo/info.xml', LIBXML_NONET);
$valid = $loaded && $document->doctype === null && $document->schemaValidate($schema, LIBXML_NONET);
if (!$valid) {
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, trim($error->message) . PHP_EOL);
    }
}
$check($valid, 'App Store XML schema');
$xpath = new DOMXPath($document);
$value = static fn (string $path): string => $xpath->evaluate('string(' . $path . ')');
$check($value('/info/id') === 'archive_tools'
    && $value('/info/namespace') === 'ArchiveTools'
    && $value('/info/licence') === 'AGPL-3.0-or-later'
    && trim($value('/info/author')) !== '', 'public identity and SPDX licence');
foreach (['bugs', 'repository', 'website'] as $field) {
    $url = $value('/info/' . $field);
    $check(filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https', $field . ' HTTPS URL');
}
echo 'Metadata validation: ' . $checks . ' checks passed.' . PHP_EOL;
