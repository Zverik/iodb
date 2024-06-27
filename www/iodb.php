<?php // Imagery Offset and Calibration Object Database. Written by Ilya Zverev, licenses WTFPL.
require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *'); // CORS

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_DATABASE);
if( $db->connect_errno )
    die('Cannot connect to database: ('.$db->connect_errno.') '.$db->connect_error);
$db->set_charset('utf8');

if( PHP_SAPI == 'cli' ) {
    db_dump();
    exit;
}

if( !isset($_REQUEST['action']) ) {
    require('iodb-web.php');
    exit;
}

$action = $_REQUEST['action'];

if( $action == 'get' ) {
    do_get();
} elseif( $action == 'store' ) {
    post();
} elseif( $action == 'deprecate' ) {
    deprecate();
} elseif( $action == 'report' ) {
    report();
} elseif( $action == 'all' ) {
    db_dump_passthru();
    exit;
} else {
    // the rest of actions could be related to web
    require('iodb-web.php');
}

// -----------------------------------------------------------------------------

// Check query parameter and return either it or the default value, or raise error if there's no default.
function req( $param, $default = NULL ) {
    if( !isset($_REQUEST[$param]) || strlen($_REQUEST[$param]) == 0 ) {
        if( is_null($default) )
            error("Missing required parameter \"$param\".");
        else
            return $default;
    }
    return trim($_REQUEST[$param]);
}

// Validate float or integer number, and check for min/max.
function validate_num( $f, $name, $float, $min = NULL, $max = NULL ) {
    if( !preg_match($float ? '/^-?\d+(?:\.\d+)?$/' : '/^-?\d+$/', $f) )
        error("Parameter \"$name\" should be "
             .($float ? 'a floating-point number with a dot as a separator.' : 'an integer number'));
    if( (!is_null($min) && $f < $min) || (!is_null($max) && $f > $max) )
        error("Parameter \"$name\" should be a number between $min and $max.");
}

// Print a message (respecting output format) and shut down.
function print_msg( $type, $msg ) {
    $fmt = out_fmt();
    if( $fmt == 'json' ) {
        print_json(array($type => $msg));
    } elseif ( $fmt == 'xml' ) {
        print xmlheader()."\t<$type>".htmlspecialchars($msg)."</$type>\n".xmlfooter();
    } else { // html
        print '<b>'.htmlspecialchars($msg).'</b>';
    }
    exit;
}

// Prints an error message.
function error( $msg ) {
    print_msg('error', $msg);
}

// Prints a success message.
function message( $msg ) {
    print_msg('message', $msg);
}

// Returns requested outpue format: 'json' or 'xml'.
function out_fmt() {
    if( isset($GLOBALS['html']) ) return 'html';
    return isset($_REQUEST['format']) && strtolower($_REQUEST['format']) == 'json' ? 'json' : 'xml';
}

// Prints json respecting jsonp parameter.
function print_json( $data ) {
    header('Content-type: application/json');
    $str = json_encode($data);
    print isset($_REQUEST['jsonp']) ? $_REQUEST['jsonp']."($str);" : $str;
}

// Prints XML header.
function xmlheader() {
    header('Content-type: application/xml');
    return "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<imagery-offsets timestamp=\"".date(DATE_ISO8601)."\">\n";
}

// Prints XML footer.
function xmlfooter() {
    return "</imagery-offsets>";
}

// Checks for input parameters and calls get() or get_json() depending on requested format.
function do_get() {
    $lat = req('lat'); $lon = req('lon');
    validate_num($lat, 'lat', true, -90.0, 90.0);
    validate_num($lon, 'lon', true, -180.0, 180.0);
    $radius = req('radius', DEFAULT_RADIUS);
    validate_num($radius, 'radius', false);

    $query = get_query($lat, $lon, min($radius, MAX_RADIUS));
    if( out_fmt() == 'json' )
        get_json($query);
    else
        get($query);
}

