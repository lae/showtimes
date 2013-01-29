<?php
/* TODO
 * Implement sorting - DONE in frontend
 * Allow from undetermined total episodes - DONE in frontend
 * Link back to blog for shows - DONE
 * Implement +/- for updating episode count easily
 * Use cookies instead of POST to store session - maybe? this was for the old showtimes.
 * Add checkboxes to each staff member during edit, indicating completion. Depending on a show,
 *   staff is red until they finish their job. Green until everyone finishes. Automatically 
 *   increase the episode count after everyone finishes their job. Ep # also in red.
 * Realtime ETA, ETA 30 minutes after airtime. - DONE in frontend, not realtime
 * Local airtime should use JS to convert to visitor's time. - DONE in frontend
 * Make table easier to read (i.e. more opacity) - DONE in frontend
 * Create a page for this app specifically on Commie's WordPress, w/ only default header - DONE
 * API:
   * get(series,episode,position) returns staff member - get(series,position) DONE
   * get(series,episode) returns position it is stalled at - DONE
   * set(series,episode,position,status) returns success? - set(series|id,position,status) DONE
   * set(series, episode, position, staff member) returns success? - set(series|id,position,name) DONE
   * set(series, episode) returns success? - DONE
 * 13:35:23 <&RHExcelion> updating airtime should have an option to use local time
13:37:08 <&RHExcelion> or not local time
13:37:12 <&RHExcelion> crunchyroll standard time
 */
/*
CREATE TABLE shows(
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  series VARCHAR(127) NOT NULL,
  airtime DATETIME DEFAULT 0,
  current_ep SMALLINT unsigned DEFAULT 0,
  total_eps SMALLINT unsigned DEFAULT 0,
  blog_link VARCHAR(255),
  status TINYINT unsigned DEFAULT 0,
  translator VARCHAR(63),
  tl_status TINYINT unsigned DEFAULT 0,
  editor VARCHAR(63),
  ed_status TINYINT unsigned DEFAULT 0,
  typesetter VARCHAR(63),
  ts_status TINYINT unsigned DEFAULT 0,
  timer VARCHAR(63),
  tm_status TINYINT unsigned DEFAULT 0,
  channel VARCHAR(63)
);
 */
require 'Slim/Slim.php';
require 'NotORM.php';

date_default_timezone_set('Asia/Tokyo');

$pdo = new PDO('mysql:host=localhost;dbname=commie', 'commie', 'Hammer und Sichel');
$db = new NotORM($pdo);

\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim(array('mode' => 'development'));
$app->key = '3e5e0eb1209cf522b224989371da43015aa81258';
$app->setName('commie_shows');
$app->add(new \Slim\Middleware\ContentTypes);
$app->configureMode('production', function () use ($app) {
    $app->config(array(
        'log.enable' => true,
        'debug' => false
    ));
});
$app->configureMode('development', function () use ($app) {
    $app->config(array(
        'log.enable' => false,
        'debug' => true,
        'cookies.lifetime' => '15 minutes'
    ));
});
/*\Slim\Route::setDefaultConditions(array(
    'id' => '[0-9]+',
    'current_ep' => '[0-9]+',
    'total_eps' => '[0-9]+',
    'blog_link' => 'http://.*',
    'tl_status' => '(0|1)',
    'ed_status' => '(0|1)',
    'ts_status' => '(0|1)',
    'tm_status' => '(0|1)',
));*/

// JSON-encoded error to be called from within the application
function jerror($message) {
    global $app;
    echo json_encode(array('status' => false, 'message' => $message));
    $app->stop();
};

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

// GET route
$app->get('/show/:id', function ($id) use ($app, $db) {
    $app->response()->header('Content-Type', 'application/json');
    $data = $db->shows()->where('id', $id);
    if ($show = $data->fetch()) {
        echo json_encode(array('results' => array(
            'id' => $show['id'],
            'series' => $show['series'],
            'airtime' => strtotime($show['airtime']),
            'current_ep' => $show['current_ep'],
            'total_eps' => $show['total_eps'],
            'blog_link' => $show['blog_link'],
            'status' => $show['status'],
            'translator' => $show['translator'],
            'tl_status' => $show['tl_status'],
            'editor' => $show['editor'],
            'ed_status' => $show['ed_status'],
            'typesetter' => $show['typesetter'],
            'ts_status' => $show['ts_status'],
            'timer' => $show['timer'],
            'tm_status' => $show['tm_status'],
            'channel' => $show['channel'],
            'updated' => strtotime($show['updated'])+32400
        )));
    } else { echo jerror("Show ID $id does not exist"); }
})->name('get_show')->conditions(array('id' => '[0-9]+'));

