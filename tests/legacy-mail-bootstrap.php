<?php
// Use the existing local dependency tree, but resolve all application code from this worktree.
$vendor = getenv('LEGACY_MAIL_VENDOR') ?: dirname(__DIR__) . '/vendor/autoload.php';
$loader = require $vendor;
$loader->setPsr4('App\\', dirname(__DIR__) . '/app');
$loader->setPsr4('Tests\\', __DIR__);
$map = [];
$root = dirname(__DIR__) . '/app/';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') $map['App\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4))] = $file->getPathname();
}
$loader->addClassMap($map);
