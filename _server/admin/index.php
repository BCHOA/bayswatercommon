<?php
/**
 * Bayswater Common HOA website admin (https://admin.bayswatercommon.org/).
 *
 * Editors manage announcements and their own password.
 * Admins can also manage users and reset anyone's password.
 * The public website reads active announcements from api/announcements.php.
 */
declare(strict_types=1);

const BCHOA = true;
require __DIR__ . '/inc/lib.php';
require __DIR__ . '/inc/view.php';
require __DIR__ . '/inc/minutes.php';
require __DIR__ . '/inc/settings.php';
require __DIR__ . '/inc/mailer.php';
require __DIR__ . '/inc/gcal.php';
require __DIR__ . '/inc/inbound.php';
require __DIR__ . '/inc/settings-page.php';

$p      = (string)($_GET['p'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost && !csrf_ok()) {
    // An upload bigger than post_max_size makes PHP drop the whole form, token included.
    $tooBig = !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    flash('error', $tooBig
        ? 'That file is larger than ' . human_size(upload_limit()) . '. Put it on Google Drive or similar and add it as a link instead.'
        : 'Your session expired. Please try again.');
    redirect($p === 'login' || $p === 'setup' ? '' : 'p=' . rawurlencode($p));
}

// ---------- First run: create the first admin ----------
if (!users_all()) {
    page_setup($isPost);
}

if ($p === 'logout' && $isPost) {
    $u = current_user();
    if ($u) {
        audit($u['email'], 'signed out');
    }
    logout();
    flash('ok', 'You have signed out.');
    redirect();
}

$me = current_user();
if (!$me) {
    page_login($isPost);
}

// Someone whose password was set by an admin must choose their own first.
if (!empty($me['must_change'])) {
    page_password($me, $isPost, true);
}

$adminOnly = ['users', 'user_edit', 'user_save', 'user_reset', 'user_delete', 'activity', 'settings', 'settings_save', 'settings_test', 'news_run', 'backup_download'];
if (in_array($p, $adminOnly, true) && !is_admin($me)) {
    flash('error', 'Only admins can do that.');
    redirect();
}

switch ($p) {
    case 'password':    page_password($me, $isPost, false);
    case 'minutes':     page_minutes($me);
    case 'min_edit':    page_min_edit($me);
    case 'min_save':    $isPost ? action_min_save($me) : redirect('p=minutes');
    case 'min_delete':  $isPost ? action_min_delete($me) : redirect('p=minutes');
    case 'min_upload':  $isPost ? action_min_upload($me) : redirect('p=minutes');
    case 'min_link':    $isPost ? action_min_link($me) : redirect('p=minutes');
    case 'min_item_delete': $isPost ? action_min_item_delete($me) : redirect('p=minutes');
    case 'ann_edit':    page_ann_edit($me);
    case 'ann_save':    $isPost ? action_ann_save($me) : redirect();
    case 'ann_delete':  $isPost ? action_ann_delete($me) : redirect();
    case 'users':       page_users($me);
    case 'user_edit':   page_user_edit($me);
    case 'user_save':   $isPost ? action_user_save($me) : redirect('p=users');
    case 'user_reset':  $isPost ? action_user_reset($me) : redirect('p=users');
    case 'user_delete': $isPost ? action_user_delete($me) : redirect('p=users');
    case 'activity':    page_activity($me);
    case 'settings':    page_settings($me);
    case 'settings_save': $isPost ? action_settings_save($me) : redirect('p=settings');
    case 'settings_test': $isPost ? action_settings_test($me) : redirect('p=settings');
    case 'backup_download': $isPost ? action_backup_download($me) : redirect('p=settings');
    case 'news_run':    $isPost ? action_news_run($me) : redirect('p=settings');
    default:            page_announcements($me);
}

// =====================================================================
// Pages and actions (each one ends the request)
// =====================================================================

function page_setup(bool $isPost): void
{
    $email = $name = '';
    $error = '';
    if ($isPost) {
        $email = strtolower(clean_line((string)($_POST['email'] ?? ''), 200));
        $name  = clean_line((string)($_POST['name'] ?? ''), 100);
        $pw    = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $error = password_problem($pw, (string)($_POST['confirm'] ?? ''));
        }
        if ($error === '') {
            $created = store_update('users', USERS_DEFAULT, function (array &$d) use ($email, $name, $pw) {
                if ($d['users']) {
                    return false;   // someone else finished setup first
                }
                $d['users'][] = new_user_record($d['next_id']++, $email, $name, 'admin', $pw, false);
                return true;
            });
            if ($created) {
                audit($email, 'created the first admin account (setup)');
                $u = user_by_email($email);
                login_as($u);
                flash('ok', 'Setup complete. You are signed in as an admin.');
            }
            redirect();
        }
    }
    $body = '<section class="card narrow"><h1>First-time setup</h1>'
          . '<p>No users exist yet. Create the first <strong>admin</strong> account. You can add everyone else afterwards.</p>'
          . ($error ? '<div class="flash error">' . h($error) . '</div>' : '')
          . '<form method="post" action="?p=setup">' . csrf_field()
          . field('Email', input('email', $email, 'email', 'required autocomplete="username"'))
          . field('Name', input('name', $name, 'text', 'autocomplete="name"'))
          . field('Password', input('password', '', 'password', 'required autocomplete="new-password" minlength="' . MIN_PASSWORD . '"'), 'At least ' . MIN_PASSWORD . ' characters.')
          . field('Confirm password', input('confirm', '', 'password', 'required autocomplete="new-password"'))
          . '<button class="btn" type="submit">Create admin account</button></form></section>';
    render('Setup', $body);
}

