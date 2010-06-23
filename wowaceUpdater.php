#!/usr/bin/php
<?php
define("DIR_SEP", DIRECTORY_SEPARATOR);
error_reporting(-1);

// Check requirements
$failed = false;
@list($major, $minor) = explode('.', PHP_VERSION);
if($major < 5 || ($major == 5 && $minor < 2)) {
	print("PHP >= 5.2 is required !\n");
	$failed = true;
}
foreach(array('ZIP', 'cURL', 'SimpleXML') as $ext) {
	if(!extension_loaded($ext)) {
		@dl($ext);
		if(!extension_loaded($ext)) {
			printf("The %s extension is requird !\n", $ext);
			$failed = true;
		}
	}
}
if($failed) exit(1);

//===== START OF CONFIGURATION =====

// This is where your addons live
$baseDir = "/home/guillaume/AddOns";

// This is where old versions will be moved (no pruning)
$backupDir = "/home/guillaume/.backup";

// Must be one of 'release', 'beta' or 'alpha'.
$defaultKind = 'beta';

// Disable to use embedded addons
$wantNolib = true;

// Number of concurrent requests
$maxConcurrent = 10;

//===== END OF CONFIGURATION =====

function cleanupVersion($version, $addon)  {
	return preg_replace('/^('.preg_quote($addon->name).'|'.preg_quote($addon->project).')\s*/i', '', $version);
}

$addons = array();

function pruneFailedAddons() {
	global $addons;
	foreach($addons as $key => $addon) {
		if(isset($addon->failure)) {
			printf("%s: %s\n", $addon->name, $addon->failure);
			unset($addons[$key]);
		}
	}	
}


// Build the list of updatable addons from the directory
$dh = opendir($baseDir);
while($entry = readdir($dh)) {
	$path = $baseDir.DIR_SEP.$entry.DIR_SEP;
	if(is_dir($path) && file_exists($path.$entry.'.toc') && !file_exists($path.'.git') && !file_exists($path.'.svn') && !file_exists($path.'.freeze')) {
		$values = array();
		$fh = fopen($path.$entry.'.toc', 'r');
		while($line = fgets($fh)) {
			$line = trim($line);
			if(preg_match('/^##\s*(.+)\s*:\s*(.+)\s*$/i', $line, $parts)) {
				$values[$parts[1]] = $parts[2];
			}
		}
		fclose($fh);
		if(isset($values['X-Curse-Project-ID']) && isset($values['X-Curse-Packaged-Version'])) {
			$project = $values['X-Curse-Project-ID'];			
			$version = file_exists($path.'.version') ? trim(file_get_contents($path.'.version')) : $values['X-Curse-Packaged-Version'];
			if(!isset($addons[$project])) {
				$addon = new StdClass();
				$addons[$project] = $addon;
				$addon->project = $project;
				$addon->name = isset($values['X-Curse-Project-Name']) ? $values['X-Curse-Project-Name'] : $entry;
				$addon->dirs = array();
				$addon->version = cleanupVersion($version, $addon);
			} else {
				$addon = $addons[$project];
			}
			$addon->dirs[] = $entry;
			if(file_exists($path.'.alpha')) {
				$addon->kind = 'alpha';
			} elseif(file_exists($path.'.beta')) {
				$addon->kind = 'beta';
			} elseif(file_exists($path.'.release')) {
				$addon->kind = 'release';
			}
		}
	}
}
closedir($dh);

function guessReleaseType($version) {
	if(preg_match('/^v?[-\d\.\_]+$|stable|release/i', $version))
		return 'release';
	elseif(preg_match('/(beta|alpha)/i', $version, $parts))
		return strtolower($parts[1]);
	else
		return 'alpha';
}

printf("Found %d addons.\n", count($addons));

