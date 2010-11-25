#!/usr/bin/php5 -f
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
	if(!extension_loaded($ext) && !extension_loaded('php_'.$ext)) {
		@dl($ext);
		@dl('php_'.$ext);
		if(!extension_loaded($ext) && !extension_loaded('php_'.$ext)) {
			printf("The %s extension is required !\n", $ext);
			$failed = true;
		}
	}
}
if($failed) exit(1);

define('HOME', isset($_SERVER["USERPROFILE"]) ? $_SERVER["USERPROFILE"] : $_SERVER["HOME"]);

//===== START OF CONFIGURATION =====

// This is where your addons live
$baseDir = "/home/guillaume/AddOns";

// This is where old versions will be moved (no pruning)
$backupDir = HOME.DIR_SEP.".addonBackup";

// Must be one of 'release', 'beta' or 'alpha'.
$defaultKind = 'beta';

// Disable to use embedded addons
$wantNolib = true;

// Number of concurrent requests
$maxConcurrent = 10;

// Do not really modify the files
$dryRun = false;

// The URL of *your* wowinterface favorites RSS feed
$wowiFavoritesURL = null;

//===== END OF CONFIGURATION =====

// Override default configuration
if(file_exists(HOME.DIR_SEP.'.wowaceUpdater.conf')) {
	@include_once(HOME.DIR_SEP.'.wowaceUpdater.conf');
}

$defaultCurlOptions = array(
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_AUTOREFERER => true,
	CURLOPT_FAILONERROR => true,
	CURLOPT_RETURNTRANSFER => true,
);

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

$wowaceCount = 0;
$wowiCount = 0;
$modules = array();
$sources = array();
$frozen = array();
$unknowns = array();

// Build the list of updatable addons from the directory
$dh = opendir($baseDir);
while($entry = readdir($dh)) {
	$path = $baseDir.DIR_SEP.$entry.DIR_SEP;
	if(!is_dir($baseDir.DIR_SEP.$entry) || !file_exists($path.$entry.'.toc')) {
		continue;
	}

	if(file_exists($path.'.git') || file_exists($path.'.svn')) {
		$sources[] = $entry;
		continue;
	}

	if(file_exists($path.'.freeze')) {
		$frozen[] = $entry;
		continue;
	}

	$headers = array();
	$fh = fopen($path.$entry.'.toc', 'r');
	while($line = fgets($fh)) {
		$line = trim($line);
		if(preg_match('/^##\s*(.+)\s*:\s*(.+)\s*$/i', $line, $parts)) {
			$headers[$parts[1]] = $parts[2];
		}
	}
	fclose($fh);

	if(is_link($baseDir.DIR_SEP.$entry) || !empty($headers['RequiredDeps']) || !empty($headers['Dependencies'])) {
		$modules[] = $entry;
		continue;
	}

	$addon = new StdClass();
	$addon->dirs = array();

	if(isset($headers['X-Curse-Project-ID']) && isset($headers['X-Curse-Packaged-Version'])) {
		$addon->project = $headers['X-Curse-Project-ID'];
		$addon->version = @$headers['X-Curse-Packaged-Version'];
		$addon->name = isset($headers['X-Curse-Project-Name']) ? $headers['X-Curse-Project-Name'] : $entry;
		$addon->source = "wowace";
		if(file_exists($path.'.alpha')) {
			$addon->kind = 'alpha';
		} elseif(file_exists($path.'.beta')) {
			$addon->kind = 'beta';
		} elseif(file_exists($path.'.release')) {
			$addon->kind = 'release';
		}
	} elseif(isset($headers['X-WoWI-ID'])) {
		$addon->project = $headers['X-WoWI-ID'];
		$addon->name = $entry;
		$addon->source = "wowi";
		$addon->kind = null;
	}
	if(file_exists($path.'.version') && !isset($addon->version)) {
		$addon->version = trim(file_get_contents($path.'.version'));
	}
	if(file_exists($path.'.addon-data.ini')) {
		$data = parse_ini_file($path.'.addon-data.ini');
		if($data) {
			foreach($data as $key => $value) {
				if(!empty($addon->$key) && $addon->$key == $value) {
					unset($data[$key]);
				} else {
					$addon->$key = $value;
				}
			}
			$addon->data = $data;
		}
	}
	if(!isset($addon->project)) {
		$unknowns[] = $entry;
		continue;
	}
	if(isset($addon->version)) {
		$addon->version = cleanupVersion($addon->version, $addon);
	} else {
		$addon->version = "unknown";
	}
	if(!isset($addons[$addon->project])) {
		$addons[$addon->project] = $addon;
		if($addon->source == "wowace") {
			$wowaceCount++;
		} else {
			$wowiCount++;
		}
	} else {
		$addon = $addons[$project];
	}
	$addon->dirs[] = $entry;
}
closedir($dh);