function page_login(bool $isPost): void
{
    $email = '';
    $error = '';
    if ($isPost) {
        $email = strtolower(clean_line((string)($_POST['email'] ?? ''), 200));
        $pw    = (string)($_POST['password'] ?? '');
        if (throttled($email)) {
            $error = 'Too many failed sign-ins. Please wait 15 minutes and try again.';
        } else {
            $u = user_by_email($email);
            // Always do the slow hash work, so an unknown email takes as long as a wrong password.
            $ok = $u ? password_verify($pw, $u['password_hash']) : (password_hash($pw, PASSWORD_DEFAULT) && false);
            if (!$u || !$ok) {
                throttle_fail($email);
                audit($email, 'failed sign-in from ' . client_ip());
                $error = 'That email and password do not match.';
            } elseif (($why = user_blocked_reason($u)) !== '') {
                $error = $why;
            } else {
                throttle_clear($email);
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                    update_user($u['id'], fn(array &$x) => $x['password_hash'] = password_hash($pw, PASSWORD_DEFAULT));
                }
                update_user($u['id'], fn(array &$x) => $x['last_login'] = now());
                login_as($u);
                audit($u['email'], 'signed in');
                redirect();
            }
        }
    }
    $body = '<section class="card narrow"><h1>Sign in</h1>'
          . ($error ? '<div class="flash error">' . h($error) . '</div>' : '')
          . '<form method="post" action="?p=login">' . csrf_field()
          . field('Email', input('email', $email, 'email', 'required autofocus autocomplete="username"'))
          . field('Password', input('password', '', 'password', 'required autocomplete="current-password"'))
          . '<button class="btn" type="submit">Sign in</button></form>'
          . '<p class="help">Forgot your password? Ask a website admin to reset it.</p></section>';
    render('Sign in', $body);
}

