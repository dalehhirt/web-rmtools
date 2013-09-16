<?php
include __DIR__ . '/../data/config.php';
include __DIR__ . '/../include/PeclBranch.php';
include __DIR__ . '/../include/Tools.php';
include __DIR__ . '/../include/PeclExt.php';

use rmtools as rm;


$shortopts = NULL; //"c:p:mu";
$longopts = array("config:", "package:", "mail", "upload", "is-snap", "force-name:", "force-version:", "force-email:");

$options = getopt($shortopts, $longopts);

$branch_name = isset($options['config']) ? $options['config'] : NULL;
$pkg_path = isset($options['package']) ? $options['package'] : NULL;
$mail_maintainers = isset($options['mail']);
$upload = isset($options['upload']);
$is_snap = isset($options['is-snap']);
$force_name = isset($options['force-name']) ? $options['force-name'] : NULL;
$force_version = isset($options['force-version']) ? $options['force-version'] : NULL;
$force_email = isset($options['force-email']) ? $options['force-email'] : NULL;

if (NULL == $branch_name || NULL == $pkg_path) {
	echo "Usage: pecl.php [OPTION] ..." . PHP_EOL;
	echo "  --config         Configuration file name without suffix, required." . PHP_EOL;
	echo "  --package        Path to the PECL package, required." . PHP_EOL;
	echo "  --mail           Send build logs to the extension maintainers, optional." . PHP_EOL;
	echo "  --upload         Upload the builds to the windows.php.net, optional." . PHP_EOL;
	echo "  --is-snap        We upload to releases by default, but this one goes to snaps, optional." . PHP_EOL;
	echo "  --force-name     Force this name instead of reading the package data, optional." . PHP_EOL;
	echo "  --force-version  Force this version instead of reading the package data, optional." . PHP_EOL;
	echo "  --force-email    Send the results to this email instead of any from package.xml, optional." . PHP_EOL;
	echo PHP_EOL;
	echo "Example: pecl --config=php55_x64 --package=c:\pecl_in_pkg\some-1.0.0.tgz" . PHP_EOL;
	echo PHP_EOL;
	exit(0);
}

define('MAIL_FROM', 'pecl-dev@lists.php.net');
define('MAIL_TO_FALLBACK', 'ab@php.net');

$config_path = __DIR__ . '/../data/config/pecl/' . $branch_name . '.ini';

$branch = new rm\PeclBranch($config_path);

$branch_name = $branch->config->getName();

echo "Run started for <$config_path>" . PHP_EOL;
echo "Branch <$branch_name>" . PHP_EOL;

$build_dir_parent = $branch->config->getBuildLocation();

if (!is_dir($build_dir_parent)) {
	echo "Invalid build location <$build_dir_parent>" . PHP_EOL;
	exit(-1);
}

$builds = $branch->getBuildList('windows');

/* be optimistic */
$was_errors = false;

echo "Using <$pkg_path>" . PHP_EOL . PHP_EOL;