// Queries the database and prints XML.
function get( $query ) {
    global $db;
    $result = $db->query($query);
    if( !$result )
        error("Database query failed: ".$db->error);
    print xmlheader();
    if( isset($_REQUEST['query']) )
        print "\t<query>".htmlspecialchars($query)."</query>\n";
    while( $data = $result->fetch_assoc() ) {
        $obsolete = is_null($data['abandon_date']) ? '' : ' deprecated="yes"';
        $flagged = is_null($data['flagged']) ? '' : ' flagged="yes"';
        $tag = !is_null($data['imagery']) ? 'offset' : (!is_null($data['calibration']) ? 'calibration' : 'unknown');
        print "\t<$tag id=\"".$data['offset_id']."\" lat=\"".$data['lat']."\" lon=\"".$data['lon']."\"$obsolete$flagged>\n";
        print "\t\t<author>".htmlspecialchars($data['author'])."</author>\n";
        print "\t\t<description>".htmlspecialchars($data['description'])."</description>\n";
        print "\t\t<date>".$data['offset_date']."</date>\n";
	if( !is_null($data['abandon_date']) ) {
            print "\t\t<deprecated>\n";
            print "\t\t\t<author>".htmlspecialchars($data['abandon_author'])."</author>\n";
            print "\t\t\t<reason>".htmlspecialchars($data['abandon_reason'])."</reason>\n";
            print "\t\t\t<date>".$data['abandon_date']."</date>\n";
            print "\t\t</deprecated>\n";
        }
        if( !is_null($data['imagery']) ) {
            $minzoom = is_null($data['min_zoom']) ? '' : ' min-zoom="'.$data['min_zoom'].'"';
            $maxzoom = is_null($data['max_zoom']) ? '' : ' max-zoom="'.$data['max_zoom'].'"';
            print "\t\t<imagery$minzoom$maxzoom>".htmlspecialchars($data['imagery'])."</imagery>\n";
            print "\t\t<imagery-position lat=\"".$data['imlat']."\" lon=\"".$data['imlon']."\" />\n";
        } elseif( !is_null($data['calibration']) ) {
            $geom = parse_wkt($data['cal_text']);
            print "\t\t<geometry>\n";
            foreach( $geom as $lonlat )
                print "\t\t\t<node lat=\"$lonlat[1]\" lon=\"$lonlat[0]\" />\n";
            print "\t\t</geometry>\n";
        }
        print "\t</$tag>\n";
    }
    print xmlfooter();
    $result->free();
}

// Queries the database and prints JSON.
function get_json( $query ) {
    global $db;
    $result = $db->query($query);
    if( !$result )
        error("Database query failed: ".$db->error);
    $list = array();
    $list[] = array('type' => 'meta', 'timestamp' => date(DATE_ISO8601));
    while( $data = $result->fetch_assoc() ) {
	$item = array();
        $item['type'] = !is_null($data['imagery']) ? 'offset' : (!is_null($data['calibration']) ? 'calibration' : 'unknown');
        $item['id'] = $data['offset_id'];
	$item['lat'] = $data['lat'];
	$item['lon'] = $data['lon'];
	$item['author'] = $data['author'];
	$item['description'] = $data['description'];
        $item['date'] = $data['offset_date'];
        if( !is_null($data['abandon_date']) ) {
            $dep = array();
            $dep['author'] = $data['abandon_author'];
            $dep['reason'] = $data['abandon_reason'];
            $dep['date'] = $data['abandon_date'];
            $item['deprecated'] = $dep;
        }
        if( !is_null($data['flagged']) )
            $item['flagged'] = 1;
        if( !is_null($data['imagery']) ) {
	    if( !is_null($data['min_zoom']) )
		$item['min-zoom'] = $data['min_zoom'];
	    if( !is_null($data['max_zoom']) )
		$item['max-zoom'] = $data['max_zoom'];
	    $item['imagery'] = $data['imagery'];
	    $item['imlat'] = $data['imlat'];
	    $item['imlon'] = $data['imlon'];
        } elseif( !is_null($data['calibration']) ) {
            $item['geometry'] = parse_wkt($data['cal_text']);
        }
	$list[] = $item;
    }
    $result->free();
    print_json($list);
}

