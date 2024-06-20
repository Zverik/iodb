#!/bin/sh
IODB=/var/www/sites/offsets.textual.ru/iodb.php
PHP=/usr/bin/php
GZIP=/usr/bin/gzip
TARGET=/var/www/sites/offsets.textual.ru/download
TMPDIR=/tmp
DATE=`date +%y%m%d`

$PHP $IODB xml  2> /dev/null | $GZIP > $TMPDIR/iodb-latest.xml.gz
$PHP $IODB json 2> /dev/null | $GZIP > $TMPDIR/iodb-latest.json.gz

if [ ! -f $TARGET/iodb-$DATE.xml.gz ]; then
	cp $TMPDIR/iodb-latest.xml.gz $TARGET/iodb-$DATE.xml.gz
	cp $TMPDIR/iodb-latest.json.gz $TARGET/iodb-$DATE.json.gz
fi

mv $TMPDIR/iodb-latest.xml.gz $TARGET
mv $TMPDIR/iodb-latest.json.gz $TARGET