function addonSort($a, $b) {
	return strcasecmp($a->name, $b->name);
}
uasort($addons, "addonSort");

//sort($frozen);
//sort($sources);
//sort($modules);
sort($unknowns);

printf("Found %d addons:
- wowace: %d
- wowinterface: %d
- frozen: %d
- sources: %d
- modules: %d
- unknown: %d %s
",
	count($addons) + count($frozen) + count($sources) + count($modules) + count($modules),
	$wowaceCount,
	$wowiCount,
	count($frozen),
	count($sources),
	count($modules),
	count($unknowns), count($unknowns) > 0 ? "[ ".join(", ", $unknowns)." ]" : ""
);

// Fetch files.rss to get package informations using concurrent requests.
print("Fetching latest package data.\n");
$mch = curl_multi_init();
$handles = array();
$queue = array();
$num = 0;

if($wowiCount > 0 && $wowiFavoritesURL) {
	$mh = curl_init($wowiFavoritesURL);
	curl_setopt_array($mh, $defaultCurlOptions);
	$queue[] = $mh;
	$handles[intval($mh)] = "wowiFavorites";
	$num++;
}

foreach($addons as $key => $addon) {
	if($addon->source == "wowace") {
		$mh = curl_init('http://www.wowace.com/addons/'.$addon->project.'/files/');
		curl_setopt_array($mh, $defaultCurlOptions);
		$queue[] = $mh;
		$handles[intval($mh)] = $addon;
		$num++;
	}
}