// Prepares a database query given lat, lon, radius and $_REQUEST['imagery'].
function get_query( $lat, $lon, $radius ) {
    global $db;
    $coslat = cos($lat * M_PI / 180.0);
    $region = region_where_clause($lat, $lon, $radius);
    $qimagery = !isset($_REQUEST['imagery']) ? ''
        : "and (imagery is null or imagery = '".$db->escape_string(trim($_REQUEST['imagery']))."')";
    $query = "select *, AsText(calibration) as cal_text, "
            ."X(coord) as lon, Y(coord) as lat, X(im_coord) as imlon, Y(im_coord) as imlat, "
            ."(Y(coord) - $lat)*(Y(coord) - $lat) + (X(coord) - $lon)*(X(coord) - $lon)*$coslat*$coslat as distance "
            ."from iodb where $region $qimagery order by distance limit 30";
    return $query;
}

// Returns where clause for offsets around a point with given radius in km
function region_where_clause( $lat, $lon, $radius ) {
    // http://stackoverflow.com/questions/973363/mysql-not-using-my-indexes
    // http://en.wikipedia.org/wiki/Latitude#The_length_of_a_degree_of_latitude
    // http://janmatuschek.de/LatitudeLongitudeBoundingCoordinates
    $basekm = 6371.0; // 111.1;
    $coslat = cos($lat * M_PI / 180.0);
    $dlat = $radius / $basekm;
    $dlon = asin(sin($dlat) / $coslat); // $dlat * $coslat
    $bbox = wkt_bbox_around($lat, $lon, $dlat * 180.0 / M_PI, $dlon * 180.0 / M_PI);
    return "MBRContains($bbox, coord)";
}

// Transforms center point + radiuses to a WKT string.
function wkt_bbox_around( $lat, $lon, $dlat, $dlon ) {
    $minlat = $lat - $dlat;
    $minlon = $lon - $dlon;
    $maxlat = $lat + $dlat;
    $maxlon = $lon + $dlon;
    return "GeomFromText('POLYGON(($minlon $minlat, $minlon $maxlat, $maxlon $maxlat, $maxlon $minlat, $minlon $minlat))')";
}

// Returns an array of coordinate arrays [lon, lat] from WKT.
function parse_wkt( $wkt ) {
    $geom = array();
    $type = strtoupper(substr($wkt, strpos($wkt, '('))); // we don't need it actually
    $i = strrpos($wkt, '(') + 1;
    $points = explode(',', substr($wkt, $i, strpos($wkt, ')') - $i));
    foreach( $points as $point ) {
        $geom[] = explode(' ', trim($point));
    }
    return $geom;
}

