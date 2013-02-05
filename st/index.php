<?php
require 'Slim/Slim.php';
require 'NotORM.php';

date_default_timezone_set('Asia/Tokyo');

$pdo = new PDO('mysql:host=localhost;dbname=commie', 'commie', 'Hammer und Sichel');
$db = new NotORM($pdo);

\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim(array('mode' => 'production'));
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
        'airtime' => $date,
        'current_ep' => $ep_inc,
        'status' => $status
    ));
    return $result;
}

function showa($s) {
	return array(
        'id' => (int)$s['id'],
        'series' => htmlspecialchars_decode($s['series'], ENT_QUOTES),
        'airtime' => strtotime($s['airtime']),
        'status' => (int)$s['status'],
        'current_ep' => (int)$s['current_ep'],
        'total_eps' => (int)$s['total_eps'],
        'translator' => htmlspecialchars_decode($s['translator'], ENT_QUOTES),
        'editor' => htmlspecialchars_decode($s['editor'], ENT_QUOTES),
        'typesetter' => htmlspecialchars_decode($s['typesetter'], ENT_QUOTES),
        'timer' => htmlspecialchars_decode($s['timer'], ENT_QUOTES),
        'tl_status' => (int)$s['tl_status'],
        'ed_status' => (int)$s['ed_status'],
        'ts_status' => (int)$s['ts_status'],
        'tm_status' => (int)$s['tm_status'],
        'blog_link' => htmlspecialchars_decode($s['blog_link'], ENT_QUOTES),
        'channel' => htmlspecialchars_decode($s['channel'], ENT_QUOTES),
        'updated' => strtotime($s['updated'])+32400
    );
}
#
# GET ROUTES
#
$app->get('/refresh', function() use ($app, $db) {
    $n = strtotime(date('Y-m-d H:i:s'));
    foreach ($db->shows() as $show) {
        $air = strtotime($show['airtime']);
        if ($n > $air) {
            if ($show['translator'] == 'Crunchyroll' && $show['tl_status'] != 1)
                $show->update(array('tl_status' => 1));
        }
    }
    sendjson(true, "Database updated.");
});
$app->get('/shows(/:filter)', function ($f) use ($app, $db) {
    $shows = array();
    switch ($f) {
        case 'done': $data = $db->shows()->where('status', 1); break;
        case 'notdone': $data = $db->shows()->where('status', 0); break;
        case NULL: $data = $db->shows(); break;
        default: $app->notFound();
    }
    foreach ($data as $show) { $shows[] = showa($show); }
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
            case NULL: $r = showa($show); break;
            default: $app->notFound();
        }
        sendjson(true, $r);
    }
    else
        throw new Exception($_err);
});
#
# POST ROUTES
#
$app->post('/show/new', function () use ($app, $db) {
    $r = $app->request()->getBody();
    $app->response()->header('Content-Type', 'application/json');
    if (!array_key_exists('key', $r))
        throw new Exception('You did not specify the API key.');
    if ($r['key'] != $app->key)
        throw new Exception('Unauthorized API key.');
    if (!array_key_exists('data', $r))
        throw new Exception('You did not specify any information for the new show.');
    $data = $r['data'];
    foreach ($data as $f => &$v)
        $v = htmlspecialchars($v, ENT_QUOTES);
    $show = array(
        'series' => $data['series'],
        'airtime' => $data['airtime'],
        'current_ep' => $data['current_ep'],
        'total_eps' => $data['total_eps'],
        'blog_link' => $data['blog_link'],
        'status' => $data['status'],
        'translator' => $data['translator'],
        'tl_status' => 0,
        'editor' => $data['editor'],
        'ed_status' => 0,
        'typesetter' => $data['typesetter'],
        'ts_status' => 0,
        'timer' => $data['timer'],
        'tm_status' => 0,
        'channel' => $data['channel'],
    );
    if (strlen($show['series']) < 1)
        throw new Exception('You\'ll want to enter a series name.');
    elseif (!preg_match('/^[0-9]{4}-(1[0-2]|0?[1-9])-([1-2][0-9]|3(0|1)|[1-9]) (1?[0-9]|2[0-3]):[0-5][0-9]$/', $show['airtime']))
        throw new Exception('Value given for airtime must be a valid date with format YYYY-m-d H:MM');
    elseif (!is_numeric($show['current_ep']))
        throw new Exception('Value given for current_ep is not numeric.');
    elseif (!is_numeric($show['total_eps']))
        throw new Exception('Value given for total_eps is not numeric.');
    if ($show['status'] == '')
        $show['status'] = 0;
    $result = $db->shows()->insert($show);
    sendjson((bool)$result, 'Show added.');
})->name('new_show');

$app->post('/show/delete', function () use ($app, $db) {
    $r = $app->request()->getBody();
    $app->response()->header('Content-Type', 'application/json');
    if (!array_key_exists('key', $r))
        throw new Exception('You did not specify the API key.');
    if ($r['key'] != $app->key)
        throw new Exception('Unauthorized API key.');
    if (!array_key_exists('id', $r) && !array_key_exists('series', $r))
        throw new Exception('You need to specify either the series name or ID to delete it.');
    else {
        if ($where_value = $r['id'])
            $where = 'id';
        elseif ($where_value = $r['series'])
            $where = 'series';
        $data = $db->shows()->where($where, $where_value);
        if (!$show = $data->fetch())
            throw new Exception('Show does not exist.');
        $result = $show->delete();
        sendjson((bool)$result, 'Show deleted.');
    }
})->name('delete_show');