$app->get('/shows', function () use ($app, $db) {
    $shows = array('results' => '');
    foreach ($db->shows() as $show) {
        $shows['results'][] = array(
            'id' => $show['id'],
            'series' => htmlspecialchars_decode($show['series'], ENT_QUOTES),
            'airtime' => strtotime($show['airtime']),
            'current_ep' => $show['current_ep'],
            'total_eps' => $show['total_eps'],
            'blog_link' => $show['blog_link'],
            'status' => $show['status'],
            'translator' => $show['translator'],
            'tl_status' => $show['tl_status'],
            'editor' => $show['editor'],
            'ed_status' => $show['ed_status'],
            'typesetter' => $show['typesetter'],
            'ts_status' => $show['ts_status'],
            'timer' => $show['timer'],
            'tm_status' => $show['tm_status'],
            'channel' => $show['channel'],
            'updated' => strtotime($show['updated'])+32400
        );
    }
    $app->response()->header('Content-Type', 'application/json');
    echo json_encode($shows);
});

$app->get('/completed_shows', function () use ($app, $db) {
    $shows = array('results' => '');
    foreach ($db->shows()->where('status',1) as $show) {
        $shows['results'][] = array(
            'id' => $show['id'],
            'series' => htmlspecialchars_decode($show['series'], ENT_QUOTES),
            'airtime' => strtotime($show['airtime']),
            'current_ep' => $show['current_ep'],
            'total_eps' => $show['total_eps'],
            'blog_link' => $show['blog_link'],
            'status' => $show['status'],
            'translator' => $show['translator'],
            'tl_status' => $show['tl_status'],
            'editor' => $show['editor'],
            'ed_status' => $show['ed_status'],
            'typesetter' => $show['typesetter'],
            'ts_status' => $show['ts_status'],
            'timer' => $show['timer'],
            'tm_status' => $show['tm_status'],
            'channel' => $show['channel'],
            'updated' => strtotime($show['updated'])+32400
        );
    }
    $app->response()->header('Content-Type', 'application/json');
    echo json_encode($shows);
});

$app->get('/incomplete_shows', function () use ($app, $db) {
    $shows = array('results' => '');
    foreach ($db->shows()->where('status != 1') as $show) {
        $shows['results'][] = array(
            'id' => $show['id'],
            'series' => htmlspecialchars_decode($show['series'], ENT_QUOTES),
            'airtime' => strtotime($show['airtime']),
            'current_ep' => $show['current_ep'],
            'total_eps' => $show['total_eps'],
            'blog_link' => $show['blog_link'],
            'status' => $show['status'],
            'translator' => $show['translator'],
            'tl_status' => $show['tl_status'],
            'editor' => $show['editor'],
            'ed_status' => $show['ed_status'],
            'typesetter' => $show['typesetter'],
            'ts_status' => $show['ts_status'],
            'timer' => $show['timer'],
            'tm_status' => $show['tm_status'],
            'channel' => $show['channel'],
            'updated' => strtotime($show['updated'])+32400
        );
    }
    $app->response()->header('Content-Type', 'application/json');
    echo json_encode($shows);
});

$app->get('/show/:series/substatus', function ($series) use ($app, $db) {
    $app->response()->header('Content-Type', 'application/json');
    $data = $db->shows()->where('series', $series);
    if ($show = $data->fetch()) {
        $now = strtotime(date('Y-m-d H:i:s'));
        $air = strtotime($show['airtime']);
        if ($show['current_ep'] >= $show['total_eps']) { $stalled = $v = 'completed'; }
        elseif ($air > $now) { $stalled = 'broadcaster'; $v = $show['channel']; }
        elseif ($show['tl_status'] == 0) { $stalled = 'translator'; $v = $show['translator']; }
        elseif ($show['ed_status'] == 0) { $stalled = 'editor'; $v = $show['editor']; }
        elseif ($show['tm_status'] == 0) { $stalled = 'timer'; $v = $show['timer']; }
        elseif ($show['ts_status'] == 0) { $stalled = 'typesetter'; $v = $show['typesetter']; }
        echo json_encode(array('results' => array(
            'id' => $show['id'],
            'position' => $stalled,
            'value' => $v,
            'updated' => strtotime($show['updated'])+32400
        )));
    } else { jerror("Show '$series' does not exist."); }
})->name('get_show_substatus');

