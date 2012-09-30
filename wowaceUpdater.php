#!php -f
<?php
error_reporting(-1);
date_default_timezone_set("UTC");

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
$backupDir = HOME."/.addonBackup";

// Must be one of 'release', 'beta' or 'alpha'.
$defaultKind = 'beta';

// Disable to use embedded addons
$wantNolib = true;

// Number of concurrent requests
$maxConcurrent = 10;

// Do not really modify the files
$dryRun = false;

//===== END OF CONFIGURATION =====

// Override default configuration
if(file_exists(HOME.'/.wowaceUpdater.conf')) {
	@include_once(HOME.'/.wowaceUpdater.conf');
}

if($backupDir) {
	if(!is_dir($backupDir) || !is_writable($backupDir)) {
		die("Backup directory doesn't exist or isn't writable: $backupDir");
	}
} else {
	print("WARNING: backup disabled !\n");
	$backupDir = false;
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
	} elseif(is_link($baseDir.DIR_SEP.$entry)) {
		$modules[] = $entry;
		continue;
	}

	$hasAddonData = file_exists($path.'.addon-data.ini');

	if(!$hasAddonData) {
		// Parse addon TOC file
		$headers = array();
		$fh = fopen($path.$entry.'.toc', 'r');
		while($line = fgets($fh)) {
			$line = trim($line);
			if(preg_match('/^##\s*(.+)\s*:\s*(.+)\s*$/i', $line, $parts)) {
				$headers[$parts[1]] = $parts[2];
			}
		}
		fclose($fh);

		if(!empty($headers['RequiredDeps']) || !empty($headers['Dependencies'])) {
			$modules[] = $entry;
			continue;
		}
	}

	$addon = new StdClass();
	$addon->dirs = array();
	$addon->wantNolib = $wantNolib;

	if(!$hasAddonData) {

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

	} else {

		$data = parse_ini_file($path.'.addon-data.ini');
		if($data) {
			foreach($data as $key => $value) {
				if(isset($addon->$key) && $addon->$key == $value) {
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
		$addon = $addons[$addon->project];
	}
	$addon->dirs[] = $entry;

	// Cleanup obsolete files
	foreach(array(".version", ".alpha", ".beta", ".release") as $obsoleteFile) {
		if(file_exists($path.$obsoleteFile)) {
			@unlink($path.$obsoleteFile);
		}
	}
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

foreach($addons as $key => $addon) {
	if($addon->source == "wowace") {
		$url = 'http://www.wowace.com/addons/'.$addon->project.'/files/';
	} elseif($addon->source == "wowi") {
		$url = 'http://fs.wowinterface.com/patcher.php?id='.$addon->project;
	} else {
		continue;
	}
	$mh = curl_init($url);
	curl_setopt_array($mh, $defaultCurlOptions);
	$queue[] = $mh;
	$handles[intval($mh)] = $addon;
	$num++;
}


function parse_wowi_patcher_data($addon, $xml) {
	$doc = simplexml_load_string($xml);
	$addon->newversion = (string)$doc->Current->UIVersion;
	$addon->url = (string)$doc->Current->UIFileURL;
}

function scrape_wowace_addon_page($addon, $html) {

	// Parse the full HTML page
	$doc = new DOMDocument('1.0');
	@$doc->loadHTML($html);
	$xpath = new DOMXpath($doc);
	$versions = array();
	foreach($xpath->query("//table[@class='listing']/tbody/tr/td") as $element) {
		if(!preg_match('@/tr\[(\d+)\]/td\[\d+\]$@', $element->getNodePath(), $parts)) {
			continue;
		}
		$index = intval($parts[1]);
		if(!isset($versions[$index])) {
			$current = array(
				'kind' => null,
				'timestamp' => 0,
				'nolib' => false,
				'ok' => false,
				'link' => null,
				'version' => null,
				'baseVersion' => null,
			);
		} else {
			$current = $versions[$index];
		}
		$value = trim($element->nodeValue);
		switch($element->getAttribute('class')) {
			case 'col-file':
				$value = cleanupVersion($value, $addon);
				preg_match('@^(.+?)(-nolib)?$@i', $value, $versionParts);
				$current['version'] = $value;
				$current['baseVersion'] = $versionParts[1];
				$current['nolib'] = !empty($versionParts[2]);
				$current['link'] = 'http://www.wowace.com' . $element->firstChild->getAttribute('href');
				break;
			case 'col-type':
				$current['kind'] = strtolower($value);
				break;
			case 'col-status':
				$current['ok'] = (strtolower($value) == "normal");
				break;
			case 'col-date':
				$current['timestamp'] = intval($element->firstChild->getAttribute('data-epoch'));
				break;
		}
		$versions[$index] = $current;
	}

	// Filter out the version we found
	$addon->available = array();
	foreach($versions as $current) {
		if(!$current['ok'] || ($current['nolib'] && !@$addon->wantNolib)) {
			continue;
		}
		$kind = $current['kind'] . ($current['nolib'] ? '-nolib' : '');
		if(!isset($addon->available[$kind]) || $current['timestamp'] > $addon->available[$kind]['timestamp']) {
			unset($current['ok']);
			unset($current['nolib']);
			$addon->available[$kind] = $current;
		}
	}

	// Use -nolib wherever available and wanted
	if($addon->wantNolib) {
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
				$content = curl_multi_getcontent($mh);
				if($addon->source == "wowi") {
					parse_wowi_patcher_data($addon, $content);
				} else {
					scrape_wowace_addon_page($addon, $content);
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
		if(isset($addon->newversion) && $addon->newversion != $addon->version) {
			printf("%s: %s ===> %s\n", $addon->name, $addon->version, $addon->newversion);
		} else {
			unset($addons[$key]);
		}
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
	#register_shutdown_function('unlink', $tmpfile);
	$fh = fopen($tmpfile, 'wb');
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
	if($backupDir) {
		$backupPath = "$backupDir/".$addon->project.'-'.$addon->version;
		if(!file_exists($backupPath)) {
			if(mkdir($backupPath, 0755, true)) {
				$failed = false;
				$dirs = $addon->dirs;
				foreach($dirs as $i => $dir) {
					if(!rename("$baseDir/$dir", "$backupPath/$dir")) {
						printf("Cannot backup %s, skipped.", $addon->name);
						for($j = 0; $j < $i; $j++) {
							@rename($backupPath."/".$dirs[$j], "$baseDir/".$dirs[$j]);
						}
						@rmdir($backupPath);
						$failed = true;
						break;
					}
				}
				if($failed) continue;
			} else {
				printf("Cannot backup %s, skipped.", $addon->name);
				continue;
			}
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
			file_put_contents($baseDir."/".$parts[1]."/.addon-data.ini", $dataStr);
		}
	}
	$za->close();
	@unlink($addon->filename);
	printf("done.\n");
}

?>
