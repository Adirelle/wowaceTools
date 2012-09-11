#!/usr/bin/php
<?php

//------------------------------------------

// Name of the localization file
$LOCALIZATION_FILE = "Localization.lua";

// Wowace API key
$HOME = getenv("HOME");
if(!$HOME) {
	$HOME = getenv("USERPROFILE");
}
$API_KEY = trim(@file_get_contents($HOME.DIRECTORY_SEPARATOR.".wowaceApiKey"));

//------------------------------------------

error_reporting(-1);

$WORKDIR = getcwd();

$ARG1 = $_SERVER["argv"][1];
if($ARG1) {
	if(is_dir($ARG1)) {
		$WORKDIR = $ARG1;
	} elseif(file_exists($ARG1)) {
		$WORKDIR = dirname($ARG1);
	} else {
		die("Neither a directory nor a file: $ARG1");
	}
}

while(!is_file($WORKDIR.DIRECTORY_SEPARATOR.$LOCALIZATION_FILE)) {
	$WORKDIR = dirname($WORKDIR);
	if(!is_dir($WORKDIR)) {
		die("No Localization.lua file to update !\n");
	}
}

echo "Working in $WORKDIR\n";
chdir($WORKDIR);

class MyFilter extends RecursiveFilterIterator {
	public function accept() {
		$iter = $this->getInnerIterator();
		$basename = $iter->getBaseName();
		if($basename{0} == ".") {
			return false;
		}
		if($iter->isfile()) {
			return substr($basename, strlen($basename)-4) == ".lua";
		} elseif($iter->isdir()) {
			return strtolower($basename) != "libs";
		}
	}
}

function normalizeString($str, $delim) {
	if($delim == "'") {
		$str = str_replace("\\'", "'", $str);
		$str = str_replace('"', '\\"', $str);
	}
	return '"'.$str.'"';
}

$iter = new RecursiveIteratorIterator(new MyFilter(new RecursiveDirectoryIterator(".")));
$files = array();
foreach($iter as $file) {
	$files[] = str_replace(".".DIRECTORY_SEPARATOR, "", $file->getPathname());
}
sort($files);
printf("Found %d source files:\n- %s\n", count($files), join($files, "\n- "));

$strings = array();
$seen = array();
foreach($files as $file) {
	$ignore = false;
	foreach(file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
		if(strpos($line, '@noloc[[') !== FALSE) {
			// contains "@noloc]]"
			$ignore = true;

		} elseif(strpos($line, '@noloc]]') !== FALSE) {
			// constains "@noloc]]"
			$ignore = false;

		} elseif(!$ignore && strpos($line, '@noloc') === FALSE) {
			// not ignored and doesn't contain "@noloc"

			if(preg_match('/L\[([\'"])(.+?)\1\]\s*=\s*([\'"])(.+?)\3/i', $line, $parts)) {
				// L["somestr"] = "otherstr"
				$str = normalizeString($parts[2], $parts[1]);
				$value = normalizeString($parts[4], $parts[3]);
				if(isset($seen[$str])) {
					unset($strings[$seen[$str]][$str]);
				}
				$strings[$file][$str] = $value;
				$seen[$str] = $file;

			} elseif(preg_match_all('/L\[([\'"])(.+?)\1\]/', $line, $matches, PREG_SET_ORDER)) {
				// L["somestr"] L["otherstr"]
				foreach($matches as $match) {
					$str = normalizeString($match[2], $match[1]);
					if(!isset($seen[$str])) {
						$strings[$file][$str] = true;
						$seen[$str] = $file;
					}
				}
			}
		}
	}
}
$strings = array_filter($strings);
printf("Found %d string(s) in %s file(s).\n", count($seen), count($strings));

function buildStrings($strings, $withComments = true) {
	$lines = array();
	foreach($strings as $file => $strs) {
		if($withComments) {
			$lines[] = "\n-- ".str_replace(DIRECTORY_SEPARATOR, '/', $file)."\n";
			ksort($strs);
		}
		foreach($strs as $k => $v) {
			if($v === TRUE) {
				$lines[] = "L[$k] = true\n";
			} else {
				$lines[] = "L[$k] = $v\n";
			}
		}
	}
	if(!$withComments) {
		sort($lines);
	}
	return join($lines, "");
}

