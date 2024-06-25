<?php // Imagery Offset Database Web Interface. Written by Ilya Zverev, licenses WTFPL.
require __DIR__ . '/../vendor/autoload.php';

$redirect = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['PHP_SELF']), '/\\').'/';
function oauth_make() {
  global $redirect;
  return new \JBelien\OAuth2\Client\Provider\OpenStreetMap([
      'clientId'     => CLIENT_ID,
      'clientSecret' => CLIENT_SECRET,
      'redirectUri'  => $redirect.'oauth',
      'dev'          => false
  ]);
}

// Set session parameters.
$session_lifetime = 365 * 24 * 3600; // a year
session_set_cookie_params($session_lifetime);
session_start([
  'cookie_lifetime' => $session_lifetime,
  'use_only_cookies' => true,
  'use_strict_mode' => true,
]);

header('Content-type: text/html; charset=utf-8');
$html = true;
$user = isset($_SESSION['osm_user']) ? $_SESSION['osm_user'] : DEFAULT_USER;
$is_admin = in_array($user, $administrators);

$action = req('action', '');
if( $action == 'login' ) {
  $oauth = oauth_make();
  $options = ['scope' => 'read_prefs'];
  $auth_url = $oauth->getAuthorizationUrl($options);
  $_SESSION['oauth2state'] = $oauth->getState();
  header('Location: '.$auth_url);
  exit;
} elseif( $action == 'oauth' ) {
	if(empty($_GET['code'])) {
		echo "Error: there is no OAuth code.";
	} elseif(empty($_SESSION['oauth2state'])) {
		echo "Error: there is no OAuth state.";
  } elseif(empty($_GET['state']) || $_GET['state'] != $_SESSION['oauth2state']) {
    echo "Error: invalid state.";
	} else {
    unset($_SESSION['oauth2state']);
		try {
      $oauth = oauth_make();
      $accessToken = $oauth->getAccessToken(
        'authorization_code', ['code' => $_GET['code']]
      );
      $resourceOwner = $oauth->getResourceOwner($accessToken);
      $_SESSION['osm_user'] = $resourceOwner->getDisplayName();

      // Переход на станицу успеха
      header("Location: $redirect");
    } catch (Exception $e) {
			echo("<pre>Exception:\n");
			print_r($e);
			echo '</pre>';
    }
	}
  exit;
} elseif( $action == 'logout' ) {
    unset($_SESSION['osm_user']);
    header("Location: $redirect");
} elseif( $action == 'createdb' && $is_admin ) {
    create_table();
    header("Location: $redirect");
} elseif( $action == 'web' && $user ) {
    $admact = req('webact');
    if( $admact == 'report' ) {
        report_impl(req('offsetid'), req('message'));
    } elseif( $admact == 'unflag' && $is_admin ) {
        report_impl(req('offsetid', $_REQUEST['offsetids']));
    } elseif( $admact == 'deprecate' ) {
        deprecate_impl(req('offsetid'), $user, req('message'));
    } elseif( $admact == 'undeprecate' && $is_admin ) {
        undeprecate(req('offsetid', $_REQUEST['offsetids']));
    } elseif( $admact == 'delete' && $is_admin ) {
        error('Deleting is disabled for now. Please contact an admin if you really need to delete something.');
        delete_impl(req('offsetid', $_REQUEST['offsetids']));
    } else
        error('Unknown web action: '.$admact);
    header("Location: $redirect");
} elseif( strlen($action) > 0 ) {
    header("Location: $redirect");
}

?><!doctype html><html lang="en">
<head>
<title>Imagery Offset Database</title>
<meta charset="utf-8">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.8.0/dist/leaflet.css" integrity="sha512-hoalWLoI8r4UszCkZ5kL8vayOGVae1oxXe/2A4AO6J9+580uKHDO3JdHb7NzwwzK5xr/Fs0W40kiNHxM9vyTtQ==" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.8.0/dist/leaflet.js" integrity="sha512-BB3hKbKWOc9Ez/TAwyWxNXeoV9c1v6FIeYiBieIWkpLjauysF18NzgR1MBNBXf8/KABdlkX68nAhlwcDFLGPCQ==" crossorigin=""></script>
<script>
var map, marker, lastdiv, infobox;
function init() {
    map = L.map('regionmap').setView([54.998, -7.332], 9);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors',
        maxZoom: 15, minZoom: 6
    }).addTo(map);
    marker = new L.LayerGroup().addTo(map);

    var offsetDivs = document.getElementsByClassName('offset');
    var calDivs = document.getElementsByClassName('calibration');
    for( var i = 0; i < offsetDivs.length + calDivs.length; i++ ) {
        var div = i < offsetDivs.length ? offsetDivs[i] : calDivs[i - offsetDivs.length];
        div.onmouseenter = function() {
            updateOffset(this);
        };
    }
    document.onclick = updateInfobox;
}

