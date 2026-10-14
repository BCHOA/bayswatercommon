<?php
/** Bayswater Common HOA admin: meeting minutes pages and actions (editors and admins). */
declare(strict_types=1);

defined('BCHOA') or exit;

function item_list_html(array $m, bool $withRemove): string
{
    $items = '';
    foreach ($m['items'] as $it) {
        $items .= '<li>' . badge(strtoupper($it['kind']), 'scheduled')
                . ' <a href="' . h(item_url($it)) . '" target="_blank" rel="noopener">' . h($it['label']) . '</a>'
                . ($it['file'] !== '' ? ' <small>(' . h(human_size((int)$it['size'])) . ')</small>' : '')
                . ($withRemove ? ' ' . post_button('min_item_delete', ['id' => $m['id'], 'item' => $it['id']], 'Remove', 'btn btn-danger btn-small', 'Remove "' . $it['label'] . '"?') : '')
                . '</li>';
    }
    return $items !== '' ? '<ul class="items">' . $items . '</ul>' : '';
}

function page_minutes(array $me): void
{
    minutes_dir();   // also refreshes the uploads folder's .htaccess after an update
    $rows = '';
    foreach (meetings_all() as $m) {
        $items = item_list_html($m, false);
        $rows .= '<article class="ann active"><div class="ann-head"><span class="dates">' . h(nice_date($m['date'])) . '</span></div>'
               . '<h3>' . h($m['title']) . '</h3>'
               . ($m['notes'] !== '' ? '<p class="help">' . nl2br(h($m['notes']), false) . '</p>' : '')
               . ($items !== '' ? $items : '<p class="help">No files or links yet.</p>')
               . '<div class="ann-foot"><small>Last changed by ' . h($m['updated_by']) . ', ' . h($m['updated_at']) . '</small>'
               . '<span class="actions"><a class="btn btn-quiet" href="?p=min_edit&amp;id=' . $m['id'] . '">Edit / add files</a>'
               . post_button('min_delete', ['id' => $m['id']], 'Delete', 'btn btn-danger', 'Delete this meeting and all of its uploaded files? This cannot be undone.')
               . '</span></div></article>';
    }
    $body = '<div class="bar"><h1>Meeting minutes</h1><a class="btn" href="?p=min_edit">+ Add meeting</a></div>'
          . '<p class="help">Each meeting can have any number of files (PDF minutes, audio recordings, documents) and links (Zoom recordings or transcripts, Google Drive, etc.). Everything here shows on the public HOA Documents page.</p>'
          . ($rows ?: '<p class="empty">No meetings yet.</p>');
    render('Meeting minutes', $body, $me, 'minutes');
}