// Fetch files.rss to get package informations using concurrent requests.
print("Fetching latest package data.\n");
$mch = curl_multi_init();
$handles = array();
$queue = array();
foreach($addons as $key => $addon) {
	$mh = curl_init('http://www.wowace.com/addons/'.$addon->project.'/files.rss');
	curl_setopt($mh, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($mh, CURLOPT_FOLLOWLOCATION, true);	
	curl_setopt($mh, CURLOPT_FAILONERROR, true);
	$queue[] = $mh;
	$handles[intval($mh)] = $addon;
}

$num = count($addons);
$active = 0;
$done = 0;
$lastdone = -1;
do {
	while($active < $maxConcurrent && !empty($queue)) {
		curl_multi_add_handle($mch, array_shift($queue));
		$active++;
	}
	$status = curl_multi_exec($mch, $active);
	while(false !== ($info = curl_multi_info_read($mch))) {
		if($info['msg'] == CURLMSG_DONE) {
			$done++;
			$mh = $info['handle'];
			$addon = $handles[intval($mh)];
			unset($handles[intval($mh)]);
			if($info['result'] == CURLE_OK) {
				$rss = curl_multi_getcontent($mh);
				try {
					$xml = new SimpleXMLElement($rss);
					$items = @$xml->channel->item;
					if(isset($items)) {
						$addon->available = array();
						foreach($items as $item) {
							unset($item->description);
							preg_match('/^(.+)(-nolib)?$/', cleanupVersion((string)$item->title, $addon), $parts);
							@list(, $version, $nolib) = $parts;
							if(!isset($nolib) || $wantNolib) {
								$kind = guessReleaseType($version);
								if(!isset($addon->available[$kind.$nolib])) {
									$addon->available[$kind.$nolib] = array(
										'version' => $version,
										'link' => (string)$item->link,
									);
								}
							}
						}
					} else {
						$addon->failure = sprintf("Malformed RSS feed for %s from %s !", $addon->name, 'http://www.wowace.com/addons/'.$addon->project.'/files.rss');
					}
				} catch(Exception $e) {
					$addon->failure = sprintf("Could not parse XML for %s from http://www.wowace.com/addons/%s/files.rss: %s !", $addon->name, $addon->project, $e);
				}
			} else {
				$addon->failure = sprintf("Could not retrieve package data for %s: %s", $addon->project, curl_error($mh));
			}
			curl_close($mh);
		}
	}
	if($done != $lastdone) {
		$marks = floor($done / $num * 70);
		printf("%s%s %3d%%\r", str_repeat('#', $marks), str_repeat('.', 70-$marks), floor($done / $num * 100));
		$lastdone = $done;
	}
} while ($status === CURLM_CALL_MULTI_PERFORM || $active || !empty($queue));

curl_multi_close($mch);

print("\n");

pruneFailedAddons();

$searchOrder = array(
	'release' => array('release-nolib', 'release', 'beta-nolib', 'beta', 'alpha-nolib', 'alpha'),
	'beta' => array('beta-nolib', 'beta', 'release-nolib', 'release', 'alpha-nolib', 'alpha'),
	'alpha' => array('alpha-nolib', 'alpha', 'beta-nolib', 'beta', 'release-nolib', 'release'),
);

// Selected version to be installed
foreach($addons as $key => $addon) {
	$selected = false;
	foreach($searchOrder[isset($addon->kind) ? $addon->kind : $defaultKind] as $kind) {
		if(isset($addon->available[$kind])) {
			$selected = $addon->available[$kind];
			break;
		}
	}
	if(!$selected) {
		printf("%s: no suitable version found !\n", $addon->name);
		unset($addons[$key]);
		continue;
	}
	if($addon->version == $selected['version']) {
		// Already installed, we're done with this one
		//printf("%s: current: %s, latest: %s, up to date !\n", $addon->name, $addon->version, $selected['version']);
		unset($addons[$key]);
	} else {
		// Need update
		printf("%s: %s ===> %s\n", $addon->name, $addon->version, $selected['version']);
		unset($addon->available);
		$addon->selected = $selected['link'];
		$addon->newversion = $selected['version'];
	}
}

if(count($addons) == 0) {
	printf("Nothing to update.\n");
		exit(0);
}

// Now get the download page, scrape it to extract the file path, then download it
printf("Downloading %d files\n", count($addons));

$mch = curl_multi_init();
$handles = array();
$queue = array();
foreach($addons as $key => $addon) {
	$mh = curl_init($addon->selected);
	curl_setopt($mh, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($mh, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($mh, CURLOPT_FAILONERROR, true);	
	$queue[] = $mh;
	$handles[intval($mh)] = $addon;
}

$num = count($addons) * 2;
$done = 0;
$lastdone = -1;
$active = 0;
do {
	while($active < $maxConcurrent && !empty($queue)) {
		curl_multi_add_handle($mch, array_shift($queue));
		$active++;
	}
	$status = curl_multi_exec($mch, $active);
	while(false !== ($info = curl_multi_info_read($mch))) {
		if($info['msg'] == CURLMSG_DONE && $info['result'] == CURLE_OK) {
			$done++;
			$mh = $info['handle'];
			$addon = $handles[intval($mh)];
			unset($handles[intval($mh)]);
			if(isset($addon->selected)) {
				$page = curl_multi_getcontent($mh);
				curl_close($mh);
				if(empty($page)) {
					$addon->failure = sprintf('Download of %s failed', $addon->selected);
					$done++;
				} elseif(preg_match('@<dd><a href="(http://.+?/.+?\.zip)">(.+?\.zip)</a></dd>@i', $page, $parts)) {
					@list(, $url, $filename) = $parts;
					$tmpfile = tempnam('/tmp', $filename.'-');
					register_shutdown_function('unlink', $tmpfile);
					//$tmpfile = '/tmp/'.$filename;
					$fh = fopen($tmpfile, 'w');
					if($fh) {
						$addon->fh = $fh;
						$addon->origfilename = $filename;
						$addon->filename = $tmpfile;
						$mh = curl_init($url);
						if($mh) {
							$addon->url = $url;
							curl_setopt($mh, CURLOPT_FOLLOWLOCATION, true);	
							curl_setopt($mh, CURLOPT_FAILONERROR, true);
							curl_setopt($mh, CURLOPT_FILE, $fh);	
							$handles[intval($mh)] = $addon;	
							$queue[] = $mh;
						} else {
							$addon->failure = sprintf("cannot download %s", $url);
							$done++;
						}
					} else {
						$addon->failure = sprintf("cannot create file %s", $tmpfile);
						$done++;
					}
				} else {
					$addon->failure = sprintf('cannot find the package URL in %s', $addon->selected);
					$done++;
				}
				unset($addon->selected);
			} elseif(isset($addon->url)) {
				curl_close($mh);
				unset($addon->url);
			}
		} elseif($info['msg'] == CURLMSG_DONE && $info['result'] != CURLE_OK) {
			$mh = $info['handle'];
			$addon = $handles[intval($mh)];
			unset($handles[intval($mh)]);
			$addon->failure = curl_error($mh);
			curl_close($mh);    		
			unset($addon->selected);
			unset($addon->url);
		}
	}
	if($done != $lastdone) {
		$marks = floor($done / $num * 70);
		printf("%s%s %3d%%\r", str_repeat('#', $marks), str_repeat('.', 70-$marks), floor($done / $num * 100));
		$lastdone = $done;
	}
} while ($status === CURLM_CALL_MULTI_PERFORM || $active || !empty($queue));

curl_multi_close($mch);

print("\n");

foreach($addons as $key => $addon) {
	if(isset($addon->fh)) {
		$fh = $addon->fh;
		fflush($fh);
		fclose($fh);
		unset($addon->fh);
	}
}

pruneFailedAddons();

if(count($addons) == 0) {
	print("Nothing to install.\n");
	exit(0); // Done
}

// Backup the old files and install the new ones
foreach($addons as $key => $addon) {
	printf("Installing %s %s: ", $addon->name, $addon->newversion); flush();
	$za = new ZipArchive();
	if(TRUE !== ($err = $za->open($addon->filename))) {
		printf("Cannot open the zip archive %s: %d !\n", $addon->filename, $err);
		continue;
	}
	$backupPath = $backupDir.$addon->project.'-'.$addon->version;
	if(!file_exists($backupPath)) {
		if(mkdir($backupPath, 0755, true)) {
			$failed = false;
			$dirs = $addon->dirs;
			foreach($dirs as $i => $dir) {
				if(!rename($baseDir.DIR_SEP.$dir, $backupPath.DIR_SEP.$dir)) {
					printf("cannot backup %s, skipped.", $addon->name);
					for($j = 0; $j < $i; $j++) {
						@rename($backupPath.DIR_SEP.$dirs[$j], $baseDir.DIR_SEP.$dirs[$j]);
					}
					@rmdir($backupPath);
					$failed = true;
					break;
				}
			}
			if($failed) continue;
		} else {
			printf("cannot backup %s, skipped.", $addon->name);	
			continue;
		}
	}
	$za->extractTo($baseDir);
	for($index = 0; $index < $za->numFiles; $index++) {
		$entry = $za->statIndex($index);
		if(preg_match('@^([^/]+)/\1\.toc$@i', $entry['name'], $parts)) {
			file_put_contents($baseDir.DIR_SEP.$parts[1].DIR_SEP.'.version', $addon->newversion);
			if(isset($addon->kind)) {
				file_put_contents($baseDir.DIR_SEP.$parts[1].DIR_SEP.'.'.isset($addon->kind), "");
			}
		}
	}
	$za->close();
	printf("done.\n");
}

?>