function updateOffset(div) {
    var lat = 1*div.getAttribute('lat');
    var lon = 1*div.getAttribute('lon');
    if( lat < -85 || lat > 85 || lon < -180 || lon > 180 ) {
        document.getElementById('worldpos').style.visibility = 'hidden';
        return;
    }

    // leaflet map marker
    var ll = new L.LatLng(div.getAttribute('lat'), div.getAttribute('lon'));
    map.setView(ll, 9);
    marker.clearLayers();
    new L.Circle(ll, 10000, {clickable: false, stroke: false}).addTo(marker);
    new L.Circle(ll, 1000, {clickable: false, stroke: false}).addTo(marker);

    // world pos
    var x = (lon + 180.0) / 360 * 512;
    // the following formula was taken from OSM wiki
    var y = (1-Math.log(Math.tan(lat*Math.PI/180) + 1/Math.cos(lat*Math.PI/180))/Math.PI) * 256;
    var dx = Math.floor(x / 256);
    var dy = Math.floor(y / 256);
    document.getElementById('worldmap').style.background = 'url(https://tile.openstreetmap.org/1/' + dx + '/' + dy + '.png)';
    document.getElementById('worldpos').style.left = (Math.round(x) - 13 - dx*256) + 'px';
    document.getElementById('worldpos').style.top = (Math.round(y) - 41 - dy*256) + 'px';
    document.getElementById('worldpos').style.visibility = 'inherit';

    // background
    if( lastdiv ) lastdiv.style.backgroundColor = 'inherit';
    div.style.backgroundColor = '#eee';
    lastdiv = div;
}

function updateInfobox(e) {
    hideInfo();
    // show relevant infobox
    var t = e.target.id ? e.target : e.target.parentNode;
    if( t.id && e.pageX > 60 ) {
        infobox = document.getElementById('i' + t.id.substring(1));
        infobox.style.display = 'block';
        infobox.style.left = (e.pageX + 10) + 'px';
        infobox.style.top = (e.pageY + 10) + 'px';
    }
}

function hideInfo() {
    if( infobox ) infobox.style.display = 'none';
}

function selectall() {
    process_check(function(c) { c.checked = true; });
}

function invertall() {
    process_check(function(c) { c.checked = !c.checked; });
}

function process_check(f) {
    var cbs = document.getElementsByTagName('input');
    for( var i = 0; i < cbs.length; i++ ) {
        if( cbs[i].type == 'checkbox' )
            f(cbs[i]);
    }
}

function count_check() {
    var cnt = 0;
    var cbs = document.getElementsByTagName('input');
    for( var i = 0; i < cbs.length; i++ ) {
        if( cbs[i].type == 'checkbox' && cbs[i].checked )
            cnt++;
    }
    return cnt;
}

function submitAction(id, action, message, isprompt) {
    if( id == '' && !count_check() )
        return false;
    if( isprompt ) {
        var msg = window.prompt(message);
        if( !msg || msg.length < 2 )
            return false;
        form.message.value = msg;
    } else {
        if( !window.confirm(message) )
            return false;
    }
    form.offsetid.value = id;
    form.webact.value = action;
    form.submit();
    return false;
}
</script>
<style>
body {
    font-family: Verdana, sans-serif;
    font-size: 12pt;
    overflow-x: hidden;
    margin: 10;
}
h1 {
/*    font-family: "Impact", "Gill Sans", "Arial Narrow", sans-serif;*/
    padding: 30px 40px;
    font-size: 24pt;
    font-weight: normal;
    color: darkblue;
    margin: -10 -10 0;
    text-align: center;
}
h1 a {
    text-decoration: none;
    color: white;
}
#menu {
    list-style-type: none;
    background: lightblue;
    margin: 0 -10;
    padding: 0 0;
}
#menu li {
    display: inline-block;
}
#menu li a, #menu li a:visited {
    color: darkblue;
    display: inline-block;
    padding: 5px 20px;
}
#menu li a:hover {
    background: white;
}
a, a:visited {
    color: blue;
}
h2 {
    padding: 1em 0 0;
    margin-right: 280px;
    border-bottom: 1px solid darkblue;
    margin-bottom: 10px;
    font-size: 18pt;
    font-weight: normal;
}
.offset, .calibration {
    margin-right: 280px;
    padding: 0.5em 0;
    padding-left: 24px;
    background-repeat: no-repeat;
    background-position: left center;
}
.description {
    font-style: italic;
}
.deprecated {
    margin-top: 1em;
}
.deprecated + .deprecated, h2 + .deprecated {
    margin-top: 0;
}
.offset { background-image: url(lib/images/ioffset.png); }
.calibration { background-image: url(lib/images/calibrat.png); }
.offset.deprecated { background-image: url(lib/images/ioffsetd.png); }
.calibration.deprecated { background-image: url(lib/images/calibratd.png); }
.map {
    position: fixed;
    right: 20px;
    width: 256px;
    height: 256px;
}
#worldmap {
    background: url(https://tile.openstreetmap.org/0/0/0.png);
    bottom: 270px;
}
#regionmap {
    bottom: 10px;
}
#worldpos {
    position: relative;
    background: url(lib/images/marker-icon.png);
    width: 25px;
    height: 41px;
    visibility: hidden;
}
.entries {
    position: absolute;
    right: 0;
    color: black;
    font-style: italic;
    text-align: right;
    padding: 5px 20px;
}
.infobox {
    display: none;
    position: absolute;
    width: 400px;
    font-size: 10pt;
    background: #fff;
    border: 1px solid gray;
    padding: 10px 10px;
    z-index: 1001;
}
form {
    margin: 0;
    padding: 0;
}
#nextpage {
    margin: 1em 0;
}
.actions {
    margin-top: 1em;
}
.filters {
    margin-bottom: 1em;
}
</style>
</head>
<?php
$res = $db->query('select 1 from iodb');
if( $res ) {
    $has_table = true;
    $res->free();
} else
    $has_table = false;

