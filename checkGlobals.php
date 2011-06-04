#!/usr/bin/php
<?php

$args = array_slice($_SERVER['argv'], 1);

$_replaceTags = false;
$_inPlace = false;
$files = array();

// Parse each command line
foreach($args as $arg) {
	if($arg == '-u') {
		$_replaceTags = true;
	} elseif($arg == '-i') {
		$_replaceTags = true;
		$_inPlace = true;
	} elseif(file_exists($arg)) {
		$files[] = $arg;
	} else {
		fprintf(STDERR, "invalid filename/option: %s\n", $arg);
		exit(1);
	}
}

if(count($files) == 0) {
	fprintf(STDERR, "no file to process\n");
	exit(2);
}

// Read the API globals, to ignore in main chunks
$api = array();
$fh = fopen(dirname($_SERVER['argv'][0]).DIRECTORY_SEPARATOR."APIGlobals", "rt");
while($word = fgets($fh)) {
	$word = trim($word);
	$api[$word] = true;
}
fclose($fh);

// Common aliases
$aliases = array(
	'tsort' => 'table.sort',
	'tconcat' => 'table.concat',
	'band' => 'bit.band',
	'bor' => 'bit.bor',
	'huge' => 'math.huge',
);

// Parse each file
foreach($files as $file) {

	// Check the syntax and bails out if it failed
	system("luac -p ".escapeshellarg($file), $retVal);
	if($retVal != 0) {
		continue;
	}

	// Get the original flags
	$replaceTags = $_replaceTags;
	$inPlace = $_inPlace;

	// When updating, filter the source
	$acceptLine = true;
	$toParse = $file;
	$hasGlobalTag = false;
	if($replaceTags) {
		$strippedFile =  $file.".parse";;
		$toDelete = $strippedFile;
		$fh2 = fopen($strippedFile, "wt");
	}

	// Read the source, looking for globals to ignore in comments and function names
	$ignore = array('_G' => '_G', 'LibStub' => true, 'AdiDebug' => true, 'AdiProfiler' => true);
	$functions = array();

	$fh = fopen($file, "rt");
	for($num = 1; $line = fgets($fh); $num++) {

		if(preg_match('/^\s*--\s*globals:\s*(.*)\s*$/i', $line, $parts)) {
			// Globals to ignore
			$words = preg_split('/[\s,]+/', $parts[1]);
			foreach($words as $word) {
				$ignore[$word] = true;
			}

		} elseif(preg_match('/function\s+((?:\w+\s*[\.:]\s*)?\w+)/', $line, $parts)) {
			// Fuction definition
			$functions[$num] = $parts[1];

		}

		if($replaceTags) {
			// Check for global tags

			if(preg_match('/^(\s*)\-\-<GLOBALS>/', $line, $parts)) {
				// one-line tag, write opening and closing tags
				$hasGlobalTag = true;
				fprintf($fh2, "%s--<GLOBALS\n", $parts[1]);
				fputs($fh2, "--GLOBALS>\n");

			} elseif(trim($line) == '--<GLOBALS') {
				// Opening tag, replace it with a one-liner and ignore subsequent lines
				$acceptLine = false;
				$hasGlobalTag = true;
				fputs($fh2, $line);

			} elseif(trim($line) == '--GLOBALS>') {
				// Closing tag, start to copy lines again
				$acceptLine = true;
				fputs($fh2, $line);

			} else {
				fputs($fh2, ($acceptLine ? "" : "-- ").$line);
			}
		}
	}
	fclose($fh);

	if($replaceTags) {
		fclose($fh2);
		if($hasGlobalTag) {
			$toParse = $strippedFile;
		} else {
			fputs(STDERR, "$file: no <GLOBALS> tags found, not updating.\n");
			@unlink($strippedFile);
			continue;
		}
	}

	// Always warn for these globals
	$warn = array(
		'math' => true,
		'string' => true,
		'table' => true,
		'bit' => true
	);

	$chunk = null;
	$toLocals = array();

	// Parse the file
	$fh = popen("luac -l -p ".escapeshellarg($toParse), "r");
	while($line = fgets($fh)) {

		if(substr($line,0,4) == "main") {
			// Starting main chunk
			$chunk = 'main chunk';

		} elseif(preg_match('/^function\s+<.*:(\d+),\d+>/', $line, $parts)) {
			// Stating a function
			$lineNum = intval($parts[1]);
			if(isset($functions[$lineNum])) {
				$chunk = 'function '.$functions[$lineNum];
			} else {
				$chunk = 'anonymous function';
			}

		} elseif(preg_match('/\[(\d+)]\\s+([GS]ET)GLOBAL.*;\s*(.*)\s*$/', $line, $parts)) {
			// Access to a global variabale

			list(, $line, $action, $word) = $parts;

			if(isset($ignore[$word])) {
				// Ignore globals to ignore
				continue;
			}

			if($action == 'GET' && (isset($api[$word]) || isset($aliases[$word])) && !isset($warn[$word])) {
				// Getting known API

				if($chunk != 'main' || isset($aliases[$word])) {
					// Want it as an upvalue to prevent _G lookups
					$toLocals[$word] = true;
				}

			} else {
				// Setting a global or getting unknown globals: warning
				fprintf(STDERR, "%s:%d: %sting global '%s' in %s\n", $file, $line, strtolower($action), $word, $chunk);
			}

		}
	}
	pclose($fh);

	// If updating, copy the file up to the tag
	$outputFH = STDOUT;
	$indent = "";
	if($replaceTags) {
		if($inPlace) {
			@rename($file, $file.".bak");
			$outputFH	= fopen($file, "wt");
		}
		$fh = fopen($toParse, "rt");
		while(($line = fgets($fh)) && !preg_match('/^(\s*)\-\-<GLOBALS$/', $line, $parts)) {
			fputs($outputFH, $line);
		}
		$indent = $parts[1];
		fputs($outputFH, $indent."--<GLOBALS\n");

		// Drop lines between the two tags
		while(($line = fgets($fh)) && trim($line) != '--GLOBALS>') {
			// NOP
		}
	}

	if(!empty($toLocals)) {
		// Output the locals
		uksort($toLocals, "strnatcasecmp");
		fprintf($outputFH, $indent."local _G = _G\n");
		foreach($toLocals as $word => $_) {
			fprintf($outputFH, $indent."local %s = _G.%s\n", $word, isset($aliases[$word]) ? $aliases[$word] : $word);
		}
	}

	// If updating, copy the end of file
	if($replaceTags) {
		fputs($outputFH, $indent."--GLOBALS>\n");
		while($line = fgets($fh)) {
			fputs($outputFH, $line);
		}
		fclose($fh);
		if($inPlace) {
			fclose($outputFH);
		}
	}

	// Cleanup
	if(isset($toDelete)) {
		@unlink($toDelete);
	}

}
