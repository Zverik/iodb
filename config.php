<? // IODB configuration file. Written by Ilya Zverev, licensed WTFPL.

// OpenStreetMap OAuth parameters, see http://wiki.openstreetmap.org/wiki/OAuth
const CLIENT_ID     = '';
const CLIENT_SECRET = '';

const AUTHORIZATION_ENDPOINT = 'http://www.openstreetmap.org/oauth/authorize';
const TOKEN_ENDPOINT         = 'http://www.openstreetmap.org/oauth/access_token';
const REQUEST_ENDPOINT       = 'http://www.openstreetmap.org/oauth/request_token';
const OSM_API                = 'http://api.openstreetmap.org/api/0.6/';

// Database credentials
const DB_HOST     = 'localhost';
const DB_USER     = 'iodb';
const DB_PASSWORD = '';
const DB_DATABASE = 'iodb';

// Miscellaneous
const DEFAULT_RADIUS = 10; // In km
const MAX_RADIUS = 40; // Also in km
const MAX_REQUEST_PER_IP_PER_DAY = 100; // Only for storing and deprecating offsets
const DEFAULT_USER = ''; // If set, this user is considered logged in by default
$administrators = array(); // User names of administrators

?>