$before = req('before', '');
if( !preg_match('/^\d{4}-\d\d-\d\d$/', $before) ) $before = '';
$select = '*, X(coord) as lon, Y(coord) as lat, X(im_coord) as imlon, Y(im_coord) as imlat, GeometryType(calibration) as geotype';
$where = '';
if( isset($_REQUEST['filter']) ) {
    $filter = req('filter');
    if( $is_admin && $filter == 'flagged' ) {
        $where = 'flagged is not null';
    } elseif (isset($_REQUEST['fid']) ) {
        $fid = req('fid');
        validate_num($fid, 'filter id', false);
        $result = $db->query("select $select, HEX(ip) as h_ip, HEX(abandon_ip) as h_aip from iodb where offset_id = $fid");
        if( $result ) {
            $offset = $result->fetch_assoc();
            $result->free();
            if( $filter == 'author' || $filter == 'author0' ) {
                $author = htmlspecialchars(!isset($offset['abandon_date']) || $filter == 'author0' ? $offset['author'] : $offset['abandon_author']);
                $where = "(author = '$author' or abandon_author = '$author')";
            } elseif( ($filter == 'ip' || $filter == 'ip0') && $is_admin ) {
                $ip = !isset($offset['abandon_date']) || $filter == 'ip0' ? $offset['h_ip'] : $offset['h_aip'];
                $where = "(ip = 0x$ip or abandon_ip = 0x$ip)";
            } elseif( $filter == 'area' ) {
                $where = region_where_clause($offset['lat'], $offset['lon'], MAX_RADIUS);
            }
        }
    }
    if( strlen($where) > 0 )
        $where = 'and '.$where;
}
?>
<body onload="javascript:init();">
<h1><?php if( strlen($before) > 0 || strlen($where) > 0 ) { ?><a href="/">Imagery Offset Database</a><?php } else { ?>Imagery Offset Database<?php } ?></h1>
<?php
$count = $has_table ? request_one('select count(1) from iodb where 1=1 '.$where) : 0;
if( $count === null ) $count = 0;
?>
<ul id="menu">
    <li><a href="https://wiki.openstreetmap.org/wiki/Imagery_Offset_Database">What is this?</a></li>
    <li><a href="https://wiki.openstreetmap.org/wiki/Imagery_Offset_Database/Quick_Start">JOSM Tutorial</a></li>
    <li><a href="https://wiki.openstreetmap.org/wiki/Imagery_Offset_Database/API">API</a></li>
    <li><a href="/map">The Map</a></li>
    <li><a href="/download">The Data</a></li>
    <li><?php if(!$user) { ?><a href="/login">Login</a><?php } else { ?><a href="/logout">Logout</a><?php } ?></li>
    <li class="entries"><?=$count ?> entries</li>