function page_min_edit(array $me, ?array $form = null, string $error = ''): void
{
    $id = (int)($_GET['id'] ?? ($form['id'] ?? 0));
    $m = $id ? meeting_by_id($id) : null;
    if ($id && !$m) {
        flash('error', 'That meeting no longer exists.');
        redirect('p=minutes');
    }
    $v = $form ?? ($m ?? ['date' => today(), 'title' => 'HOA Board Meeting', 'notes' => '']);
    $body = '<section class="card"><h1>' . ($m ? 'Edit meeting' : 'Add meeting') . '</h1>'
          . ($error ? '<div class="flash error">' . h($error) . '</div>' : '')
          . '<form method="post" action="?p=min_save">' . csrf_field()
          . '<input type="hidden" name="id" value="' . ($m ? $m['id'] : 0) . '">'
          . '<div class="row">'
          . field('Meeting date', input('date', $v['date'], 'date', 'required'))
          . field('Title', input('title', $v['title'], 'text', 'required maxlength="120"'))
          . '</div>'
          . field('Notes (optional)', '<textarea name="notes" rows="3" maxlength="1000">' . h($v['notes']) . '</textarea>', 'A short summary, or where to find more information.')
          . '<div class="buttons"><button class="btn" type="submit">' . ($m ? 'Save' : 'Save and add files') . '</button>'
          . '<a class="btn btn-quiet" href="?p=minutes">' . ($m ? 'Back to list' : 'Cancel') . '</a></div></form></section>';

    if ($m) {
        $items = item_list_html($m, true);
        $accept = implode(',', array_map(fn($e) => '.' . $e, array_keys(MINUTES_EXT)));
        $types = '.' . implode(', .', array_keys(MINUTES_EXT));
        $body .= '<section class="card"><h2>Files and links</h2>'
               . ($items !== '' ? $items : '<p class="help">Nothing added yet.</p>')
               . '</section>'
               . '<section class="card"><h2>Upload a file</h2>'
               . '<form method="post" action="?p=min_upload" enctype="multipart/form-data">' . csrf_field()
               . '<input type="hidden" name="id" value="' . $m['id'] . '">'
               . field('Label', input('label', '', 'text', 'maxlength="100" placeholder="e.g. Minutes (PDF) or Meeting recording"'), 'Leave blank to use the file name.')
               . field('File', '<input type="file" name="file" required accept="' . h($accept) . '">',
                       'Allowed: ' . h($types) . '. Up to ' . h(human_size(upload_limit())) . ' per file. Large recordings can take a few minutes to upload.')
               . '<button class="btn" type="submit">Upload</button></form></section>'
               . '<section class="card"><h2>Add a link</h2>'
               . '<form method="post" action="?p=min_link">' . csrf_field()
               . '<input type="hidden" name="id" value="' . $m['id'] . '">'
               . '<div class="row">'
               . field('Label', input('label', '', 'text', 'required maxlength="100" placeholder="e.g. Zoom transcript"'))
               . field('Web address', input('url', '', 'url', 'required placeholder="https://..."'))
               . '</div><button class="btn" type="submit">Add link</button></form></section>';
    }
    render($m ? 'Edit meeting' : 'Add meeting', $body, $me, 'minutes');
}

function action_min_save(array $me): void
{
    $f = [
        'id'    => (int)($_POST['id'] ?? 0),
        'date'  => (string)($_POST['date'] ?? ''),
        'title' => clean_line((string)($_POST['title'] ?? ''), 120),
        'notes' => clean_text((string)($_POST['notes'] ?? ''), 1000),
    ];
    if (!valid_date($f['date']) || $f['title'] === '') {
        $_GET['id'] = $f['id'];
        page_min_edit($me, $f, 'Please enter the meeting date and a title.');
    }
    $newId = store_update('minutes', MIN_DEFAULT, function (array &$d) use ($f, $me) {
        if ($f['id'] === 0) {
            $id = $d['next_id']++;
            $d['meetings'][] = ['id' => $id, 'date' => $f['date'], 'title' => $f['title'], 'notes' => $f['notes'], 'items' => [],
                'created_by' => $me['email'], 'created_at' => now(), 'updated_by' => $me['email'], 'updated_at' => now()];
            return $id;
        }
        foreach ($d['meetings'] as &$m) {
            if ($m['id'] === $f['id']) {
                $m = array_merge($m, ['date' => $f['date'], 'title' => $f['title'], 'notes' => $f['notes'],
                    'updated_by' => $me['email'], 'updated_at' => now()]);
                return $m['id'];
            }
        }
        return false;
    });
    if (!$newId) {
        flash('error', 'That meeting no longer exists.');
        redirect('p=minutes');
    }
    audit($me['email'], ($f['id'] ? 'edited' : 'added') . ' meeting ' . $f['date'] . ' "' . $f['title'] . '"');
    flash('ok', $f['id'] ? 'Meeting saved.' : 'Meeting added. Now upload files or add links.');
    redirect('p=min_edit&id=' . $newId);
}

function action_min_delete(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $gone = store_update('minutes', MIN_DEFAULT, function (array &$d) use ($id) {
        foreach ($d['meetings'] as $i => $m) {
            if ($m['id'] === $id) {
                array_splice($d['meetings'], $i, 1);
                return $m;
            }
        }
        return false;
    });
    if ($gone) {
        foreach ($gone['items'] as $it) {
            if ($it['file'] !== '') {
                @unlink(minutes_dir() . '/' . basename($it['file']));
            }
        }
        audit($me['email'], 'deleted meeting ' . $gone['date'] . ' "' . $gone['title'] . '" and its files');
        flash('ok', 'Meeting deleted.');
    }
    redirect('p=minutes');
}

