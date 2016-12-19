<?php
require_once __DIR__ . '/WikimediaCommandLine.inc';

foreach ( $wgLocalDatabases as $db ) {
print "$db\n";
}