</ul>
<?php if( $is_admin && !$has_table ) { ?>
<p>There seems to be no <tt>iodb</tt> table in the database. <a href="createdb">Create it</a>.</p>
<?php exit; } ?>
<?php if( strlen($before) == 0 && false ): ?>
<p>This website is in development. Sorry for any missing functionality.</p>
<?php endif; ?>
<div class="map" id="worldmap"><div id="worldpos"></div><div id="maplink"></div></div>
<div class="map" id="regionmap"></div>
<?php if( $user ) { ?>
<form name="form" action="web" method="post">
<input type="hidden" name="webact" value="">
<input type="hidden" name="offsetid" value="">
<input type="hidden" name="message" value="">
<?php } ?>
<?php if( $is_admin ): ?>
<p><a href="" onclick="javascript:selectall(); return false;">Select all</a>, <a href="" onclick="javascript:invertall(); return false;">invert</a>. <a href="?filter=flagged">Show flagged</a>.
Things to do with selected entires:
<input type="button" value="Unflag" onclick="javascript:submitAction('', 'unflag', 'Unflag '+count_check()+' selected offsets?', false);">
<input type="button" value="Undeprecate" onclick="javascript:submitAction('', 'undeprecate', 'Remove deprecation record for '+count_check()+' selected offsets?', false)">
<input type="button" value="Delete" onclick="javascript:submitAction('', 'delete', 'Delete '+count_check()+' selected offsets? This operation is irreversible!', false);">
</p>
<?php endif; ?>
<?php
$cnt = 0;
$days = 0;
$print_time_format = 'jS \o\f F, Y';
$time = strlen($before) == 0 ? time() : strtotime($before) - 3600*24;

while( $cnt < 100 && $days < 10 ) {
    $time_shown = false;
    $timestr = date('Y-m-d', $time);
    $result = $db->query("select $select from iodb where abandon_date is null and offset_date = '$timestr' $where order by offset_id desc limit 500");
    if( !$result )
        error('Error requesting data: '.$db->error);
    while( $offset = $result->fetch_assoc() ) {
        print_offset($offset);
        $cnt++;
    }
    $result->free();

    $result = $db->query("select $select from iodb where abandon_date = '$timestr' $where order by offset_id desc");
    if( !$result )
        error('Error requesting data: '.$db->error);
    while( $offset = $result->fetch_assoc() ) {
        print_offset($offset);
        $cnt++;
    }
    $result->free();
    $time -= 24*3600;
    $days++;
}
?>
<?php if( $user ) { ?></form><?php } ?>
<?php
    $filterq = isset($_REQUEST['filter']) ? '&filter='.$_REQUEST['filter'] : '';
    if( isset($_REQUEST['fid'] ) )
        $filterq .= '&fid='.$_REQUEST['fid'];
?>
    <div id="nextpage"><a href="?before=<?=date('Y-m-d', $time + 24*3600).$filterq ?>">Next page</a></div>
