<?php
require 'Slim/Slim.php';
require 'NotORM.php';
require 'config.php';

date_default_timezone_set('Asia/Tokyo');

$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
$db = new NotORM($pdo);

\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim(array('debug' => false));
$app->key = API_KEY;
$app->setName('commie_shows');
$app->add(new \Slim\Middleware\ContentTypes);
# Make 404 errors return a JSON encoded string
$app->notFound(function () { sendjson(false, "Route not found."); });
# Do the same with exceptions
$app->error(function (\Exception $e) { sendjson(false, $e->getMessage()); });

function sendjson($status, $results) {
    global $app;
    $app->response()->header('Content-Type', 'application/json');
    if ($status === true)
        $r = array('status' => true, 'results' => $results);
    else
        $r = array('status' => false, 'message' => $results);
    echo json_encode($r);
}
function err($msg) { sendjson(false, $msg); global $app; $app->stop(); }
function check_api_key($request) {
    global $app;
    if (!array_key_exists('key', $request))
        err('You did not specify the API key.');
    elseif ($request['key'] != $app->key)
        err('Unauthorized API key.');
}
function check_if_sane_sql($row) {
    $columns = array(
        'series' => 'varchar(127)', 'series_jp' => 'varchar(127)', 'airtime' => 'date',
        'current_ep' => 'smallint unsigned', 'total_eps' => 'smallint unsigned',
        'status' => 'tinyint', 'translator' => 'varchar(63)', 'tl_status' => 'tinyint unsigned',
        'editor' => 'varchar(63)', 'ed_status' => 'tinyint unsigned', 'typesetter' => 'varchar(63)',
        'ts_status' => 'tinyint unsigned', 'timer' => 'varchar(63)', 'tm_status' => 'tinyint unsigned',
        'encoded' => 'tinyint', 'qc' => 'varchar(63)', 'qc_status' => 'tinyint unsigned',
        'blog_link' => 'varchar(127)', 'channel' => 'varchar(63)', 'abbr' => 'varchar(15)',
        'folder' => 'varchar(255)', 'xdcc_folder' => 'varchar(255)', 'last_release' => 'date');
    foreach ($row as $f => $v) {
        $t = $columns[$f];
        if (preg_match('/^tinyint/', $t)) {
            if (preg_match('/\bunsigned\b/', $t)) {
                if (!is_numeric($v) || $v < 0 || $v > 255)
                    err("Value given for '$f' must be a numeral between 0 and 255. ($v)");
            }
            else {
                if (!is_numeric($v) || $v < -127 || $v > 128)
                    err("Value given for '$f'must be a numeral between -128 and 127. ($v)");
            }
        }
        elseif (preg_match('/^smallint/', $t)) {
            if (preg_match('/\bunsigned\b/', $t)) {
                if (!is_numeric($v) || $v < 0 || $v > 65535)
                    err("Value given for '$f' must be a numeral between 0 and 65535. ($v)");
            }
            else {
                if (!is_numeric($v) || $v < -32768 || $v > 32767)
                    err("Value given for '$f' must be a numeral between -32768 and 32767. ($v)");
            }
        }
        elseif (preg_match('/^varchar/', $t)) {
            preg_match('/(?<=varchar\()\d+/', $t, $len);
            $len = $len[0];
            if (strlen($v) > $len)
                err("Value given for '$f' must be shorter than $len characters. ($v)");
        }
        elseif ($t == 'date') {
            if (!preg_match('/^[0-9]{4}-(1[0-2]|0?[0-9])-([1-2][0-9]|3(0|1)|0?[0-9]) (([0-1])?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $v))
                err("Value given for '$f' must be a valid date with format YYYY-m-d H:MM. ($v)");
        }
    }

}
function sanitize_show($data, $defaults = array()) {
    $show = array();
    foreach ($data as $f => $v) {
        switch ($f) {
            case 'series': case 'series_jp': case 'blog_link': case 'translator':
            case 'editor': case 'typesetter': case 'timer': case 'channel':
            case 'abbr': case 'qc': case 'folder': case 'xdcc_folder':
                $show[$f] = htmlspecialchars($v, ENT_QUOTES);
                break;
            case 'current_ep': case 'total_eps': case 'status': case 'tl_status':
            case 'ed_status': case 'ts_status': case 'tm_status': case 'encoded':
            case 'qc_status': case 'airtime': case 'last_release':
                $show[$f] = $v;
        }
    }
    foreach ($defaults as $f => $v) {
        if (!array_key_exists($f, $show) && ($f != 'id' && $f != 'updated'))
            $show[$f] = $v;
    }
    if (strlen($show['series']) < 1 || !array_key_exists('series', $show))
        err("You need to specify a name for the series.");
    if (!in_array($show['status'], array(-2,-1,0,1)) || !array_key_exists('status', $show)) {
        //status definitions: -2 - dropped; -1 - on hold; 0 - airing; 1 - completed
        if ($show['current_ep'] == $show['total_eps'] && ($show['total_eps'] != 0 && array_key_exists('total_eps', $show)))
            $show['status'] = 1;
        else
            $show['status'] = 0;
    }
    foreach (array('tl_status', 'ed_status', 'ts_status', 'tm_status', 'encoded', 'qc_status') as $f) {
        if (!in_array($show[$f], array(0,1)))
            $show[$f] = 0;
    }
    check_if_sane_sql($show);
    return $show;
}
// Update to next episode/increase date and clear counters.
function next_episode($show) {
    $date = new DateTime($show['airtime']);
    $date->modify('+1 week');
    $ep_inc = $show['current_ep'] + 1;
    $status = ($ep_inc == $show['total_eps']?1:$show['status']);
    $result = $show->update(array(
        'tl_status' => 0,
        'ed_status' => 0,
        'ts_status' => 0,
        'tm_status' => 0,
        'encoded' => 0,
        'qc_status' => 0,
        'airtime' => $date,
        'current_ep' => $ep_inc,
        'status' => $status,
        'last_release' => new DateTime()
    ));
    return $result;
}

function prep_show($s) {
    $show = array();
    foreach ($s as $f => $v) {
        switch ($f) {
            case 'series': case 'series_jp': case 'blog_link': case 'translator':
            case 'editor': case 'typesetter': case 'timer': case 'channel':
            case 'abbr': case 'qc': case 'folder': case 'xdcc_folder':
                $show[$f] = htmlspecialchars_decode($v, ENT_QUOTES);
                break;
            case 'id': case 'current_ep': case 'total_eps': case 'status':
            case 'tl_status': case 'ed_status': case 'ts_status': case 'tm_status':
            case 'encoded': case 'qc_status':
                $show[$f] = (int)$v;
                break;
            case 'airtime':
                $show[$f] = strtotime($v);
                break;
            case 'updated': case 'last_release':
                $show[$f] = strtotime($v)+50400;
        }
    }
	return $show;
}
#
# GET ROUTES
#
$app->get('/refresh', function() use ($app, $db) {
    $n = strtotime(date('Y-m-d H:i:s'));
    foreach ($db->shows() as $show) {
        /* 04:12:25 <&herkz> what if it's latecast?
         * 04:14:46 <&lae> if you want to .done those shows, I can take CR shows off the cronjob
         * 04:15:31 <&herkz> probably should */
        /* $air = strtotime($show['airtime']);
        if ($n > $air) {
            if ($show['translator'] == 'Crunchyroll' && $show['tl_status'] != 1)
                $show->update(array('tl_status' => 1));
        } */
    }
    sendjson(true, "Database updated.");
});
$app->get('/shows(/:filter)', function ($f=NULL) use ($app, $db) {
    $shows = array();
    switch ($f) {
        case 'done': $data = $db->shows()->where('status', 1); break;
        case 'notdone': $data = $db->shows()->where('status < ?', 1); break;
        case 'aired': $data = $db->shows()->where('airtime < ?', new DateTime())->where('status', 0)->where('encoded', 0)->order('airtime'); break;
        case 'aired_compact': $data = $db->shows()->select('id, series, current_ep')->where('airtime < ?', new DateTime())->where('status', 0)->where('encoded', 0)->order('airtime'); break;
        case 'current_episodes': $data = $db->shows()->select('id, series, abbr, current_ep, updated, last_release')->where('status', 0')->where('current_ep > 0')->order('series'); break;
        case NULL: $data = $db->shows(); break;
        default: $app->notFound();
    }
    foreach ($data as $show) { $shows[] = prep_show($show); }
    sendjson(true, $shows);
});
$app->get('/show/:filter(/:method)', function ($f, $m=NULL) use ($app, $db) {
    if (preg_match('/^[0-9]+$/', $f)) {
        $_err = "Show ID $f does not exist.";
        $query = $db->shows()->where('id', (int)$f);
    }
    else {
        $_err = "Show \"$f\" does not exist.";
        $query = $db->shows()->where('series', htmlspecialchars($f, ENT_QUOTES));
    }
    if ($show = $query->fetch()) {
        $show = prep_show($show);
        switch ($m) {
            case 'substatus':
                if ($show['current_ep'] >= $show['total_eps'] && $show['total_eps'] != 0)
                    $who = array('completed', 'completed');
                elseif ($show['status'] == -2)
                    $who = array('DROPPED', 'DROPPED');
                elseif (strtotime($show['airtime']) > strtotime(date('Y-m-d H:i:s')))
                    $who = array('broadcaster', $show['channel']);
                else {
                    switch(0) {
                        case $show['tl_status']: $who = array('translator', $show['translator']); break;
                        case $show['ed_status']: $who = array('editor', $show['editor']); break;
                        case $show['encoded']: $who = array('encoder', 'Servrhe'); break;
                        case $show['tm_status']: $who = array('timer', $show['timer']); break;
                        case $show['ts_status']: $who = array('typesetter', $show['typesetter']); break;
                        case $show['qc_status']: $who = array('quality control', $show['qc']); break;
                    }
                }
                $r = array('id' => (int)$show['id'], 'updated' => $show['updated']);
                $r = array_merge($r, array('position' => $who[0], 'value' => $who[1]));
                break;
            case 'translator': case 'editor': case 'typesetter': case 'timer': case 'qc':
                $r = array('id' => (int)$show['id'], 'position' => $m, 'name' => $show[$m]);
                break;
            case NULL: $r = $show; break;
            default: $app->notFound();
        }
        sendjson(true, $r);
    }
    else
        err($_err);
});
#
# POST ROUTES
#
$app->post('/show/new', function () use ($app, $db) {
    $r = $app->request()->getBody();
    check_api_key($r);
    if (!array_key_exists('data', $r))
        err('You did not specify any information for the new show.');
    $show = sanitize_show($r['data']);
    $result = $db->shows()->insert($show);
    if ($result)
        sendjson(true, 'Show added.');
    else
        sendjson(false, 'Show could not be added.');
});

$app->post('/show/delete', function () use ($app, $db) {
    $r = $app->request()->getBody();
    check_api_key($r);
    if (!array_key_exists('id', $r) && !array_key_exists('series', $r))
        err('You need to specify either the series name or ID to delete it.');
    else {
        if ($where_value = $r['id'])
            $where = 'id';
        elseif ($where_value = $r['series'])
            $where = 'series';
        $data = $db->shows()->where($where, $where_value);
        if (!$show = $data->fetch())
            err('Show does not exist.');
        $result = $show->delete();
        sendjson((bool)$result, 'Show deleted.');
    }
})->name('delete_show');

$app->post('/show/update', function () use ($app, $db) {
    $r = $app->request()->getBody();
    check_api_key($r);
    if (!array_key_exists('method', $r))
        err('You did not specify a method.');
    if (!array_key_exists('id', $r) && !array_key_exists('series', $r))
        err('You need to specify either the series name or ID to update it.');
    else {
        if (isset($r['id']))
            $where = 'id';
        elseif (isset($r['series']))
            $where = 'series';
        $data = $db->shows()->where($where, $r[$where]);
        if (!$show = $data->fetch())
            err('Show does not exist.');
        $columns = array_keys(iterator_to_array($show));
    }
    switch ($r['method']) {
        case 'change_everything':
            if (!array_key_exists('data', $r))
                err('You did not specify any information for this show.');
            $result = $show->update(sanitize_show($r['data'], $show));
            sendjson((bool)$result, 'Show updated. (if nothing changed, this will show up as an error.)');
            break;
        case 'position_status':
            if (!array_key_exists('position', $r))
                err('You did not specify a position.');
            if (!array_key_exists('value', $r))
                err('You did not specify a status.');
            $val = $r['value'];
            if ($val != 1 && $val != 0)
                err('Status should either be 0 or 1.');
            $st = array('translator' => 'tl_status', 'editor' => 'ed_status', 'typesetter' => 'ts_status', 'timer' => 'tm_status', 'encoder' => 'encoded', 'qc' => 'qc_status');
            $total = 0;
            foreach ($st as $f => $v) {
                if ($r['position'] == $f) {
                    $result = $show->update(sanitize_show(array("$v" => $val), $show));
                    $total += $val;
                }
                else
                    $total += $show[$v];
            }
            if ($total == 6) {
                $result = next_episode($show);
                sendjson((bool)$result, 'Show completed and counters reset.');
            } else
                sendjson((bool)$result, 'Show updated.');
            break;
        case 'next_episode':
            $result = next_episode($show);
            sendjson((bool)$result, 'Episode count updated and staff counters reset.');
            break;
        case 'restart_last_episode':
            $date = new DateTime($show['airtime']);
            $date->modify('-1 week');
            $ep_dec = $show['current_ep'] - 1;
            $status = ($ep_dec < $show['total_eps']?0:1);
            $result = $show->update(array(
                'tl_status' => 0,
                'ed_status' => 0,
                'ts_status' => 0,
                'tm_status' => 0,
                'encoded' => 0,
                'qc_status' => 0,
                'airtime' => $date,
                'current_ep' => $ep_dec,
                'status' => $status
            ));
            sendjson((bool)$result, 'Episode count decremented and staff counters reset.');
            break;
        case 'current_episode':
            if (!array_key_exists('value', $r))
                err('You did not specify a new value.');
            $result = $show->update(sanitize_show(array('current_ep' => $r['value']), $show));
            sendjson((bool)$result, 'Current episode count updated.');
            break;
        case 'total_episodes':
            if (!array_key_exists('value', $r))
                err('You did not specify a new value.');
            $result = $show->update(sanitize_show(array('total_eps' => $r['value']), $show));
            sendjson((bool)$result, 'Total episode count updated.');
            break;
        case 'position':
            if (!array_key_exists('position', $r))
                err('You did not specify a position.');
            if (!array_key_exists('value', $r))
                err('You did not specify a name.');
            $positions = array('translator', 'editor', 'typesetter', 'timer', 'qc');
            if (!in_array($r['position'], $positions))
                err('Position does not exist.');
            $result = $show->update(sanitize_show(array($r['position'] => $r['value']), $show));
            sendjson((bool)$result, 'Position updated (' . $r['position'] . ' is now ' . $r['value'] . ').');
            break;
        default:
            err("Specified method '" . $r['method'] . "' does not exist.");
    }
})->name('update_show');

$app->run();