$app->post('/show/update', function () use ($app, $db) {
    $r = $app->request()->getBody();
    $app->response()->header('Content-Type', 'application/json');
    if (!array_key_exists('key', $r))
        throw new Exception('You did not specify the API key.');
    if ($r['key'] != $app->key)
        throw new Exception('Unauthorized API key.');
    if (!array_key_exists('method', $r))
        throw new Exception('You did not specify a method.');
    if (!array_key_exists('id', $r) && !array_key_exists('series', $r))
        throw new Exception('You need to specify either the series name or ID to update it.');
    else {
        if ($where_value = $r['id'])
            $where = 'id';
        elseif ($where_value = $r['series'])
            $where = 'series';
        $data = $db->shows()->where($where, $where_value);
        if (!$show = $data->fetch())
            throw new Exception('Show does not exist.');
        $columns = array_keys(iterator_to_array($show));
    }
    switch ($r['method']) {
        case 'change_everything':
            if (!array_key_exists('data', $r))
                throw new Exception('You did not specify any information for this show.');
            $data = $r['data'];
            foreach ($data as $f => &$v) {
                $v = htmlspecialchars($v, ENT_QUOTES);
                if(!in_array("$f", $columns))
                    throw new Exception("Key '$f is invalid.");
            }
            $changes = array(
                'series' => $data['series'],
                'airtime' => $data['airtime'],
                'current_ep' => $data['current_ep'],
                'total_eps' => $data['total_eps'],
                'blog_link' => $data['blog_link'],
                'status' => $data['status'],
                'translator' => $data['translator'],
                'tl_status' => $data['tl_status'],
                'editor' => $data['editor'],
                'ed_status' => $data['ed_status'],
                'typesetter' => $data['typesetter'],
                'ts_status' => $data['ts_status'],
                'timer' => $data['timer'],
                'tm_status' => $data['tm_status'],
                'channel' => $data['channel'],
            );
            if (strlen($changes['series']) < 1)
                throw new Exception('You\'ll want to enter a series name.');
            elseif (!preg_match('/^[0-9]{4}-(1[0-2]|0?[1-9])-([1-2][0-9]|3(0|1)|[1-9]) (1?[0-9]|2[0-3]):[0-5][0-9]$/', $changes['airtime']))
                throw new Exception('Value given for airtime must be a valid date with format YYYY-m-d H:MM');
            elseif (!is_numeric($changes['current_ep']))
                throw new Exception('Value given for current_ep is not numeric.');
            elseif (!is_numeric($changes['total_eps']))
                throw new Exception('Value given for total_eps is not numeric.');
            if (!in_array($changes['tl_status'], array(0,1)))
                $changes['tl_status'] = $show['tl_status'];
            if (!in_array($changes['ed_status'], array(0,1)))
                $changes['ed_status'] = $show['ed_status'];
            if (!in_array($changes['ts_status'], array(0,1)))
                $changes['ts_status'] = $show['ts_status'];
            if (!in_array($changes['tm_status'], array(0,1)))
                $changes['tm_status'] = $show['tm_status'];
            if (!in_array($changes['status'], array(-1,0,1))) {
                if ($changes['current_ep'] == $changes['total_eps'])
                    $changes['status'] = 1;
                else
                    $changes['status'] = $show['status'];
            }
            $result = $show->update($changes);
            sendjson((bool)$result, 'Show updated. (if nothing changed, this will show up as an error.)');
            break;
        case 'position_status':
            if (!array_key_exists('position', $r))
                throw new Exception('You did not specify a position.');
            if (!array_key_exists('value', $r))
                throw new Exception('You did not specify a status.');
            $v = $r['value'];
            if ($v != 1 && $v != 0)
                throw new Exception('Status should either be 0 or 1.');
            switch ($r['position']) {
                case 'translator':
                    $result = $show->update(array('tl_status' => $v));
                    $total = $show['ed_status'] + $show['ts_status'] + $show['tm_status'];
                    break;
                case 'editor':
                    $result = $show->update(array('ed_status' => $v));
                    $total = $show['tl_status'] + $show['ts_status'] + $show['tm_status'];
                    break;
                case 'typesetter':
                    $result = $show->update(array('ts_status' => $v));
                    $total = $show['tl_status'] + $show['ed_status']+ $show['tm_status'];
                    break;
                case 'timer':
                    $result = $show->update(array('tm_status' => $v));
                    $total = $show['tl_status'] + $show['ed_status'] + $show['ts_status'];
                    break;
                default:
                    throw new Exception("Position '" . $r['position'] . "' does not exist.");
            }
            $total += $v;
            if ($total == 4) {
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
                'airtime' => $date,
                'current_ep' => $ep_dec,
                'status' => $status
            ));
            sendjson((bool)$result, 'Episode count decremented and staff counters reset.');
            break;
        case 'current_episode':
            if (!array_key_exists('value', $r))
                throw new Exception('You did not specify a new value.');
            $result = $show->update(array('current_ep' => $r['value']));
            sendjson((bool)$result, 'Current episode count updated.');
            break;
        case 'total_episodes':
            if (!array_key_exists('value', $r))
                throw new Exception('You did not specify a new value.');
            $result = $show->update(array('total_eps' => $r['value']));
            sendjson((bool)$result, 'Total episode count updated.');
            break;
        case 'position':
            if (!array_key_exists('position', $r))
                throw new Exception('You did not specify a position.');
            if (!array_key_exists('value', $r))
                throw new Exception('You did not specify a name.');
            $positions = array('translator', 'editor', 'typesetter', 'timer');
            if (!in_array($r['position'], $positions))
                throw new Exception('Position does not exist.');
            $result = $show->update(array($r['position'] => $r['value']));
            sendjson((bool)$result, 'Position updated (' . $r['position'] . ' is now ' . $r['value'] . ').');
            break;
        default:
            throw new Exception("Specified method '" . $r['method'] . "' does not exist.");
    }
})->name('update_show');

$app->run();
