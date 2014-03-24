# Imagery Offset Database

This is a backend and web interface for imagery offset database.

## Installation

Copy everything except `scripts` to a www document root. Edit `config.php`, in which
you need to include OSM API key and database credentials. Then open the web interface
and click "Create database".

To publish regular backups of the database, use `iodb_backup` script.

## Author and License

Written by Ilya Zverev, licensed WTFPL.

Web interface uses [Leaflet](http://leafletjs.com) library and some plugins for it:
heatmap.js, Leaflet.markercluster, Permalink and TileLayer.Grayscale. Those and other
plugins can be downloaded from Leaflet's official website.
