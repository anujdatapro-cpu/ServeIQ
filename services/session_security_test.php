<?php
declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());
require __DIR__ . '/../includes/session.php';

$params = session_get_cookie_params();
$before = session_id();
regenerateSessionId();
$after = session_id();
$_SESSION['test_auth_state'] = 'present';
$cases = [
    ['session cookie is HttpOnly', $params['httponly'] === true],
    ['session cookie uses SameSite=Lax', ($params['samesite'] ?? '') === 'Lax'],
    ['local HTTP session cookie is not marked Secure', $params['secure'] === false],
    ['strict session mode is enabled', ini_get('session.use_strict_mode') === '1'],
    ['session ID changes when regenerated', $before !== $after],
    ['activity at timeout boundary is not expired', !sessionHasExpired(1000, 1800, 2800)],
    ['activity beyond timeout is expired', sessionHasExpired(1000, 1800, 2801)],
    ['missing activity timestamp is not treated as expired', !sessionHasExpired(0, 1800, 9999)],
];
destroySession();
$cases[] = ['session teardown clears data and closes active session', $_SESSION === [] && session_status() === PHP_SESSION_NONE];

$failed = 0;
foreach ($cases as $index => [$description, $passed]) {
    printf("TEST %02d %s: %s\n", $index + 1, $passed ? 'PASS' : 'FAIL', $description);
    $failed += $passed ? 0 : 1;
}
printf("SESSION SECURITY TESTS: %d/%d passed\n", count($cases) - $failed, count($cases));
exit($failed === 0 ? 0 : 1);