// Adds an entry to the database.
function post() {
    global $db;
    $author = $db->escape_string(req('author'));
    $message = $db->escape_string(req('description'));
    if( mb_strlen($author, 'UTF8') == 0 || mb_strlen($author, 'UTF8') > 100 )
        error('Incorrect author name');
    $messagelen = mb_strlen($message, 'UTF8');
    if( $messagelen < 3 )
        error('Description is too short');
    if( $messagelen > 200 )
        error("Description is too long ($messagelen), 200 chars max");
    $ip = bin2hex(inet_pton($_SERVER['REMOTE_ADDR']));
    validate_ip($ip);

    if( isset($_REQUEST['imagery']) ) {
        $imagery = $db->escape_string(req('imagery'));
        $lat = req('lat'); $lon = req('lon');
        validate_num($lat, 'lat', true, -90.0, 90.0);
        validate_num($lon, 'lon', true, -180.0, 180.0);
        // test if there is already something at there coordinates
        validate_dup($lat, $lon, $author, true);

        $imlat = req('imlat');
        $imlon = req('imlon');
        $minzoom = req('minzoom', 0);
        $maxzoom = req('maxzoom', 30);

        validate_num($imlon, 'imlon', true);
        validate_num($imlat, 'imlat', true);
        validate_num($minzoom, 'minzoom', false, 0, 30);
        validate_num($maxzoom, 'maxzoom', false, 0, 30);

        $query = "insert into iodb (offset_date, coord, author, description, ip, "
                ."imagery, im_coord, min_zoom, max_zoom) "
                ."values (CURDATE(), POINT($lon, $lat), '$author', '$message', 0x$ip, "
                ."'$imagery', POINT($imlon, $imlat), $minzoom, $maxzoom)";
    } elseif( isset($_REQUEST['geometry']) ) {
        $geom = parse_geometry(req('geometry'));
        validate_dup($geom[1], $geom[0], $author, false);
        $query = "insert into iodb (offset_date, coord, author, description, ip, calibration) "
                ."values (CURDATE(), POINT($geom[0], $geom[1]), '$author', '$message', 0x$ip, GeomFromText('$geom[2]'))";
    } else {
        error("Not enough parameters");
    }
    // everything's set, add this!
    $result = $db->query($query);
    if( !$result ) {
        error("Database query failed: ".$db->error);
    }
    message('Offset was added to the database, thank you.');
}

// Request a single value from the database
function request_one($query) {
    global $db;
    $result = $db->query($query);
    if( !$result )
        error('Database query failed: '.$db->error);
    if( $result->num_rows > 0 ) {
        $tmp = $result->fetch_row();
        $ret = $tmp[0];
    } else {
        $ret = null;
    }
    $result->free();
    return $ret;
}

// Check that there are no offset of a given type in this point.
function validate_dup($lat, $lon, $author, $is_imagery) {
    $is_im_clause = $is_imagery ? 'is not null' : 'is null';
    $query = "select offset_id from iodb where "
            ."MBRContains(".wkt_bbox_around($lat, $lon, 1e-6, 1e-6).", coord) "
            ."and author = '$author' and imagery $is_im_clause";
    if( request_one($query) != null )
        error("There is already an offset at coordinates ($lat, $lon), sorry.");
}

// Limit offsets per day for a single IP.
function validate_ip( $ipenc ) {
    $query = "select count(1) from iodb where "
            ."(ip = 0x$ipenc or abandon_ip = 0x$ipenc) and (offset_date = CURDATE() or abandon_date = CURDATE())";
    $cnt = request_one($query);
    if( $cnt > MAX_REQUEST_PER_IP_PER_DAY )
        error('You have made '.MAX_REQUEST_PER_IP_PER_DAY.' requests today. That\'s the limit, sorry.');
}

// Parses query parameter 'geometry', returns array of [lon, lat, wkt].
function parse_geometry( $geom ) {
    $points = explode(',', $geom);
    $g = array();
    $alat = 0.0;
    $alon = 0.0;
    foreach( $points as $pt ) {
        if( !preg_match('/(-?\d{1,3}(?:\.\d+)?)\s+(-?\d{1,2}(?:\.\d+)?)/', $pt, $matches) )
            error("Incorrect format of a geometry point: $pt");
        $g[] = $matches[1].' '.$matches[2];
        $alon += $matches[1];
        $alat += $matches[2];
    }

    $cnt = count($g);
    if( $cnt == 0 )
        error("No points specified in the geometry.");
    if( $cnt > 100 )
        error("Too many points (".count($g)." > 100) in the geometry.");

    $wkt_base = implode(',', $g);
    if( $cnt == 1 )
        $wkt = "POINT($wkt_base)";
    elseif( $g[0] != $g[$cnt-1] )
        $wkt = "LINESTRING($wkt_base)";
    else
        $wkt = "POLYGON(($wkt_base))";

    return array($alon / $cnt, $alat / $cnt, $wkt);
}