function page_password(array $me, bool $isPost, bool $forced): void
{
    $error = '';
    if ($isPost) {
        $cur = (string)($_POST['current'] ?? '');
        $pw  = (string)($_POST['password'] ?? '');
        if (!password_verify($cur, $me['password_hash'])) {
            $error = 'Your current password is not correct.';
        } elseif (($error = password_problem($pw, (string)($_POST['confirm'] ?? ''))) === '') {
            if (hash_equals($cur, $pw)) {
                $error = 'Please choose a password different from the current one.';
            } else {
                update_user($me['id'], function (array &$x) use ($pw) {
                    $x['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                    $x['must_change'] = false;
                    $x['session_version']++;      // signs out any other sessions
                    $x['updated_at'] = now();
                });
                audit($me['email'], 'changed own password');
                login_as(user_by_id($me['id']));
                flash('ok', 'Your password has been changed.');
                redirect();
            }
        }
    }
    $intro = $forced
        ? '<p>Your password was set by an admin. Please choose your own password to continue.</p>'
        : '<p>Choose a new password for <strong>' . h($me['email']) . '</strong>. Other devices signed in to your account will be signed out.</p>';
    $body = '<section class="card narrow"><h1>' . ($forced ? 'Choose your password' : 'Change my password') . '</h1>' . $intro
          . ($error ? '<div class="flash error">' . h($error) . '</div>' : '')
          . '<form method="post" action="?p=password">' . csrf_field()
          . '<input type="hidden" name="username" value="' . h($me['email']) . '" autocomplete="username">'
          . field($forced ? 'Password you were given' : 'Current password', input('current', '', 'password', 'required autocomplete="current-password"'))
          . field('New password', input('password', '', 'password', 'required autocomplete="new-password" minlength="' . MIN_PASSWORD . '"'), 'At least ' . MIN_PASSWORD . ' characters. A short phrase is easier to remember.')
          . field('Confirm new password', input('confirm', '', 'password', 'required autocomplete="new-password"'))
          . '<button class="btn" type="submit">Save password</button></form></section>';
    render('My password', $body, $me, 'password');
}

// ---------- Announcements ----------

function page_announcements(array $me): void
{
    $rows = '';
    foreach (array_reverse(announcements_all()) as $a) {
        $st = announcement_status($a);
        $label = ['active' => 'Showing now', 'scheduled' => 'Scheduled', 'expired' => 'Expired'][$st];
        $rows .= '<article class="ann ' . $st . '"><div class="ann-head">' . badge($label, $st)
               . '<span class="dates">' . h(nice_date($a['start_date'])) . ' – ' . h(nice_date($a['expiry_date'])) . '</span></div>'
               . ($a['title'] !== '' ? '<h3>' . h($a['title']) . '</h3>' : '')
               . '<div class="ann-body">' . format_announcement($a['body']) . '</div>'
               . '<div class="ann-foot"><small>Last changed by ' . h($a['updated_by']) . ', ' . h($a['updated_at']) . '</small>'
               . '<span class="actions"><a class="btn btn-quiet" href="?p=ann_edit&amp;id=' . $a['id'] . '">Edit</a>'
               . post_button('ann_delete', ['id' => $a['id']], 'Delete', 'btn btn-danger', 'Delete this announcement? This cannot be undone.')
               . '</span></div></article>';
    }
    if ($rows === '') {
        $rows = '<p class="empty">No announcements yet.</p>';
    }
    $body = '<div class="bar"><h1>Announcements</h1><a class="btn" href="?p=ann_edit">+ New announcement</a></div>'
          . '<p class="help">Announcements show on the public home page and the calendar page from their start date through their end date. You can also post by email: send it to <strong>' . h(settings()['mailbox']) . '</strong> with the title as the subject (optional first lines <code>Start: 5/23/2026</code> and <code>End: 6/6/2026</code>).</p>'
          . $rows;
    render('Announcements', $body, $me, 'announcements');
}

function page_ann_edit(array $me, ?array $form = null, string $error = ''): void
{
    $id = (int)($_GET['id'] ?? ($form['id'] ?? 0));
    $a = null;
    if ($id) {
        foreach (announcements_all() as $x) {
            if ($x['id'] === $id) {
                $a = $x;
            }
        }
        if (!$a) {
            flash('error', 'That announcement no longer exists.');
            redirect();
        }
    }
    $v = $form ?? ($a ?? ['title' => '', 'body' => '', 'start_date' => today(),
                           'expiry_date' => date('Y-m-d', strtotime('+14 days'))]);
    $preview = $v['body'] !== '' ? '<div class="preview"><span class="lbl">Preview</span><div class="ann-body">' . format_announcement($v['body']) . '</div></div>' : '';
    $body = '<section class="card"><h1>' . ($a ? 'Edit announcement' : 'New announcement') . '</h1>'
          . ($error ? '<div class="flash error">' . h($error) . '</div>' : '')
          . '<form method="post" action="?p=ann_save">' . csrf_field()
          . '<input type="hidden" name="id" value="' . ($a ? $a['id'] : 0) . '">'
          . field('Title (optional)', input('title', $v['title'], 'text', 'maxlength="' . MAX_TITLE . '"'))
          . field('Message', '<textarea name="body" rows="7" required maxlength="' . MAX_BODY . '">' . h($v['body']) . '</textarea>',
                  'Formatting: <code>**bold**</code>, <code>*italic*</code>, <code>[link text](https://example.com)</code>. Web addresses become links automatically. Leave a blank line to start a new paragraph.')
          . '<div class="row">'
          . field('Show from', input('start_date', $v['start_date'], 'date', 'required'))
          . field('Show until (last day)', input('expiry_date', $v['expiry_date'], 'date', 'required'))
          . '</div>'
          . $preview
          . '<div class="buttons"><button class="btn" type="submit" name="go" value="save">Save</button>'
          . '<button class="btn btn-quiet" type="submit" name="go" value="preview">Preview</button>'
          . '<a class="btn btn-quiet" href="./">Cancel</a></div></form></section>';
    render($a ? 'Edit announcement' : 'New announcement', $body, $me, 'announcements');
}

function action_ann_save(array $me): void
{
    $f = [
        'id'          => (int)($_POST['id'] ?? 0),
        'title'       => clean_line((string)($_POST['title'] ?? ''), MAX_TITLE),
        'body'        => clean_text((string)($_POST['body'] ?? ''), MAX_BODY),
        'start_date'  => (string)($_POST['start_date'] ?? ''),
        'expiry_date' => (string)($_POST['expiry_date'] ?? ''),
    ];
    $error = '';
    if ($f['body'] === '') {
        $error = 'Please enter a message.';
    } elseif (!valid_date($f['start_date']) || !valid_date($f['expiry_date'])) {
        $error = 'Please enter both dates.';
    } elseif ($f['expiry_date'] < $f['start_date']) {
        $error = 'The end date must be on or after the start date.';
    }
    if ($error !== '' || ($_POST['go'] ?? '') === 'preview') {
        $_GET['id'] = $f['id'];
        page_ann_edit($me, $f, $error);
    }
    $ok = store_update('announcements', ANN_DEFAULT, function (array &$d) use ($f, $me) {
        if ($f['id'] === 0) {
            $d['announcements'][] = [
                'id' => $d['next_id']++, 'title' => $f['title'], 'body' => $f['body'],
                'start_date' => $f['start_date'], 'expiry_date' => $f['expiry_date'],
                'created_by' => $me['email'], 'created_at' => now(),
                'updated_by' => $me['email'], 'updated_at' => now(),
            ];
            return true;
        }
        foreach ($d['announcements'] as &$a) {
            if ($a['id'] === $f['id']) {
                $a = array_merge($a, [
                    'title' => $f['title'], 'body' => $f['body'],
                    'start_date' => $f['start_date'], 'expiry_date' => $f['expiry_date'],
                    'updated_by' => $me['email'], 'updated_at' => now(),
                ]);
                return true;
            }
        }
        return false;
    });
    if (!$ok) {
        flash('error', 'That announcement no longer exists.');
        redirect();
    }
    audit($me['email'], ($f['id'] ? 'edited' : 'added') . ' announcement "' . mb_substr($f['title'] ?: $f['body'], 0, 60) . '"');
    flash('ok', 'Announcement saved.');
    redirect();
}

function action_ann_delete(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $gone = store_update('announcements', ANN_DEFAULT, function (array &$d) use ($id) {
        foreach ($d['announcements'] as $i => $a) {
            if ($a['id'] === $id) {
                array_splice($d['announcements'], $i, 1);
                return $a;
            }
        }
        return false;
    });
    if ($gone) {
        audit($me['email'], 'deleted announcement "' . mb_substr($gone['title'] ?: $gone['body'], 0, 60) . '"');
        flash('ok', 'Announcement deleted.');
    }
    redirect();
}

// ---------- Users (admins only) ----------

function new_user_record(int $id, string $email, string $name, string $role, string $pw, bool $mustChange, string $expires = ''): array
{
    return [
        'id' => $id, 'email' => $email, 'name' => $name, 'role' => $role,
        'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'must_change' => $mustChange, 'active' => true, 'expires_on' => $expires,
        'session_version' => 1, 'created_at' => now(), 'updated_at' => now(), 'last_login' => '',
    ];
}

/** Apply $fn to one stored user under lock. Returns false if the user wasn't found. */
function update_user(int $id, callable $fn): bool
{
    return (bool)store_update('users', USERS_DEFAULT, function (array &$d) use ($id, $fn) {
        foreach ($d['users'] as &$u) {
            if ($u['id'] === $id) {
                $fn($u);
                return true;
            }
        }
        return false;
    });
}

function page_users(array $me): void
{
    $rows = '';
    foreach (users_all() as $u) {
        $state = user_blocked_reason($u) === '' ? badge('Active', 'active') : badge(empty($u['active']) ? 'Off' : 'Expired', 'expired');
        $rows .= '<tr><td><strong>' . h($u['name'] ?: '—') . '</strong><br><small>' . h($u['email']) . '</small></td>'
               . '<td>' . (is_admin($u) ? 'Admin' : 'Editor') . '</td>'
               . '<td>' . $state . ($u['must_change'] ? '<br><small>Must set password</small>' : '') . '</td>'
               . '<td>' . ($u['expires_on'] ? h(nice_date($u['expires_on'])) : 'Never') . '</td>'
               . '<td>' . h($u['last_login'] ?: 'Never') . '</td>'
               . '<td class="actions"><a class="btn btn-quiet" href="?p=user_edit&amp;id=' . $u['id'] . '">Edit</a></td></tr>';
    }
    $body = '<div class="bar"><h1>Users</h1><a class="btn" href="?p=user_edit">+ Add user</a></div>'
          . '<p class="help"><strong>Editors</strong> manage announcements, meeting minutes and their own password. <strong>Admins</strong> can also add and remove users and reset passwords.</p>'
          . '<div class="table-wrap"><table><thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Access ends</th><th>Last sign-in</th><th></th></tr></thead><tbody>'
          . $rows . '</tbody></table></div>';
    render('Users', $body, $me, 'users');
}

function page_user_edit(array $me, ?array $form = null, string $error = ''): void
{
    $id = (int)($_GET['id'] ?? ($form['id'] ?? 0));
    $u = $id ? user_by_id($id) : null;
    if ($id && !$u) {
        flash('error', 'That user no longer exists.');
        redirect('p=users');
    }
    $v = $form ?? ($u ?? ['email' => '', 'name' => '', 'role' => 'editor', 'active' => true, 'expires_on' => '']);
    $self = $u && $u['id'] === $me['id'];
    $roleSel = '<select name="role"' . ($self ? ' disabled' : '') . '>'
             . '<option value="editor"' . ($v['role'] === 'editor' ? ' selected' : '') . '>Editor (announcements, minutes)</option>'
             . '<option value="admin"' . ($v['role'] === 'admin' ? ' selected' : '') . '>Admin (everything, plus users)</option></select>';
    $nextMay = date('Y') . '-05-01' > today() ? date('Y') . '-05-01' : (date('Y') + 1) . '-05-01';

    $body = '<section class="card"><h1>' . ($u ? 'Edit user' : 'Add user') . '</h1>'
          . ($error ? '<div class="flash error">' . h($error) . '</div>' : '')
          . '<form method="post" action="?p=user_save">' . csrf_field()
          . '<input type="hidden" name="id" value="' . ($u ? $u['id'] : 0) . '">'
          . field('Email (used to sign in)', input('email', $v['email'], 'email', 'required'))
          . field('Name', input('name', $v['name']))
          . field('Role', $roleSel, $self ? 'You can\'t change your own role.' : '')
          . field('Access ends (optional)', input('expires_on', $v['expires_on'], 'date'),
                  'Leave blank for no end date. Board terms usually end on ' . h(nice_date($nextMay)) . '.')
          . ($u ? '<label class="check"><input type="checkbox" name="active" value="1"' . (!empty($v['active']) ? ' checked' : '') . ($self ? ' disabled' : '') . '> Account is on (can sign in)</label>' : '')
          . (!$u ? field('Temporary password', input('password', $form['password'] ?? random_password(), 'text', 'required autocomplete="off" minlength="' . MIN_PASSWORD . '"'),
                         'Give this to the new user. They must choose their own password the first time they sign in.') : '')
          . '<div class="buttons"><button class="btn" type="submit">Save</button><a class="btn btn-quiet" href="?p=users">Cancel</a></div></form></section>';

    if ($u && !$self) {
        $body .= '<section class="card"><h2>Reset password</h2>'
               . '<p>Sets a temporary password and signs this user out everywhere. They must choose a new password when they next sign in.</p>'
               . '<form method="post" action="?p=user_reset" class="row-form">' . csrf_field()
               . '<input type="hidden" name="id" value="' . $u['id'] . '">'
               . field('Temporary password', input('password', random_password(), 'text', 'required autocomplete="off" minlength="' . MIN_PASSWORD . '"'))
               . '<button class="btn" type="submit">Reset password</button></form></section>'
               . '<section class="card danger-zone"><h2>Remove user</h2><p>Deletes this account. Their past announcements stay.</p>'
               . post_button('user_delete', ['id' => $u['id']], 'Delete user', 'btn btn-danger', 'Delete ' . $u['email'] . '? This cannot be undone.')
               . '</section>';
    } elseif ($self) {
        $body .= '<p class="help">To change your own password, use <a href="?p=password">My password</a>.</p>';
    }
    render($u ? 'Edit user' : 'Add user', $body, $me, 'users');
}

function action_user_save(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $existing = $id ? user_by_id($id) : null;
    $self = $existing && $existing['id'] === $me['id'];
    $f = [
        'id'         => $id,
        'email'      => strtolower(clean_line((string)($_POST['email'] ?? ''), 200)),
        'name'       => clean_line((string)($_POST['name'] ?? ''), 100),
        'role'       => $self ? $me['role'] : (($_POST['role'] ?? '') === 'admin' ? 'admin' : 'editor'),
        'active'     => $self ? true : ($existing ? !empty($_POST['active']) : true),
        'expires_on' => (string)($_POST['expires_on'] ?? ''),
        'password'   => (string)($_POST['password'] ?? ''),
    ];
    $error = '';
    if ($id && !$existing) {
        flash('error', 'That user no longer exists.');
        redirect('p=users');
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($f['expires_on'] !== '' && !valid_date($f['expires_on'])) {
        $error = 'Please enter a valid end date, or leave it blank.';
    } elseif (($other = user_by_email($f['email'])) && $other['id'] !== $id) {
        $error = 'Another user already has that email address.';
    } elseif (!$existing && mb_strlen($f['password']) < MIN_PASSWORD) {
        $error = 'The temporary password must be at least ' . MIN_PASSWORD . ' characters.';
    } elseif ($self && $f['expires_on'] !== '' && $f['expires_on'] < today()) {
        $error = 'You can\'t set your own access to end in the past.';
    } elseif ($existing && $existing['role'] === 'admin'
        && ($f['role'] !== 'admin' || !$f['active'] || ($f['expires_on'] !== '' && $f['expires_on'] < today()))
        && count_active_admins(users_all(), $id) === 0) {
        $error = 'This is the only active admin. Make someone else an admin first.';
    }
    if ($error !== '') {
        page_user_edit($me, $f, $error);
    }

    store_update('users', USERS_DEFAULT, function (array &$d) use ($f, $existing) {
        if (!$existing) {
            $d['users'][] = new_user_record($d['next_id']++, $f['email'], $f['name'], $f['role'], $f['password'], true, $f['expires_on']);
            return true;
        }
        foreach ($d['users'] as &$u) {
            if ($u['id'] === $f['id']) {
                $roleOrStateChanged = $u['role'] !== $f['role'] || $u['active'] !== $f['active'] || $u['email'] !== $f['email'];
                $u = array_merge($u, ['email' => $f['email'], 'name' => $f['name'], 'role' => $f['role'],
                    'active' => $f['active'], 'expires_on' => $f['expires_on'], 'updated_at' => now()]);
                if ($roleOrStateChanged && !$f['active']) {
                    $u['session_version']++;   // turning an account off signs it out
                }
                return true;
            }
        }
        return false;
    });
    audit($me['email'], ($existing ? 'updated' : 'added') . ' user ' . $f['email'] . ' (' . $f['role'] . ($f['active'] ? '' : ', off') . ')');
    flash('ok', $existing ? 'User saved.' : 'User added. Give them their email and temporary password.');
    redirect('p=users');
}

function action_user_reset(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $pw = (string)($_POST['password'] ?? '');
    $u = user_by_id($id);
    if (!$u || $u['id'] === $me['id']) {
        redirect('p=users');
    }
    if (mb_strlen($pw) < MIN_PASSWORD) {
        flash('error', 'The temporary password must be at least ' . MIN_PASSWORD . ' characters.');
        redirect('p=user_edit&id=' . $id);
    }
    update_user($id, function (array &$x) use ($pw) {
        $x['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        $x['must_change'] = true;
        $x['session_version']++;
        $x['updated_at'] = now();
    });
    throttle_clear($u['email']);
    audit($me['email'], 'reset the password for ' . $u['email']);
    flash('ok', 'Password reset for ' . $u['email'] . '. Give them the temporary password; they will choose a new one when they sign in.');
    redirect('p=users');
}

function action_user_delete(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $u = user_by_id($id);
    if (!$u || $u['id'] === $me['id']) {
        flash('error', 'You can\'t delete your own account.');
        redirect('p=users');
    }
    if ($u['role'] === 'admin' && count_active_admins(users_all(), $id) === 0) {
        flash('error', 'This is the only active admin. Make someone else an admin first.');
        redirect('p=users');
    }
    store_update('users', USERS_DEFAULT, function (array &$d) use ($id) {
        $d['users'] = array_values(array_filter($d['users'], fn($x) => $x['id'] !== $id));
        return true;
    });
    audit($me['email'], 'deleted user ' . $u['email']);
    flash('ok', 'User ' . $u['email'] . ' deleted.');
    redirect('p=users');
}

function page_activity(array $me): void
{
    $rows = '';
    foreach (audit_tail(200) as $line) {
        [$when, $who, $what] = array_pad(explode("\t", $line, 3), 3, '');
        $rows .= '<tr><td>' . h($when) . '</td><td>' . h($who) . '</td><td>' . h($what) . '</td></tr>';
    }
    $body = '<h1>Activity</h1><p class="help">The latest 200 sign-ins and changes, newest first.</p>'
          . '<div class="table-wrap"><table><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>'
          . ($rows ?: '<tr><td colspan="3">Nothing yet.</td></tr>') . '</tbody></table></div>';
    render('Activity', $body, $me, 'activity');
}
