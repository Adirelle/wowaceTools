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
if(NULL === shell_exec("git --version")) {
	echo "Cannot execute git !\n";
	$failed = true;
}
if(NULL === shell_exec("svn --version")) {
	echo "Cannot execute svn !\n";
	$failed = true;
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
$maxConcurrent = 50;

// Do not really modify the files
$dryRun = false;

// Forcefully updated
$forceUpdate = false;

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
	$backupDir = null;
}

$defaultCurlOptions = array(
	CURLOPT_FOLLOWLOCATION => true,
	CURLOPT_AUTOREFERER => true,
	CURLOPT_FAILONERROR => true,
	CURLOPT_RETURNTRANSFER => true,
);

function cleanupVersion($version, $addon)  {
	return preg_replace('/^('.preg_quote($addon->name).'|'.preg_quote($addon->project).')[\s\-]*/i', '', $version);
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

// Scan the directories
$directories = array();
$dh = opendir($baseDir);
while($entry = readdir($dh)) {
	$path = "$baseDir/$entry/";

	// Skip anything that is not an addon
	if(!is_dir("$baseDir/$entry") || !file_exists("$path$entry.toc") || substr($entry, 0, 10) == "Blizzard_") {
		continue;
	}

	// Parse addon TOC file
	$toc = array();
	foreach(file($path.$entry.'.toc') as $line) {
		$line = trim($line);
		if(preg_match('/^[\x{fffe}\x{feff}]?##\s*(\S+)\s*:\s*(.+)\s*$/ui', $line, $parts)) {
			list(, $name, $value) = $parts;
			$toc[$name] = $value;
		} else {
			$toc[] = $line;
		}
	}

	// Parse Wowace's .pkgmeta
	if(file_exists($path.'.pkgmeta')) {
		require_once(__DIR__.'/lib/sfyaml/lib/sfYaml.php');
		try {
			$pkgmeta = sfYaml::load(str_replace("\t", "  ", file_get_contents($path.'.pkgmeta')));
		} catch(InvalidArgumentException $e) {
			printf("Error parsing %s/.pgkmeta: %s\n", $entry, $e->getMessage());
			continue;
		}
	} else {
		$pkgmeta = null;
	}

	// Data with mandatory values
	$directories[$entry] = array(
		'path'      => $path,
		'mainDir'   => $entry,
		'dirs'      => array($entry),
		'isSource'  => (is_dir($path.'.git') ? 'git' : (is_dir($path.'.svn') ? 'svn' : false)),
		'pkgmeta'   => empty($pkgmeta) ? null : $pkgmeta,
		'toc'       => $toc,
	);
}
closedir($dh);

// Merge dirs with "X-Part-Of" headers
foreach($directories as $dir => $data) {
	if(isset($data['toc']['X-Part-Of'])) {
		$target = $data['toc']['X-Part-Of'];
		if(isset($directories[$target])) {
			$directories[$target]['dirs'][] = $dir;
			unset($directories[$dir]);
		}
	}
}

// Merge dirs using .pkgmeta
foreach($directories as $entry => $data) {
	if(isset($data['pkgmeta']['package-as'])) {
		$directories[$entry]['mainDir'] = $data['pkgmeta']['package-as'];
	}
	if(isset($data['pkgmeta']['move-folders'])) {
		foreach($data['pkgmeta']['move-folders'] as $target) {
			if(isset($directories[$target])) {
				if(!in_array($target, $directories[$entry]['dirs'])) {
					$directories[$entry]['dirs'][] = $target;
				}
				unset($directories[$target]);
			}
		}
	}
}

$wowaceCount = 0;
$wowiCount = 0;
$sources = array();
$unknowns = array();

foreach($directories as $entry => $data) {
	$path = $data['path'];
	$headers = $data['toc'];

	$addon = new StdClass();
	$addon->mainDir = $data['mainDir'];
	$addon->dirs = $data['dirs'];
	$addon->wantNolib = $wantNolib;
	$addon->kind = @$headers['X-WU-Kind'];
	$addon->source = @$headers['X-WU-Source'];
	$addon->project = @$headers['X-WU-Project'];
	$addon->name = (@$headers['X-WU-Name'] ?: @$headers['Title']) ?: $data['mainDir'];
	$addon->version = @$headers['Version'];

	if(isset($headers['X-Curse-Project-ID']) && isset($headers['X-Curse-Packaged-Version'])) {
		$addon->source = "wowace";
		$addon->project = $headers['X-Curse-Project-ID'];
		$addon->version = $headers['X-Curse-Packaged-Version'];
		$addon->name = $headers['X-Curse-Project-Name'];
	} elseif(isset($headers['X-WoWI-ID'])) {
		$addon->project = $headers['X-WoWI-ID'];
		$addon->source = "wowi";
	}

	if(isset($headers['X-WU-Version'])) {
		$addon->version = $headers['X-WU-Version'];
	}

	if($data['isSource'] == 'git') {
		$addon->source = "git";
		$sources[] = $addon;
		$gitDir = escapeshellarg("$baseDir/".$data['mainDir']."/.git");
		$gitCmd = "git --git-dir=$gitDir";
		$output = shell_exec("$gitCmd remote -v");
		if($output && preg_match('@wowace\.com\:wow/([^/]+)/mainline@ms', $output, $parts)) {
			$addon->project = $parts[1];
			$addon->version = trim(shell_exec("$gitCmd rev-parse --abbrev-ref HEAD"));
		}

	} elseif($data['isSource'] == 'svn') {
		$addon->source = "svn";
		$sources[] = $addon;
		$svnDir = escapeshellarg("$baseDir/".$data['mainDir']);
		$output = shell_exec("svn info --xml $svnDir");
		if($output) {
			$xml = simplexml_load_string($output);
			$url = strval($xml->entry->repository->root);
			if(preg_match('@wowace\.com/wow/([^/]+)/mainline@', $url, $parts)) {
				$addon->project = $parts[1];
				$addon->version = 'r'.strval($xml->entry['revision']);
			}
		}
	}

	// Ignore unidentified projects
	if(empty($addon->project)) {
		if($data['isSource']) {
			$sources[] = $entry;
		} else {
			$unknowns[] = $entry;
		}
		continue;
	}

	// Cleanup the version
	if(isset($addon->version)) {
		$addon->version = cleanupVersion($addon->version, $addon);
	} else {
		$addon->version = "unknown";
	}

	if(!isset($addons[$addon->project])) {
		$addons[$addon->project] = $addon;
		if($addon->source == "wowace") {
			$wowaceCount++;
		} elseif($addon->source == "wowi") {
			$wowiCount++;
		}
	} else {
		$addon = $addons[$addon->project];
	}

	// Merge directory list
	if(!in_array($entry, $addon->dirs)) {
		$addon->dirs = array_unique(array_merge($addon->dirs, $data['dirs']));
	}
}

// Add libraries from .pkgmeta
foreach($directories as $entry => $data) {
	if(isset($data['pkgmeta']['externals'])) {
		foreach($data['pkgmeta']['externals'] as $external) {
			$url = is_array($external) ? $external['url'] : $external;
			if(preg_match('@wowace\.com[/:]wow/([^/]+)/mainline@', $url, $parts)) {
				$id = $parts[1];
				if(!isset($addons[$id])) {
					echo "$entry => ${parts[1]}\n";
					$addon = new StdClass();
					$addon->dirs = array();
					$addon->wantNolib = $wantNolib;
					$addon->source = 'wowace';
					$addon->project = $id;
					$addon->name = $id;
					$addon->version = null;
					$addons[$id] = $addon;
				}
			}
		}
	}
}

function addonSort($a, $b) {
	return strcasecmp($a->name, $b->name);
}
uasort($addons, "addonSort");

//sort($sources);
sort($unknowns);

printf("Found %d addons:
- wowace: %d
- wowinterface: %d
- sources: %d
- unknown: %d %s
",
	count($addons) + count($sources) + count($unknowns),
	$wowaceCount,
	$wowiCount,
	count($sources),
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
		unset($addons[$key]);
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
				$version = cleanupVersion($value, $addon);
				if(!empty($version)) {
					preg_match('@^(.+?)(-nolib)?$@i', $version, $versionParts);
					$current['version'] = $version;
					$current['baseVersion'] = $versionParts[1];
					$current['nolib'] = !empty($versionParts[2]);
					$current['link'] = 'http://www.wowace.com' . $element->firstChild->getAttribute('href');
				}
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
		if(!empty($current['version'])) {
			$versions[$index] = $current;
		}
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
		if(!$forceUpdate && $addon->version == $selected['version']) {
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
		if(isset($addon->newversion) && ($forceUpdate || $addon->newversion != $addon->version)) {
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
	$tmpfile = tempnam('/tmp', preg_replace('/\W+/', '_', $filename).'-');
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

// header from addon data mapping
$headerMap = array(
	'Project' => 'project',
	'Name'    => 'name',
	'Source'  => 'source',
	'Version' => 'newversion',
	'Kind'    => 'kind'
);

function rrmdir($dir) {
	if(is_dir($dir)) {
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::CHILD_FIRST);
		foreach($iterator as $path => $info) {
			if($info->isDir()) {
				rmdir($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	} elseif(file_exists($dir)) {
		unlink($dir);
	}
}

// Backup the old files and install the new ones
foreach($addons as $key => $addon) {
	printf("Installing %s %s: ", $addon->name, $addon->newversion); flush();

	$za = new ZipArchive();
	if(TRUE !== ($err = $za->open($addon->filename))) {
		printf("Cannot open the zip archive %s: %d !\n", $addon->filename, $err);
		continue;
	}

	// Extract the zip files into a temporary directory
	$extractDir = "$baseDir/".$addon->source."-".$addon->project."-new";
	register_shutdown_function("rrmdir", $extractDir);
	if(!$za->extractTo($extractDir)) {
		printf("Cannot extract file from the zip archive %s !\n", $addon->filename);
		$za->close();
		continue;
	}

	// Build additioanl TOC data
	$headers = array();
	foreach($headerMap as $hdr => $prop) {
		if(!empty($addon->$prop)) {
			$headers[] = sprintf("## X-WU-%s: %s", $hdr, $addon->$prop);
		}
	}
	if(!empty($headers)) {
		$headers = "\n# Added by ".basename(__FILE__).":\n".join("\n", $headers)."\n";
	} else {
		$headers = null;
	}

	// Update extracted files
	for($index = 0; $index < $za->numFiles; $index++) {
		$entry = $za->statIndex($index);
		if($headers && preg_match('@^([^/]+)/\1\.toc$@i', $entry['name'])) {
			file_put_contents("$extractDir/".$entry['name'], $headers, FILE_APPEND);
		}
		if($entry['mtime']) {
			@touch("$extractDir/".$entry['name'], $entry['mtime']);
		}
	}
	$za->close();
	@unlink($addon->filename);

	if($dryRun) {
		echo "dry-run, skipped !\n"; flush();
		continue;
	}

	// Track status and installed/saved files
	$failed = false;
	$saveDir = "$baseDir/".$addon->source."-".$addon->project."-save";
	$saved = array();
	$installed = array();

	// Move the old files out of the way
	if(mkdir($saveDir)) {
		foreach($addon->dirs as $dir) {
			if(@rename("$baseDir/$dir", "$saveDir/$dir")) {
				$saved[] = $dir;
			} else {
				$failed = true;
				break;
			}
		}
	} else {
		$failed = true;
	}

	// Move the new files in place
	if(!$failed) {
		foreach(new FilesystemIterator($extractDir) as $path => $info) {
			$dir = $info->getFilename();
			if(file_exists("$baseDir/$dir")) {
				if(is_dir("$baseDir/$dir/.svn") || is_dir("$baseDir/$dir/.git") ) {
					echo "Will not overwrite source directory $dir\n";
					$failed = true;
					break;
				} elseif(@rename("$baseDir/$dir", "$saveDir/$dir")) {
					$saved[] = $dir;
				} else {
					echo "Could not backup $dir\n";
					$failed = true;
					break;
				}
			}
			if(@rename($path, "$baseDir/$dir")) {
				$installed[]= $dir;
			} else {
				echo "Could not install $dir\n";
				$failed = true;
				break;
			}
		}
	}

	if($failed) {
		echo "FAILED !\n";
		// Put all back in place
		foreach($installed as $dir) {
			rrmdir("$baseDir/$dir");
		}
		foreach($saved as $dir) {
			if(!@rename("$saveDir/$dir", "$baseDir/$dir")) {
				echo "Could not restore $dir !\n";
			}
		}
	} else {
		echo "done.\n";

		// Backup
		if($backupDir) {
			$backupDest = "$backupDir/".$addon->project."-".$addon->version;
			if(!@rename($saveDir, $backupDest)) {
				echo "Could not move old directory $saveDir to backup area\n";
			}
		}
	}

	// Cleanup
	rrmdir($saveDir);

}

?>
