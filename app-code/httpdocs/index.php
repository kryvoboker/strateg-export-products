<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

$is_enable_xhprof = false;
$time_zone = getenv('TZ') ?: 'Europe/Bucharest';
$dir_storage = (getenv('NEW_STORAGE_PATH') ?: __DIR__ . '/../storage') . '/';

date_default_timezone_set($time_zone);

if (file_exists($maintenance = $dir_storage . 'framework/maintenance.php') === false && $is_enable_xhprof) {
	$XHPROF_ROOT = $dir_storage . '/../xhprof/';
	$dir_xhprof_stored_runs_data = $XHPROF_ROOT . 'runs_data/';
	$dir_xhprof_stored_runs_links = $dir_storage . 'temp/xhprof_runs_links/';
	$xhprof_links_json = $dir_xhprof_stored_runs_links . 'xhprof_runs_links.json';
	$xhprof_source = 'xhprof_strateg-export-products';

	if (!is_dir($dir_xhprof_stored_runs_links)) {
		mkdir($dir_xhprof_stored_runs_links, 0755, true);
	}

	if (!is_dir($dir_xhprof_stored_runs_data)) {
		mkdir($dir_xhprof_stored_runs_data, 0755, true);
	}

	if (!file_exists($xhprof_links_json)) {
		touch($xhprof_links_json);
	}

	// start profiling
	xhprof_enable(
		XHPROF_FLAGS_NO_BUILTINS | XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY,
		[
			'ignored_functions' => [
				'call_user_func',
				'call_user_func_array',
			],
		],
	);
}

define('LARAVEL_START', microtime(true));

try {
	// Determine if the application is in maintenance mode...
	if (file_exists($maintenance = $dir_storage . 'framework/maintenance.php')) {
		require $maintenance;
	}

	// Register the Composer autoloader...
	require './../app/vendor/autoload.php';

	// Bootstrap Laravel and handle the request...
	/** @var Application $app */
	$app = require './../app/bootstrap/app.php';

	$app->handleRequest(Request::capture());
} catch (Throwable $e) {
	file_put_contents(
		'error.log',
		'[' . date('Y-m-d H:i:s') . '] production.ERROR: ' . $e->getMessage() . PHP_EOL,
		FILE_APPEND,
	);
} finally {
	if ($is_enable_xhprof && isset($XHPROF_ROOT) && isset($dir_xhprof_stored_runs_links) && isset($xhprof_source)) {
		// stop profiler
		$xhprof_data = xhprof_disable();

		include_once $XHPROF_ROOT . 'xhprof_lib/utils/xhprof_lib.php';
		include_once $XHPROF_ROOT . 'xhprof_lib/utils/xhprof_runs.php';

		// save raw data for this profiler run using default
		// implementation of iXHProfRuns.
		/** @phpstan-ignore-next-line */
		$xhprof_runs = new XHProfRuns_Default();

		// save the run under a namespace "xhprof_foo"
		$run_id = $xhprof_runs->save_run($xhprof_data, "$xhprof_source");

		$runs_links = (array)json_decode(
			(string)file_get_contents($dir_xhprof_stored_runs_links . 'xhprof_runs_links.json'),
			true,
		);

		$link = 'http://' . $_SERVER['HTTP_HOST'] . "/xhprof/xhprof_html/index.php?run=$run_id&source=$xhprof_source";

		$runs_links[date('Y-m-d H:i:s')] = $link;

		if (count($runs_links) > 1) {
			uksort(
				$runs_links,
				fn (string $date_one, string $date_two): int => strtotime($date_one) <=> strtotime($date_two),
			);
		}

		file_put_contents(
			$dir_xhprof_stored_runs_links . 'xhprof_runs_links.json',
			json_encode($runs_links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		);

		/*echo "---------------\n" .
			"Assuming you have set up the http based UI for \n" .
			"XHProf at some address, you can view run at \n" .
			"http://<xhprof-ui-address>/index.php?run=$run_id&source=$xhprof_source\n" .
			"---------------\n";*/
	}
}