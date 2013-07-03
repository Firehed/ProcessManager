<?php
$client= new GearmanClient();
$client->addServer();
print $client->do("reverse", "Hello World!");

print $client->do('caps', 'some lowercase string') . "\n";