/* Each windows configuration from the ini for the given PHP version will be built */
foreach ($builds as $build_name) {

	$build_error = 0;

	echo "Preparing to build" . PHP_EOL;

	$build_src_path = realpath($build_dir_parent . DIRECTORY_SEPARATOR . $branch->config->getBuildSrcSubdir());
	$log = rm\exec_single_log('mklink /J ' . $build_src_path . ' ' . $build_src_path);

	$build = $branch->createBuildInstance($build_name);
	$build->setSourceDir($build_src_path);

	try {
		$ext = new rm\PeclExt($pkg_path, $build);
	} catch (Exception $e) {
		echo 'Error: ' . $e->getMessage() . PHP_EOL;

		if ($mail_maintainers) {
			$maintainer_mailto = $force_email ? $force_email: MAIL_TO_FALLBACK;

			echo "Mailing info to <$maintainer_mailto>" . PHP_EOL;

			rm\xmail(
				MAIL_FROM,
				/* no chance to have the maintainers mailto at this stage */
				$maintainer_mailto,
				'[PECL-DEV] Windows build: ' . basename($pkg_path),
				"PECL build failed before it could start for the reasons below:\n\n" . $e->getMessage()
			);
		}

		$build->clean();
		$was_errors = true;

		unset($ext);
		unset($build);

		/* no sense to continue as something in ext setup went wrong */
		continue;
	}

	try {
		$ext->init($force_name, $force_version);
		$ext->setupNonCoreExtDeps();
		$ext->putSourcesIntoBranch();
	} catch (Exception $e) {
		echo 'Error: ' . $e->getMessage() . PHP_EOL;
		$was_errors = true;

		if ($mail_maintainers) {
			$maintainer_mailto = $force_email;
			if (!$maintainer_mailto) {
				$maintainer_mailto = $ext->getToEmail();
				if (!$maintainer_mailto) {
					$maintainer_mailto = MAIL_TO_FALLBACK;
				}
			}

			echo "Mailing info to <$maintainer_mailto>" . PHP_EOL;

			rm\xmail(
				MAIL_FROM,
				$maintainer_mailto,
				'[PECL-DEV] Windows build: ' . basename($pkg_path),
				"PECL build failed before it could start for the reasons below:\n\n" . $e->getMessage()
			);
		}

		$build->clean();
		$ext->clean();
		$was_errors = true;

		unset($ext);
		unset($build);

		/* no sense to continue as something in ext setup went wrong */
		continue;
	}

	// looks like php_http-2.0.0beta4-5.3-nts-vc9-x86
	$ext_build_name = $ext->getPackageName();

	$toupload_dir = TMP_DIR . '/' . $ext_build_name;
	if (!is_dir($toupload_dir)) {
		mkdir($toupload_dir, 0655, true);
	}

	if (!is_dir($toupload_dir . '/logs')) {
		mkdir($toupload_dir . '/logs', 0655, true);
	}

 	echo "Configured for <$ext_build_name>" . PHP_EOL;
	echo "Running build in <$build_src_path>" . PHP_EOL;
	try {
		$build->buildconf();

		$ext_conf_line = $ext->getConfigureLine();
		echo "Extension specific config: $ext_conf_line" . PHP_EOL;
		if ($branch->config->getPGO() == 1)  {
			echo "Creating PGI build" . PHP_EOL;
			$build->configure(' "--enable-pgi" ' . $ext_conf_line);
		}
		else {
			$build->configure($ext_conf_line);
		}

		if (!preg_match(',^\|\s+' . $ext->getName() . '\s+\|\s+shared\s+\|,Sm', $build->log_configure)) {
			throw new Exception($ext->getName() . ' is not enabled, skip make phase');
		}

		$build->make();
		//$html_make_log = $build->getMakeLogParsed();
	} catch (Exception $e) {
		echo 'Error: ' . $e->getMessage() . PHP_EOL;
		$build_error++;
	}

	/* XXX PGO stuff would come here */

	$log_base = $toupload_dir . '/logs';
	$buildconf_log_fname = $log_base . '/buildconf-' . $ext->getPackageName() . '.txt';
	$configure_log_fname = $log_base . '/configure-' . $ext->getPackageName() . '.txt';
	$make_log_fname = $log_base . '/make-' . $ext->getPackageName() . '.txt';
	$error_log_fname = NULL;

	file_put_contents($buildconf_log_fname, $build->log_buildconf);
	file_put_contents($configure_log_fname, $build->log_configure);
	file_put_contents($make_log_fname, $build->log_make);

	$stats = $build->getStats();

	if ($stats['error'] > 0) {
		$error_log_fname = $log_base . '/error-' . $ext->getPackageName() . '.txt';
		file_put_contents($error_log_fname, $build->compiler_log_parser->getErrors());
	}

	try {
		echo "Packaging the binaries" . PHP_EOL;
		$pkg_file = $ext->preparePackage();
	} catch (Exception $e) {
		echo 'Error: ' . $e->getMessage() . PHP_EOL;
		$build_error++;
	}

	try {
		echo "Packaging the logs" . PHP_EOL;
		$logs_zip = $ext->packLogs(
			array(
				$buildconf_log_fname,
				$configure_log_fname,
				$make_log_fname,
				$error_log_fname,
			)
		);
	} catch (Exception $e) {
		echo 'Error: ' . $e->getMessage() . PHP_EOL;
		$build_error++;
	}

	$upload_success = true;
	if ($upload) {
		try {
			$root = $is_snap ? 'snaps' : 'releases';
			$target = '/' . $root . '/' .  $ext->getName() . '/' . $ext->getVersion();

			$pkgs_to_upload = $build_error ? array() : array($pkg_file);

			if ($build_error) {
				echo "Uploading logs" . PHP_EOL;
			} else {
				echo "Uploading '$pkg_file' and logs" . PHP_EOL;
			}

			if ($build_error && !isset($logs_zip)) {
				throw new Exception("Logs wasn't packaged, nothing to upload");
			}

			if (rm\upload_pecl_pkg_ftp_curl($pkgs_to_upload, array($logs_zip), $target)) {
				echo "Upload succeeded" . PHP_EOL;
			} else {
				echo "Upload failed" . PHP_EOL;
			}
		} catch (Exception $e) {
			echo 'Error . ' . $e->getMessage() . PHP_EOL;
			$upload_success = false;
		}
	}

	if ($mail_maintainers) {
		try {
			$maintainer_mailto = $force_email;
			if (!$maintainer_mailto) {
				$maintainer_mailto = $ext->getToEmail();
				if (!$maintainer_mailto) {
					$maintainer_mailto = MAIL_TO_FALLBACK;
				}
			}

			echo "Mailing logs to <$maintainer_mailto>" . PHP_EOL;

			$res = $ext->mailMaintainers(0 == $build_error, $is_snap, array($logs_zip), $maintainer_mailto);
			if (!$res) {
				throw new \Exception("Mail operation failed");
			}
		} catch (Exception $e) {
			echo 'Error: ' . $e->getMessage() . PHP_EOL;
		}
	} 

	$build->clean();
	$ext->cleanup($upload && $upload_success);
	rm\rmdir_rf($toupload_dir);

	unset($ext);
	unset($build);

	echo "Done." . PHP_EOL . PHP_EOL;

	$was_errors = $was_errors || $build_error > 0;
}

echo "Run finished." . PHP_EOL . PHP_EOL;

exit((int)$was_errors);
