<?php

/*
 * CLI script that takes a path as first parameter
 * The path must exist and must be a directory
 * Opens a non-recursive directory iterator on it
 * For each directory, opens a recursive directory iterator on that directory
 * Allow to set a $ignored_directories array, that if the basename is present, skip it
 * For the main directory and for each recursive directory, iterate on the PHP files
 * Open the PHP file and loop through the lines
 * If the line contains "WPCS:", capture everything starting from "WPCS:" until the end of the line
 * Explode the captured string by the comma
 * Iterate on the exploded array, looking for replacements, like "XSS ok." to "WordPress.Security.EscapeOutput.OutputNotEscaped"
 * Print the list of the would-be changes.
 */

// Ensure a path is provided as the first argument
if ( $argc < 2 ) {
	echo "Usage: php script.php <path_to_directory>\n";
	exit( 1 );
}

$path = $argv[1];

// Check if the path exists and is a directory
if ( ! is_dir( $path ) ) {
	echo "The specified path must exist and be a directory.\n";
	exit( 1 );
}

// Array of directories to ignore
$ignored_directories = [ 'node_modules', 'vendor' ];

// Create a non-recursive directory iterator for the main directory
$dir_iterator = new DirectoryIterator( $path );

$GLOBALS['wpcs_stats'] = [
	'wpcs_comments_found'    => 0,
	'wpcs_comments_modified' => 0,
];

register_shutdown_function( static function () {
	echo "WPCS comments found: " . $GLOBALS['wpcs_stats']['wpcs_comments_found'] . "\n";
	echo "WPCS comments modified: " . $GLOBALS['wpcs_stats']['wpcs_comments_modified'] . "\n";
} );

foreach ( $dir_iterator as $fileinfo ) {
	if ( $fileinfo->isDir() && ! $fileinfo->isDot() ) {
		$basename = $fileinfo->getBasename();

		// Skip ignored directories
		if ( in_array( $basename, $ignored_directories ) ) {
			continue;
		}

		// Create a recursive iterator for each subdirectory
		$recursiveIterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $fileinfo->getPathname() ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $recursiveIterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				validateAndProcessFile( $file->getPathname() );
			}
		}
	}
}

function validateAndProcessFile( $filename ) {
	$contents         = file_get_contents( $filename );
	$originalContents = $contents;
	$updatedContents  = processPhpContents( $contents, $filename );

	if ( $originalContents !== $updatedContents ) {
		$tempFileName = tempnam( sys_get_temp_dir(), 'php_syntax_check' );
		file_put_contents( $tempFileName, $updatedContents );
		if ( ! checkSyntax( $tempFileName ) ) {
			unlink( $tempFileName );
			throw new Exception( "Syntax error would occur after processing $filename" );
		}
		unlink( $tempFileName );

		echo "Changes in $filename:\n";
		printChanges( $originalContents, $updatedContents );
	}
}

function processPhpContents( $contents, $filename ) {
	$lines    = explode( "\n", $contents );
	$newLines = [];

	$tracked_by_qit = [
		'input var ok, sanitization ok' => 'WordPress.Security.ValidatedSanitizedInput.InputNotSanitized',
		'XSS ok'                        => 'WordPress.Security.EscapeOutput.OutputNotEscaped',
		'input var ok'                  => 'WordPress.Security.ValidatedSanitizedInput.InputNotSanitized',
		'csrf ok'                       => '',
		'CSRF ok'                       => '',
		'unprepared SQL ok'             => 'WordPress.DB.PreparedSQL.NotPrepared',
		'sanitization ok'               => 'WordPress.Security.ValidatedSanitizedInput.InputNotSanitized',
	];

	$not_tracked_by_qit = [
		'cache ok'      => 'WordPress.DB.DirectDatabaseQuery.NoCaching',
		'DB call ok'    => 'WordPress.DB.DirectDatabaseQuery.DirectQuery',
		'slow query ok' => '',
		'override ok'   => '',
	];

	$replacements = [
		'tracked_by_qit'     => $tracked_by_qit,
		'not_tracked_by_qit' => $not_tracked_by_qit,
	];

	foreach ( $lines as $line ) {
		$originalLine = $line;
		if ( strpos( $line, 'WPCS:' ) !== false ) {
			$GLOBALS['wpcs_stats']['wpcs_comments_found'] ++;
			$changed = false;
			foreach ( $replacements as $type => $patterns ) {
				foreach ( $patterns as $old => $new ) {
					if ( $type === 'tracked_by_qit' || strpos( $line, $old ) !== false ) {
						if ( $type === 'not_tracked_by_qit' && strpos( $line, $old ) !== false ) {
							continue; // Skip replacement if line contains both tracked and not tracked patterns
						}

						if ( $old === 'csrf ok' || $old === 'CSRF ok' ) {
							$new = strpos( $line, '$_POST' ) !== false ? 'WordPress.Security.NonceVerification.Missing' : 'WordPress.Security.NonceVerification.Recommended';
						}

						$line    = str_replace( $old, $new, $line );
						$changed = true;
					}
				}
			}
			if ( ! $changed ) {
				throw new Exception( "Unknown pattern detected in $filename on line: $line" );
			} else {
				$line = str_replace( 'WPCS:', 'phpcs:ignore', $line );
			}
		}
		$newLines[] = $line;
		if ( $originalLine !== $line ) {
			$GLOBALS['wpcs_stats']['wpcs_comments_modified'] ++;
			echo "Original: $originalLine\n";
			echo "Updated: $line\n";
		}
	}

	return implode( "\n", $newLines );
}

function printChanges( $original, $updated ) {
	$originalLines = explode( "\n", $original );
	$updatedLines  = explode( "\n", $updated );
	foreach ( $updatedLines as $key => $line ) {
		if ( $line !== $originalLines[ $key ] ) {
			echo "Line " . ( $key + 1 ) . " changed from:\n" . $originalLines[ $key ] . "\nTo:\n" . $line . "\n";
		}
	}
}

function checkSyntax( $filename ) {
	$output = [];
	$result = 0;
	exec( "php -l " . escapeshellarg( $filename ), $output, $result );

	return $result === 0; // Returns true if no syntax errors
}