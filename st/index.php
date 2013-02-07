<?php
require 'Slim/Slim.php';
require 'NotORM.php';

date_default_timezone_set('Asia/Tokyo');

$pdo = new PDO('mysql:host=localhost;dbname=commie', 'commie', 'Hammer und Sichel');
$db = new NotORM($pdo);

\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim(array('debug' => false));
$app->key = '3e5e0eb1209cf522b224989371da43015aa81258';
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
        'encoded' => 'tinyint', 'blog_link' => 'varchar(127)', 'channel' => 'varchar(63)');
    foreach ($row as $f => $v) {
        $t = $columns[$f];
        if (preg_match('/^tinyint/', $t)) {
            if (preg_match('/\bunsigned\b/', $t)) {
                if (!is_numeric($v) || $v < 0 || $v > 255)
                    err("Value given for '$f' must be a numeral between 0 and 255.");
            }
            else {
                if (!is_numeric($v) || $v < -127 || $v > 128)
                    err("Value given for '$f'must be a numeral between -128 and 127.");
            }
        }
        elseif (preg_match('/^smallint/', $t)) {
            if (preg_match('/\bunsigned\b/', $t)) {
                if (!is_numeric($v) || $v < 0 || $v > 65535)
                    err("Value given for '$f' must be a numeral between 0 and 65535.");
            }
            else {
                if (!is_numeric($v) || $v < -32768 || $v > 32767)
                    err("Value given for '$f' must be a numeral between -32768 and 32767.");
            }
        }
        elseif (preg_match('/^varchar/', $t)) {
            preg_match('/(?<=varchar\()\d+/', $t, $len);
            $len = $len[0];
            if (strlen($v) > $len)
                err("Value given for '$f' must be shorter than $len characters.");
        }
        elseif ($t == 'date') {
            if (!preg_match('/^[0-9]{4}-(1[0-2]|0?[1-9])-([1-2][0-9]|3(0|1)|[1-9]) (([0-1])?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $v))
                err("Value given for '$f' must be a valid date with format YYYY-m-d H:MM.");
        }
    }

}
function sanitize_show($data, $defaults = array()) {
    $show = array();
    foreach ($data as $f => $v) {
        switch ($f) {
            case 'series': case 'series_jp': case 'blog_link': case 'translator':
            case 'editor': case 'typesetter': case 'timer': case 'channel':
                $show[$f] = htmlspecialchars($v, ENT_QUOTES);
                break;
            case 'current_ep': case 'total_eps': case 'status': case 'tl_status':
            case 'ed_status': case 'ts_status': case 'tm_status': case 'encoded':
            case 'airtime':
                $show[$f] = $v;
        }
    }
    foreach ($defaults as $f => $v) {
        if (!array_key_exists($f, $show) && ($f != 'id' && $f != 'updated'))
            $show[$f] = $v;
    }
    if (strlen($show['series']) < 1 || !array_key_exists('series', $show))
        err("You need to specify a name for the series.");
    if (!in_array($show['status'], array(-1,0,1)) || !array_key_exists('status', $show)) {
        if ($show['current_ep'] == $show['total_eps'] && ($show['total_eps'] != 0 && array_key_exists('total_eps', $show)))
            $show['status'] = 1;
        else
            $show['status'] = 0;
    }
    foreach (array('tl_status', 'ed_status', 'ts_status', 'tm_status', 'encoded') as $f) {
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
        'airtime' => $date,
        'current_ep' => $ep_inc,
        'status' => $status
    ));
    return $result;
}

function prep_show($s) {
    $show = array();
    foreach ($s as $f => $v) {
        switch ($f) {
            case 'series': case 'series_jp': case 'blog_link': case 'translator':
            case 'editor': case 'typesetter': case 'timer': case 'channel':
                $show[$f] = htmlspecialchars_decode($v, ENT_QUOTES);
                break;
            case 'id': case 'current_ep': case 'total_eps': case 'status':
            case 'tl_status': case 'ed_status': case 'ts_status': case 'tm_status':
            case 'encoded':
                $show[$f] = (int)$v;
                break;
            case 'airtime':
                $show[$f] = strtotime($v);
                break;
            case 'updated':
                $show[$f] = strtotime($v)+32400;
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
$app->get('/shows(/:filter)', function ($f) use ($app, $db) {
    $shows = array();
    switch ($f) {
        case 'done': $data = $db->shows()->where('status', 1); break;
        case 'notdone': $data = $db->shows()->where('status', 0); break;
        case 'aired': $data = $db->shows()->where('airtime < ?', new DateTime())->where('status', 0)->where('encoded', 0)->order('airtime'); break;
        case 'aired_compact': $data = $db->shows()->select('series')->where('airtime < ?', new DateTime())->where('status', 0)->where('encoded', 0)->order('airtime'); break;
        case NULL: $data = $db->shows(); break;
        default: $app->notFound();
    }
    foreach ($data as $show) { $shows[] = prep_show($show); }
    sendjson(true, $shows);
});
$app->get('/show/:filter(/:method)', function ($f, $m) use ($app, $db) {
    if (preg_match('/^[0-9]+$/', $f)) {
        $_err = "Show ID $f does not exist.";
        $query = $db->shows()->where('id', (int)$f);
    }
    else {
        $_err = "Show \"$f\" does not exist.";
        $query = $db->shows()->where('series', htmlspecialchars($f, ENT_QUOTES));
    }
    if ($show = $query->fetch()) {
        switch ($m) {
            case 'substatus':
                if ($show['current_ep'] >= $show['total_eps'] && $show['total_eps'] != 0)
                    $who = array('completed', 'completed');
                elseif (strtotime($show['airtime']) > strtotime(date('Y-m-d H:i:s')))
                    $who = array('broadcaster', $show['channel']);
                elseif ($show['tl_status'] == 0)
                    $who = array('translator', $show['translator']);
                elseif ($show['ed_status'] == 0)
                    $who = array('editor', $show['editor']);
                elseif ($show['tm_status'] == 0)
                    $who = array('timer', $show['timer']);
                elseif ($show['ts_status'] == 0)
                    $who = array('typesetter', $show['typesetter']);
                $r = array(
                    'id' => (int)$show['id'],
                    'position' => $who[0],
                    'value' => $who[1],
                    'updated' => strtotime($show['updated'])+32400
                );
                break;
            case NULL: $r = prep_show($show); break;
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
        if ($where_value = $r['id'])
            $where = 'id';
        elseif ($where_value = $r['series'])
            $where = 'series';
        $data = $db->shows()->where($where, $where_value);
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
            #$v = $r['value'];
            if ($v != 1 && $v != 0)
                err('Status should either be 0 or 1.');
            $st = array('translator' => 'tl_status', 'editor' => 'ed_status', 'typesetter' => 'ts_status', 'timer' => 'tm_status', 'encoding' => 'encoded');
            $total = 0;
            foreach ($st as $f => $v) {
                if ($r['position'] == $f) {
                    $result = $show->update(sanitize_show(array("$v" => $r['value']), $show));
                    $total += $r['value'];
                }
                else
                    $total += $show[$v];
            }
            if ($total == 5) {
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
            $result = $show->update(sanitize_show(array(
                'tl_status' => 0,
                'ed_status' => 0,
                'ts_status' => 0,
                'tm_status' => 0,
                'encoded' => 0,
                'airtime' => $date,
                'current_ep' => $ep_dec,
                'status' => $status
            ), $show));
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
            $positions = array('translator', 'editor', 'typesetter', 'timer');
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
