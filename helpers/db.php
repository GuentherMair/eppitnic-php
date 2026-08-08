<?php

use RedBeanPHP\R;

require_once dirname(__FILE__) . '/config.php';

// RedBeanPHP's global (non-namespaced) `R` facade alias isn't pulled in by
// composer's autoload for this package -- define it once here so every bare
// `R::...` call site (helpers/changelog.php, routes/*.php) resolves.
if ( ! class_exists('R', false)) {
    class_alias(R::class, 'R');
}

if ( ! R::hasDatabase('default')) {
    $db = getConfig('db');
    R::setup("{$db['type']}:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['password']);
    R::freeze(true);
    R::getWriter()->setUseCache(true);
}