// Mark a specified offset as deprecated.
function deprecate() {
    global $db;
    $id = req('id');
    deprecate_impl($id, req('author'), req('reason'));
    message("Offset $id was deprecated.");
}

// Mark a specified offset as deprecated (or not).
function deprecate_impl( $id, $author, $reason ) {
    global $db;
    validate_num($id, 'id', false);
    if( mb_strlen($author, 'UTF8') == 0 || mb_strlen($author, 'UTF8') > 100 )
        error('Incorrect author name');
    $messagelen = mb_strlen($reason, 'UTF8');
    if( $messagelen < 3 )
        error('Reason is too short');
    if( $messagelen > 200 )
        error("Reason is too long ($messagelen), 200 chars max");

    $ip = bin2hex(inet_pton($_SERVER['REMOTE_ADDR']));
    validate_ip($ip);

    $result = $db->query("select abandon_date from iodb where offset_id = $id");
    if( !$result )
        error("Database query failed: ".$db->error);
    if( $result->num_rows < 1 )
        error("No such offset");
    $r = $result->fetch_row();
    $result->free();
    if( !is_null($r[0]) )
        error("Offset $id is already deprecated");

    $qset = "abandon_date = CURDATE(), abandon_author = '".$db->escape_string($author)."', abandon_reason = '".$db->escape_string($reason)."', abandon_ip = 0x$ip";
    $result = $db->query("update iodb set $qset where offset_id = $id");
    if( !$result )
        error("Database query failed: ".$db->error);
}

// Flag a specified offset.
function report() {
    global $db;
    $id = req('id');
    report_impl($id, req('reason'));
    message("Offset $id was flagged.");
}

// Flag/unflag a specified offset.
function report_impl($id, $message = null) {
    global $db;
    if( is_array($id) ) {
        foreach( $id as $tid )
            validate_num($tid, 'id', false);
        $qid = 'in ('.implode(',', $id).')';
    } else {
        validate_num($id, 'id', false);
        $result = $db->query("select flagged from iodb where offset_id = $id");
        if( !$result )
            error("Database query failed: ".$db->error);
        if( $result->num_rows < 1 )
            error("No such offset");
        $arr = $result->fetch_row();
        if( !is_null($message) && !is_null($arr[0]) )
            error('The offset is already reported.');
        if( is_null($message) && is_null($arr[0]) )
            error('The offset is not reported yet.');
        $result->free();
        $qid = '= '.$id;
    }

    $result = $db->query('update iodb set flagged = '.(is_null($message) ? 'null' : "'".$db->escape_string($message)."'")." where offset_id $qid");
    if( !$result )
        error("Database query failed: ".$db->error);
}

function undeprecate( $id ) {
    global $db;
    if( is_array($id) ) {
        foreach( $id as $tid )
            validate_num($tid, 'id', false);
        $qid = 'in ('.implode(',', $id).')';
    } else {
        validate_num($id, 'id', false);
        $result = $db->query("select abandon_date from iodb where offset_id = $id");
        if( !$result )
            error("Database query failed: ".$db->error);
        if( $result->num_rows < 1 )
            error("No such offset");
        $r = $result->fetch_row();
        if( is_null($r[0]) )
            error("Offset $id is not deprecated");
        $result->free();
        $qid = '= '.$id;
    }   

    $result = $db->query("update iodb set abandon_date = null where offset_id $qid");
    if( !$result )
        error("Database query failed: ".$db->error);
}

function delete_impl( $id ) {
    global $db;
    if( !is_array($id) ) {
        $qid = '= '.$id;
        validate_num($id, 'id', false);
    } else {
        foreach( $id as $tid )
            validate_num($tid, 'id', false);
        $qid = 'in ('.implode(',', $id).')';
    }
    $result = $db->query("delete from iodb where offset_id $qid");
    if( !$result )
        error("Database query failed: ".$db->error);
}