$success = false;

function updateLocales($parts) {
	global $strings, $success, $API_KEY, $LOCALIZATION_FILE;

	@list(, $header, $project, $paramStr) = $parts;

	// Fetch strings to wowace
	if($API_KEY) {
		$CURLEXT = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'php_curl' : 'curl';
		if(extension_loaded($CURLEXT) || dl($CURLEXT)) {
			print("Importing enUS strings into wowace localization system: "); flush();
			$ch = curl_init("http://www.wowace.com/addons/$project/localization/import/?api-key=".$API_KEY);
			curl_setopt_array($ch, array(
				CURLOPT_FAILONERROR => true,
				CURLOPT_POST => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POSTFIELDS => array(
					"language" => 1,
					"format" => "lua_additive_table",
					"delete_unimported" => "y",
					"text" => buildStrings($strings, false),
				)
			));
			if(curl_exec($ch) === FALSE) {
				print(curl_error($ch)."\n");
			} else {
				print("done.\n");
			}
			curl_close($ch);
		}
	}

	print("Updating $LOCALIZATION_FILE:\n");

	// Build the base url, including additional parameters
	$params = array(
		"namespace" => '',
		"format" => 'lua_additive_table',
		"handle_unlocalized" => 'ignore',
		"escape_non_ascii" => null,
		"handle_subnamespaces" => 'none',
	);
	if(!empty($paramStr) && preg_match_all("/(\w+)\s*=\s*(\S+)/", $paramStr, $matches, PREG_SET_ORDER)) {
		foreach($matches as $param) {
			$params[$param[1]] = $param[2];
		}
	}
	$baseUrl = "http://www.wowace.com/addons/$project/localization/export.txt?";
	if(!empty($params)) {
		$baseUrl .= http_build_query($params)."&";
	}
	$baseUrl .= "language=";

	// Header
	$lines = array();
	$lines[] = trim($header);
	$lines[] = "
-- THE END OF THE FILE IS UPDATED BY https://github.com/Adirelle/wowaceTools/#updatelocalizationphp.
-- ANY CHANGE BELOW THESES LINES WILL BE LOST.
-- UPDATE THE TRANSLATIONS AT http://www.wowace.com/addons/$project/localization/
-- AND ASK THE AUTHOR TO UPDATE THIS FILE.

-- @noloc[[
";

	// English strings, from source files
	unset($strings[$LOCALIZATION_FILE]);
	$enUSstrings = buildStrings($strings);
	if(!empty($enUSstrings)) {
		$lines[] = "
------------------------ enUS ------------------------

$enUSstrings
";
	}

	// Add other locales, from wowace localization pages
	$first = true;
	foreach(array("frFR", "deDE", "esMX", "ruRU", "esES", "zhTW", "zhCN", "koKR", "ptBR") as $lang) {
		print("- fetching $lang locales: "); flush();
		$langStrings = file_get_contents($baseUrl.$lang);
		$lines[] = "\n------------------------ $lang ------------------------\n";
		if(!empty($langStrings)) {
			if($first) {
				$lines[] = "local locale = GetLocale()\nif";
				$first = false;
			} else {
				$lines[] = "elseif";
			}
			$lines[] = " locale == '$lang' then\n".$langStrings;
			print("done, translations added.\n");
		} else {
			print("done, no translation.\n");
			$lines[] = "-- no translation\n";
		}
	}
	if(!$first) {
		$lines[] = "end\n";
	}

	// Bottom
	$lines[] = "
-- @noloc]]

-- Replace remaining true values by their key
for k,v in pairs(L) do if v == true then L[k] = k end end
";

	$success = true;
	return join($lines, "");
}

$locales = preg_replace_callback(
	'/(%Localization:\s+([^\s]+)\s*([^\s]+)?\s*\n).*$/is',
	"updateLocales",
	file_get_contents($LOCALIZATION_FILE)
);

if($success) {
	print("Saving $LOCALIZATION_FILE\n");
	@unlink("$LOCALIZATION_FILE.~localebak~");
	@rename($LOCALIZATION_FILE, "$LOCALIZATION_FILE.~localebak~");
	file_put_contents($LOCALIZATION_FILE, $locales);
}

?>