</body>
</html>
<?php
function print_offset( $offset ) {
    global $time_shown, $time, $is_admin, $before, $user;
    if( !$time_shown ) {
        print '    <h2>'.date('jS \o\f F', $time)."</h2>\n";
        $time_shown = true;
    }
    $id = $offset['offset_id'];
    $deprecated = isset($offset['abandon_date']);
    $class = (isset($offset['imagery']) ? 'offset':'calibration').($deprecated ? ' deprecated':'');
    $description = htmlspecialchars($offset[$deprecated ? 'abandon_reason' : 'description']);
    $author = htmlspecialchars($offset[$deprecated ? 'abandon_author' : 'author']);
    if( !isset($offset['location']) ) {
        $country = '';
    } else {
        $loc_parts = explode(',', $offset['location']);
        $clp = count($loc_parts) - 1;
        $country = trim($loc_parts[$clp > 0 && trim($loc_parts[$clp]) == 'European Union' ? $clp - 1 : $clp]);
        if( $country ) $country = ' in '.$country;
    }
?>
    <div class="<?=$class ?>" id="o<?=$id ?>" lat="<?=$offset['lat'] ?>" lon="<?=$offset['lon'] ?>">
        <?php if( $is_admin ) { ?><input type="checkbox" name="offsetids[]" value="<?=$id ?>"><?php } ?>
        <?php if( $deprecated ) { print '<s>'.$offset['author'].'</s> '; } ?><?=$author.$country ?>: <span class="description"><?=$description ?></span><?php if( $is_admin && isset($offset['flagged']) ) { ?>&nbsp;<img src="lib/images/flag.png" style="vertical-align: middle;"><?php } ?>
        <div class="infobox" id="i<?=$id ?>">
<?php
    // initialize filters and actions (to unclutter js code)
    $filters = array();
    $beforef = ''; //strlen($before) > 0 ? "&before=$before" : '';
    $filters[] = array('author', 'author');
    if( $is_admin )
        $filters[] = array('IP', 'ip');
    if( isset($offset['abandon_date']) )
        $filters[] = array('orig. author', 'author0');
    if( $is_admin && isset($offset['abandon_date']) )
        $filters[] = array('orig. IP', 'ip0');
    $filters[] = array('area', 'area');

    for( $i = 0; $i < count($filters); $i++ )
        $filters[$i] = '<a href="/?filter='.$filters[$i][1].'&fid='.$id.$beforef.'">'.$filters[$i][0].'</a>';

    $actions = array();
    if( !isset($offset['flagged']) )
        $actions[] = array('Report', "submitAction($id, 'report', 'Why are you reporting this offset to moderators?', true)");
    if( !isset($offset['abandon_date']) )
        $actions[] = array('Deprecate', "submitAction($id, 'deprecate', 'Please do not forget to upload fresh offset!\\nWhy are you deprecating this one?', true)");
    if( $is_admin ) {
        if( isset($offset['flagged']) )
            $actions[] = array('Unflag', "submitAction($id, 'unflag', 'Unflag this offset?', false)");
        if( isset($offset['abandon_date']) )
            $actions[] = array('Undeprecate', "submitAction($id, 'undeprecate', 'You are going to clear deprecation flag on the offset. Proceed?', false)");
        $actions[] = array('Delete', "submitAction($id, 'delete', 'You are going to IRREVERSIBLY delete this offset. Proceed?', false)");
    }

    for( $i = 0; $i < count($actions); $i++ )
        $actions[$i] = '<a href="" onclick="javascript:'.$actions[$i][1].';return false;">'.$actions[$i][0].'</a>';
    $actions[] = '<a href="/map#lon='.$offset['lon'].'&lat='.$offset['lat'].'&zoom=10">Show on map</a>';
    $actions[] = '<a href="http://127.0.0.1:8111/load_and_zoom?left='.($offset['lon']-0.005).'&right='.($offset['lon']+0.005).'&bottom='.($offset['lat']-0.005).'&top='.($offset['lat']+0.005).'" target="_blank">Open area in JOSM</a>';
?>
            <div class="filters">Filter by <?= implode(', ', $filters) ?></div>
            <div class="offsetinfo">
                <?php if( isset($offset['location']) ) { ?><?=htmlspecialchars($offset['location']) ?><br><br><?php } ?>
                <b><?php
    if( isset($offset['imagery']) ) {
        $h_R = 6378135;
        $h_slat = sin(($offset['imlat'] - $offset['lat']) * M_PI / 360.0);
        $h_slon = sin(($offset['imlon'] - $offset['lon']) * M_PI / 360.0);
        $h_lat = $offset['lat'] * M_PI / 180.0;
        $h_ilat = $offset['imlat'] * M_PI / 180.0;
        $dist = 2 * $h_R * asin(sqrt( $h_slat*$h_slat + cos($h_lat)*cos($h_ilat)*$h_slon*$h_slon ));
        print 'An imagery offset of '.sprintf('%1.1f m', $dist);
    } else {
        print 'A calibration ';
        if( $offset['geotype'] == 'POINT' )
            print 'point';
        elseif( $offset['geotype'] == 'LINESTRING' )
            print 'path';
        elseif( $offset['geotype'] == 'POLYGON' )
            print 'polygon';
        else
            print 'geometry';
    }
?></b><br>
                <?php if( isset($offset['imagery']) ) { ?>Imagery: <tt><?=htmlspecialchars($offset['imagery']) ?></tt><?php if( $offset['min_zoom'] > 0 || $offset['max_zoom'] < 29 ) { ?> [z<?=$offset['min_zoom'] ?>-z<?=$offset['max_zoom'] ?>]<?php } ?><br><?php } ?>
                Created by <?=htmlspecialchars($offset['author']) ?> on <?=$offset['offset_date'] ?><br>
                <i><?=htmlspecialchars($offset['description']) ?></i>
<?php if( isset($offset['abandon_date']) ) { ?><br><br>
                Deprecated by <?=htmlspecialchars($offset['abandon_author']) ?> on <?=$offset['abandon_date'] ?><br>
                <i><?=htmlspecialchars($offset['abandon_reason']) ?></i>
<?php } ?>
<?php if( isset($offset['flagged']) ) { ?><br><br>
                <span style="color: red;">This entry has been reported.</span>
                <?php if( $is_admin ) { ?><br><i><?=htmlspecialchars($offset['flagged']) ?></i><?php } ?>
<?php } ?>
            </div>
            <?php if( $user ) { ?><div class="actions"><?= implode(', ', $actions) ?></div><?php } ?>
        </div>
    </div>
<?php 
}
?>