date_default_timezone_set("UTC");

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
				$html = curl_multi_getcontent($mh);
				if($addon == "wowiFavorites") {
					$rss = simplexml_load_string($html);
					foreach($rss->channel->item as $item) {
						if(preg_match('@downloads/info(\d+)-(.+?)\.html@i', $item->link, $parts)) {
							list(, $project, $version) = $parts;
							$version = preg_replace("/%([0-9a-f]{2})/ie", 'chr(0x\1)', $version);
							$project = intval($project);
							if(isset($addons[$project])) {
								$addon = $addons[$project];
								$date = strtotime($item->pubDate);
								if(!isset($addon->newversion) || $date > $addon->newdate) {
									$addon->newversion = $version;
									$addon->newdate = $date;
								}
							}
						}
					}
				} else {
					$addon->available = array();
					$current = null;
					foreach(preg_split("/\s*\n\s*/", $html, null, PREG_SPLIT_NO_EMPTY) as $line) {
						if(preg_match('@<td class="col-file"><a href="(/addons/.+?/)">(.+?)</a></td>@', $line, $parts)) {
							$version = cleanupVersion($parts[2], $addon);
							preg_match('@^(.+?)(-nolib)?$@i', $version, $versionParts);
							$current = array(
								'version' => $version,
								'baseVersion' => $versionParts[1],
								'nolib' => !empty($versionParts[2]),
								'link' => 'http://www.wowace.com'.$parts[1],
								'ok' => false,
							);
						} elseif($current) {
							if(preg_match('@<td class="col-type"><.+>(alpha|beta|release)<.+></td>@i', $line, $parts)) {
								$current['kind'] = strtolower($parts[1]);
							} elseif(preg_match('@<td class="col-status"><.+>normal<.+></td>@i', $line, $parts)) {
								$current['ok'] = true;
							} elseif(preg_match('@<td class="col-date"><.+ data-epoch="(\d+)">.*</span></td>@i', $line, $parts)) {
								$current['timestamp'] = intval($parts[1]);
							} elseif(preg_match('@<td class="col-filename">@i', $line)) {
								if($current['ok'] && isset($current['kind']) && isset($current['timestamp'])) {
									$kind = $current['kind'];
									if($current['nolib']) {
										if($wantNolib) {
											$kind .= '-nolib';
										} else {
											$kind = false;
										}
									}
									if($kind && (!isset($addon->available[$kind]) || $current['timestamp'] > $addon->available[$kind]['timestamp'])) {
										unset($current['ok']);
										unset($current['nolib']);
										$addon->available[$kind] = $current;
									}
								}
								$current = null;
							}
						}
					}
					if($wantNolib) {
						foreach(array('alpha', 'beta', 'release') as $kind) {
							$kindNolib = $kind.'-nolib';
							if(isset($addon->available[$kindNolib])) {
								if(!isset($addon->available[$kind]) || $addon->available[$kindNolib]['baseVersion'] == $addon->available[$kind]['baseVersion']) {
									$addon->available[$kind] = $addon->available[$kindNolib];
								}
								unset($addon->available[$kindNolib]);
							}
						}
					}
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

$resolveFilters = array(
	'release' => array(array('release'), array('beta'), array('alpha')),
	'beta' => array(array('release', 'beta'), array('alpha')),
	'alpha' => array(array('release', 'beta', 'alpha')),
);

// Selected version to be installed
foreach($addons as $key => $addon) {
	if($addon->source == "wowace") {
		$selected = false;
		$kind = !empty($addon->kind) ? $addon->kind : $defaultKind;
		foreach($resolveFilters[$kind] as $filter) {
			foreach($addon->available as $pkg) {
				if(in_array($pkg['kind'], $filter)) {
					if(!$selected || $pkg['timestamp'] > $selected['timestamp']) {
						$selected = $pkg;
					}
				}
			}
			if($selected) {
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
			//printf("%s: current: %s, latest %s: %s (%s), up to date !\n", $addon->name, $addon->version, $kind, $selected['version'], $selected['kind']);
			unset($addons[$key]);
		} else {
			// Need update
			printf("%s (%s): %s ===> %s (%s)\n", $addon->name, $kind, $addon->version, $selected['version'], $selected['kind']);
			unset($addon->available);
			$addon->selected = $selected['link'];
			$addon->newversion = $selected['version'];
		}
	} elseif($addon->source == "wowi") {
		if(isset($addon->newversion)) {
			if($addon->newversion != $addon->version) {
				$addon->url = sprintf("http://fs.wowinterface.com/patcher.php?id=%d", $addon->project);
			} else {
				unset($addons[$key]);
				continue;
			}
		} else {
			$addon->newversion  = "latest";
			$addon->url = sprintf("http://fs.wowinterface.com/patcher.php?id=%d", $addon->project);
		}
		printf("%s: %s ===> %s\n", $addon->name, $addon->version, $addon->newversion);
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
$num = 0;

function downloadFile($addon, $url, $filename) {
	global $handles, $queue, $num, $defaultCurlOptions;
	$tmpfile = tempnam('/tmp', $filename.'-');
	register_shutdown_function('unlink', $tmpfile);
	$fh = fopen($tmpfile, 'w');
	if($fh) {
		$addon->fh = $fh;
		$addon->origfilename = $filename;
		$addon->filename = $tmpfile;
		$mh = curl_init($url);
		if($mh) {
			$addon->url = $url;
			curl_setopt_array($mh, $defaultCurlOptions);
			curl_setopt($mh, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($mh, CURLOPT_FILE, $fh);
			$handles[intval($mh)] = $addon;
			$num++;
			$queue[] = $mh;
		} else {
			$addon->failure = sprintf("cannot download %s", $url);
		}
	} else {
		$addon->failure = sprintf("cannot create file %s", $tmpfile);
	}
}

foreach($addons as $key => $addon) {
	if(isset($addon->url)) {
		downloadFile($addon, $addon->url, $addon->name);
	} elseif(isset($addon->selected)) {
		$mh = curl_init($addon->selected);
		curl_setopt_array($mh, $defaultCurlOptions);
		$queue[] = $mh;
		$handles[intval($mh)] = $addon;
		$num++;
	}
}

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
				} elseif(preg_match('@<dd><a href="(http://.+?/.+?\.zip)">(.+?\.zip)</a></dd>@i', $page, $parts)) {
					@list(, $url, $filename) = $parts;
					downloadFile($addon, $url, $filename);
				} else {
					$addon->failure = sprintf('cannot find the package URL in %s', $addon->selected);
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
	if($dryRun) {
		echo "dry-run, skipped !\n"; flush();
		continue;
	}
	$za = new ZipArchive();
	if(TRUE !== ($err = $za->open($addon->filename))) {
		printf("Cannot open the zip archive %s: %d !\n", $addon->filename, $err);
		continue;
	}
	$backupPath = $backupDir.DIR_SEP.$addon->project.'-'.$addon->version;
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

	$data = isset($addon->data) ? $addon->data : array();
	$data['project'] = $addon->project;
	$data['name'] = $addon->name;
	$data['source'] = $addon->source;
	$data['version'] = $addon->newversion;
	if(!empty($addon->kind)) {
		$data['kind'] = $addon->kind;
	}
	$lines = array();
	foreach($data as $key=>$value) {
		if(is_int($value)) {
			$lines[] = sprintf("%s=%d\n", $key, $value);
		} else {
			$lines[] = sprintf("%s=\"%s\"\n", $key, $value);
		}
	}
	$dataStr = join("", $lines);

	for($index = 0; $index < $za->numFiles; $index++) {
		$entry = $za->statIndex($index);
		if(preg_match('@^([^/]+)/\1\.toc$@i', $entry['name'], $parts)) {
			file_put_contents($baseDir.DIR_SEP.$parts[1].DIR_SEP.'.addon-data.ini', $dataStr);
		}
	}
	$za->close();
	printf("done.\n");
}

?>
