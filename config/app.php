<?php
/**
 * App-wide config. Right now this is just the environment flag, which
 * gates dev-only tools like the test account creator in register.php.
 *
 * IMPORTANT: flip this to 'production' before this system goes anywhere
 * near real students/staff/data.
 */
define('APP_ENV', 'development');

/**
 * BASE_URL is the URL prefix needed to reach the public/ folder as the
 * browser sees it. Every redirect, link, stylesheet, and image path in this
 * app is built from this constant instead of a hardcoded literal, so moving
 * between environments is just this one line.
 *
 * - development (XAMPP): the project lives at C:\xampp\htdocs\enrollment-system,
 *   so the site is reached through /enrollment-system/public/...
 * - production: the hosting document root should point straight at public/
 *   (see CLAUDE.md's deployment notes), so public/ effectively *is* the web
 *   root and needs no prefix at all.
 *
 * If a host can't be pointed at public/ directly and the whole project
 * folder ends up under the web root instead, set this to that folder's
 * name instead of '' below (e.g. '/enrollment-system').
 */
define('BASE_URL', APP_ENV === 'development' ? '/enrollment-system/public' : '/public');

/**
 * SITE_URL is the full, absolute origin (scheme + host), needed anywhere a
 * link or image has to resolve outside the browser that's currently on this
 * site, emails being the main case, a recipient's inbox has no notion of
 * "relative to this page". BASE_URL alone is not enough there.
 *
 * Update the production value to the real domain once deployed (Hostinger
 * or InfinityFree, whichever branch is live).
 */
define('SITE_URL', APP_ENV === 'development'
    ? 'http://localhost/enrollment-system/public'
    : 'https://enrollment4c.infinityfree.io/public');