/** Add an item to a meeting under lock. Returns false if the meeting is gone. */
function add_meeting_item(int $id, array $item, array $me): bool
{
    return (bool)store_update('minutes', MIN_DEFAULT, function (array &$d) use ($id, $item, $me) {
        foreach ($d['meetings'] as &$m) {
            if ($m['id'] === $id) {
                $item['id'] = $d['next_item']++;
                $m['items'][] = $item;
                $m['updated_by'] = $me['email'];
                $m['updated_at'] = now();
                return true;
            }
        }
        return false;
    });
}

function action_min_upload(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $back = 'p=min_edit&id=' . $id;
    $file = $_FILES['file'] ?? null;
    $err = is_array($file) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        flash('error', 'That file is larger than ' . human_size(upload_limit()) . '. Put it on Google Drive or similar and add it as a link instead.');
        redirect($back);
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        flash('error', 'Please choose a file to upload.');
        redirect($back);
    }
    if (!meeting_by_id($id)) {
        flash('error', 'That meeting no longer exists.');
        redirect('p=minutes');
    }
    $orig = basename((string)$file['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset(MINUTES_EXT[$ext])) {
        flash('error', 'Files of type .' . $ext . ' cannot be uploaded. Allowed: .' . implode(', .', array_keys(MINUTES_EXT)) . '.');
        redirect($back);
    }
    $kind = MINUTES_EXT[$ext];
    if ($kind === 'pdf' && file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') {
        flash('error', 'That file does not look like a real PDF.');
        redirect($back);
    }
    $stem = preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($orig, PATHINFO_FILENAME))) ?? 'file';
    $stem = trim(substr($stem, 0, 60), '-') ?: 'file';
    $stored = $stem . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = minutes_dir() . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        flash('error', 'The file could not be saved. Please try again.');
        redirect($back);
    }
    @chmod($dest, 0644);
    $label = clean_line((string)($_POST['label'] ?? ''), 100) ?: clean_line(pathinfo($orig, PATHINFO_FILENAME), 100);
    if (!add_meeting_item($id, ['kind' => $kind, 'label' => $label, 'file' => $stored, 'url' => '', 'size' => (int)$file['size']], $me)) {
        @unlink($dest);
        flash('error', 'That meeting no longer exists.');
        redirect('p=minutes');
    }
    audit($me['email'], 'uploaded "' . $label . '" (' . $stored . ') to meeting #' . $id);
    flash('ok', 'File uploaded.');
    redirect($back);
}

function action_min_link(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $back = 'p=min_edit&id=' . $id;
    $label = clean_line((string)($_POST['label'] ?? ''), 100);
    $url = clean_line((string)($_POST['url'] ?? ''), 1000);
    if ($label === '' || !preg_match('#^https://[^\s<>"]+$#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        flash('error', 'Please enter a label and a web address starting with https://');
        redirect($back);
    }
    if (!add_meeting_item($id, ['kind' => 'link', 'label' => $label, 'file' => '', 'url' => $url, 'size' => 0], $me)) {
        flash('error', 'That meeting no longer exists.');
        redirect('p=minutes');
    }
    audit($me['email'], 'added link "' . $label . '" to meeting #' . $id);
    flash('ok', 'Link added.');
    redirect($back);
}

function action_min_item_delete(array $me): void
{
    $id = (int)($_POST['id'] ?? 0);
    $itemId = (int)($_POST['item'] ?? 0);
    $gone = store_update('minutes', MIN_DEFAULT, function (array &$d) use ($id, $itemId, $me) {
        foreach ($d['meetings'] as &$m) {
            if ($m['id'] !== $id) {
                continue;
            }
            foreach ($m['items'] as $i => $it) {
                if ($it['id'] === $itemId) {
                    array_splice($m['items'], $i, 1);
                    $m['updated_by'] = $me['email'];
                    $m['updated_at'] = now();
                    return $it;
                }
            }
        }
        return false;
    });
    if ($gone) {
        if ($gone['file'] !== '') {
            @unlink(minutes_dir() . '/' . basename($gone['file']));
        }
        audit($me['email'], 'removed "' . $gone['label'] . '" from meeting #' . $id);
        flash('ok', 'Removed.');
    }
    redirect('p=min_edit&id=' . $id);
}