$app->get('/show/:series/:position', function ($series, $position) use ($app, $db) {
    $app->response()->header('Content-Type', 'application/json');
    $data = $db->shows()->where('series', $series);
    if ($show = $data->fetch()) {
        $positions = array(
            'translator' => array($show['translator'], $show['tl_status']),
            'editor' => array($show['editor'], $show['ed_status']),
            'typesetter' => array($show['typesetter'], $show['ts_status']),
            'timer' => array($show['timer'], $show['tm_status'])
        );
        if (count($positions[$position]) == 2) {
            echo json_encode(array('results' => array(
                'id' => $show['id'],
                'position' => $position,
                'name' => $positions[$position][0],
                'status' => $positions[$position][1]
            )));
        }
        else { jerror("Position '$position' does not exist."); }
    } else { jerror("Show '$series' does not exist."); }
})->name('get_show_position');

$app->post('/show/new', function () use ($app, $db) {
    $r = $app->request()->getBody();
    $app->response()->header('Content-Type', 'application/json');
    if (!array_key_exists('key', $r)) { jerror('You did not specify the API key.'); }
    if ($r['key'] != $app->key) { jerror('Unauthorized API key.'); }
    if (!array_key_exists('data', $r)) { jerror('You did not specify any information for the new show.'); }
    $data = $r['data'];
    foreach ($data as $f=>&$v) { $v = htmlspecialchars($v, ENT_QUOTES); }
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
    if (strlen($show['series']) < 1) { jerror('You\'ll want to enter a series name.'); }
    elseif (!preg_match('/^[0-9]{4}-(1[0-2]|0?[1-9])-([1-2][0-9]|3(0|1)|[1-9]) (1?[0-9]|2[0-3]):[0-5][0-9]$/', $show['airtime'])) {
        jerror('Value given for airtime must be a valid date with format YYYY-m-d H:MM');
    }
    elseif (!is_numeric($show['current_ep'])) { jerror('Value given for current_ep is not numeric.'); }
    elseif (!is_numeric($show['total_eps'])) { jerror('Value given for total_eps is not numeric.'); }
    if ($show['status']=='') { $show['status'] = 0; }
    $result = $db->shows()->insert($show);
    echo json_encode(array(
        'status' => (bool)$result,
        'message' => 'Show added.'
    ));
})->name('new_show');

$app->post('/show/delete', function () use ($app, $db) {
    $r = $app->request()->getBody();
    $app->response()->header('Content-Type', 'application/json');
    if (!array_key_exists('key', $r)) { jerror('You did not specify the API key.'); }
    if ($r['key'] != $app->key) { jerror('Unauthorized API key.'); }
    if (!array_key_exists('id', $r) && !array_key_exists('series', $r)) {
        jerror('You need to specify either the series name or ID to delete it.');
    } else {
        if ($where_value=$r['id']) { $where = 'id'; }
        elseif ($where_value=$r['series']) { $where = 'series'; }
        $data = $db->shows()->where($where, $where_value);
        if (!$show = $data->fetch()) { jerror('Show does not exist.'); }
        $result = $show->delete();
        echo json_encode(array(
            'status' => (bool)$result,
            'message' => 'Show deleted.'
        ));
    }
})->name('delete_show');