// Just dump the whole database. Is called only from command line.
function db_dump() {
    global $argc, $argv;
    $query = 'select *, AsText(calibration) as cal_text, '
            .'X(coord) as lon, Y(coord) as lat, X(im_coord) as imlon, Y(im_coord) as imlat '
            .'from iodb order by offset_id';
    if( $argc > 1 ) {
        if( $argv[1] == 'loc' )
            find_locations();
        else if( $argv[1] == 'create' )
            create_table();
        else if( $argv[1] == 'json' )
            get_json($query);
        else if( $argv[1] == 'xml' )
            get($query);
        else
            print "Unknown action\n";
    } else {
        print "IODB Command line\nSyntax: php $argv[0] {xml|json|loc|create}\n";
    }
}

// Pass the database dump file through, respecting requested output format.
function db_dump_passthru() {
    header('Content-Encoding: gzip');
    if( out_fmt() == 'json' ) {
        header('Content-type: application/json');
        $file = 'iodb-latest.json.gz';
    } else {
        header('Content-type: application/xml');
        $file = 'iodb-latest.xml.gz';
    }
    header('Content-Length: '.filesize('download/'.$file));
//    header('Content-Disposition: attachment; filename='.$file);
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    readfile('download/'.$file);
}

// Query nominatim for unset locations in the database
function find_locations() {
    global $db;
    $result = $db->query("select offset_id, X(coord) as lon, Y(coord) as lat from iodb where location is null order by offset_id limit 3");
    if( !$result )
        error("Database query failed: ".$db->error);
    $queue = array();
    while( $line = $result->fetch_assoc() )
        $queue[] = array('id' => $line['offset_id'], 'lat' => $line['lat'], 'lon' => $line['lon']);
    $result->free();

    $lastrow = request_one("select max(offset_id) from iodb where location is not null");
    $result = $db->query("select offset_id, X(coord) as lon, Y(coord) as lat from iodb where offset_id = ".rand(1, $lastrow));
    if( !$result )
        error("Database query failed: ".$db->error);
    while( $line = $result->fetch_assoc() )
        $queue[] = array('id' => $line['offset_id'], 'lat' => $line['lat'], 'lon' => $line['lon']);
    $result->free();

    foreach( $queue as $offset ) {
        $url = 'https://nominatim.openstreetmap.org/reverse?format=json&zoom=16&addressdetails=0'
              .'&email=zverik%40textual.ru&lat='.$offset['lat'].'&lon='.$offset['lon'];
        $response = json_decode(file_get_contents($url), true);
        if( isset($response) && (isset($response['display_name']) || isset($response['error'])) ) {
            $newval = $db->escape_string(isset($response['display_name']) ? $response['display_name'] : '');
            $result = $db->query("update iodb set location='$newval' where offset_id = ".$offset['id']);
            if( !$result )
                error("Database query failed: ".$db->error);
        }
    }
}

function create_table() {
    global $db;
    $query = <<<CSQL
create table iodb (
    offset_id int unsigned not null auto_increment primary key,
    coord point not null,
    flagged varchar(200),
    location varchar(200),

    offset_date date not null,
    author varchar(100) not null,
    description varchar(200) not null,
    ip varbinary(16) not null,

    abandon_date date,
    abandon_author varchar(100),
    abandon_reason varchar(200),
    abandon_ip varbinary(16),

    im_coord point,
    imagery varchar(200),
    min_zoom tinyint(2),
    max_zoom tinyint(2),

    calibration geometry,

    spatial index (coord),
    index (imagery),
    index (offset_date),
    index (abandon_date),
    index (ip),
    index (abandon_ip)
) Engine=MyISAM DEFAULT CHARACTER SET utf8;
CSQL;
    $result = $db->query($query);
    if( !$result ) {
        print('Could not create iodb table: '.$db->error);
        exit;
    }
}

?>