$app->post('/show/update', function () use ($app, $db) {
    $r = $app->request()->getBody();
    $app->response()->header('Content-Type', 'application/json');
    if (!array_key_exists('key', $r)) { jerror('You did not specify the API key.'); }
    if ($r['key'] != $app->key) { jerror('Unauthorized API key.'); }
    if (!array_key_exists('method', $r)) { jerror('You did not specify a method.'); }
    if (!array_key_exists('id', $r) && !array_key_exists('series', $r)) {
        jerror('You need to specify either the series name or ID to update it.');
    } else {
        if ($where_value=$r['id']) { $where = 'id'; }
        elseif ($where_value=$r['series']) { $where = 'series'; }
        $data = $db->shows()->where($where, $where_value);
        if (!$show = $data->fetch()) { jerror('Show does not exist.'); }
        $columns = array_keys(iterator_to_array($show));
    }
    switch ($r['method']) {
        case 'change_everything':
            if (!array_key_exists('data', $r)) { jerror('You did not specify any information for this show.'); }
            $data = $r['data'];
            foreach ($data as $f=>&$v) {
                $v = htmlspecialchars($v, ENT_QUOTES);
                if(!in_array("$f", $columns)) { jerror("Key '$f is invalid."); }
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
            if (strlen($changes['series']) < 1) { jerror('You\'ll want to enter a series name.'); }
            elseif (!preg_match('/^[0-9]{4}-(1[0-2]|0?[1-9])-([1-2][0-9]|3(0|1)|[1-9]) (1?[0-9]|2[0-3]):[0-5][0-9]$/', $changes['airtime'])) {
                jerror('Value given for airtime must be a valid date with format YYYY-m-d H:MM');
            }
            elseif (!is_numeric($changes['current_ep'])) { jerror('Value given for current_ep is not numeric.'); }
            elseif (!is_numeric($changes['total_eps'])) { jerror('Value given for total_eps is not numeric.'); }
            elseif (!in_array($changes['tl_status'], array(0,1))) { $changes['tl_status'] = $show['tl_status']; }
            elseif (!in_array($changes['ed_status'], array(0,1))) { $changes['ed_status'] = $show['ed_status']; }
            elseif (!in_array($changes['ts_status'], array(0,1))) { $changes['ts_status'] = $show['ts_status']; }
            elseif (!in_array($changes['tm_status'], array(0,1))) { $changes['tm_status'] = $show['tm_status']; }
            elseif (!in_array($changes['status'], array(-1,0,1))) {
                if ($changes['current_ep'] == $changes['total_eps']) { $changes['status'] = 1; }
                else { $changes['status'] = $show['status']; }
            }
            $result = $show->update($changes);
            echo json_encode(array(
                'status' => (bool)$result,
                'message' => 'Show updated. (if nothing changed, this will show up as an error.)'
            ));
            break;
        case 'position_status':
            if (!array_key_exists('position', $r)) { jerror('You did not specify a position.'); }
            if (!array_key_exists('value', $r)) { jerror('You did not specify a status.'); }
            $v = $r['value'];
            if ($v!=1 && $v!=0) { jerror('Status should either be 0 or 1.'); }
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
                    jerror("Position '" . $r['position'] . "' does not exist.");
                    break;
            }
            $total += $v;
            if ($total==4) {
                $result = next_episode($show);
                echo json_encode(array(
                    'status' => (bool)$result,
                    'message' => 'Show completed and counters reset.'
                ));
            } else {
                echo json_encode(array(
                    'status' => (bool)$result,
                    'message' => 'Show updated.'
                ));
            }
            break;
        case 'next_episode':
            $result = next_episode($show);
            echo json_encode(array(
                'status' => (bool)$result,
                'message' => 'Episode count updated and staff counters reset.'
            ));
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
            echo json_encode(array(
                'status' => (bool)$result,
                'message' => 'Episode count decremented and staff counters reset.'
            ));
            break;
        case 'current_episode':
            if (!array_key_exists('value', $r)) { jerror('You did not specify a new value.'); }
            $result = $show->update(array('current_ep' => $r['value']));
            echo json_encode(array(
                'status' => (bool)$result,
                'message' => 'Current episode count updated.'
            ));
            break;
        case 'total_episodes':
            if (!array_key_exists('value', $r)) { jerror('You did not specify a new value.'); }
            $result = $show->update(array('total_eps' => $r['value']));
            echo json_encode(array(
                'status' => (bool)$result,
                'message' => 'Total episode count updated.'
            ));
            break;
        case 'position':
            if (!array_key_exists('position', $r)) { jerror('You did not specify a position.'); }
            if (!array_key_exists('value', $r)) { jerror('You did not specify a name.'); }
            $positions = array('translator', 'editor', 'typesetter', 'timer');
            if (!in_array($r['position'], $positions)) { jerror('Position does not exist.'); }
            $result = $show->update(array($r['position'] => $r['value']));
            echo json_encode(array(
                'status' => (bool)$result,
                'message' => 'Position updated (' . $r['position'] . ' is now ' . $r['value'] . ').'
            ));
            break;
        default:
            jerror("Specified method '" . $r['method'] . "' does not exist.");
            break;
    }
})->name('update_show');

$app->run();
