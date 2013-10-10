<?php

namespace rmtools;

include_once __DIR__ . '/../include/Tools.php';
include_once __DIR__ . '/../include/PeclBranch.php';

class PeclExt
{
	protected $pkg_path;
	protected $pkg_basename;
	protected $pkg_fmt;
	protected $name;
	protected $version;
	protected $build;
	protected $tar_cmd = 'c:\apps\git\bin\tar.exe';
	protected $bsdtar_cmd = 'c:\apps\bsdtar\bin\bsdtar.exe';
	protected $gzip_cmd = 'c:\apps\git\bin\gzip.exe';
	protected $bzip2_cmd = 'c:\apps\git\bin\bzip2.exe';
	protected $xz_cmd = 'c:\apps\xzutils\xz.exe';
	protected $zip_cmd = 'c:\php-sdk\bin\zip.exe';
	protected $unzip_cmd = 'c:\php-sdk\bin\unzip.exe';
	protected $deplister_cmd = 'c:\apps\bin\deplister.exe';
	protected $tmp_extract_path = NULL;
	protected $ext_dir_in_src_path = NULL;
	protected $package_xml = NULL;
	protected $configure_data = NULL;
	protected $non_core_ext_deps = array();
	protected $pkg_config = NULL;

	public function __construct($pkg_path, $build)
	{
		if (!file_exists($pkg_path)) {
			throw new \Exception("'$pkg_path' does not exist");
		} 
		
		if ('.tgz' == substr($pkg_path, -4)) {
			$this->pkg_basename = basename($pkg_path, '.tgz');
			$this->pkg_fmt = 'tgz';
		} else if ('.tar.gz' == substr($pkg_path, -7)) {
			$this->pkg_basename = basename($pkg_path, '.tar.gz');
			$this->pkg_fmt = 'tgz';
		} else if ('.tbz' == substr($pkg_path, -4)) {
			$this->pkg_basename = basename($pkg_path, '.tbz');
			$this->pkg_fmt = 'tbz';
		} else if ('.tbz2' == substr($pkg_path, -5)) {
			$this->pkg_basename = basename($pkg_path, '.tbz2');
			$this->pkg_fmt = 'tbz';
		} else if ('.tb2' == substr($pkg_path, -4)) {
			$this->pkg_basename = basename($pkg_path, '.tb2');
			$this->pkg_fmt = 'tbz';
		} else if ('.tar.bz2' == substr($pkg_path, -8)) {
			$this->pkg_basename = basename($pkg_path, '.tar.bz2');
			$this->pkg_fmt = 'tbz';
		} else if ('.txz' == substr($pkg_path, -4)) {
			$this->pkg_basename = basename($pkg_path, '.txz');
			$this->pkg_fmt = 'txz';
		} else if ('.tar.xz' == substr($pkg_path, -7)) {
			$this->pkg_basename = basename($pkg_path, '.tar.xz');
			$this->pkg_fmt = 'txz';
		} else if ('.tar' == substr($pkg_path, -4)) {
			$this->pkg_basename = basename($pkg_path, '.tar');
			$this->pkg_fmt = 'tar';
		} else if ('.zip' == substr($pkg_path, -4)) {
			$this->pkg_basename = basename($pkg_path, '.zip');
			$this->pkg_fmt = 'zip';
		} else {
			throw new \Exception("Unsupported package format. We support zip, pure tarball, tarball compressed with gzip, bzip2 or xz");
		}

		$this->pkg_path = $pkg_path;
		$this->build = $build;

	}

	public function init($force_name = NULL, $force_version = NULL)
	{
		$this->unpack();

		$this->name = $force_name;
		$this->version = $force_version;

		/* Setup some stuff */
		if (!$this->name && $this->package_xml) {
			$this->name = (string)$this->getPackageXmlProperty("name");
		}
		if (!$this->version && $this->package_xml) {
			$this->version = (string)$this->getPackageXmlProperty("version", "release");
		}

		/* Looks a bit strange, but usually we would get a name from package.xml, if not - we're
		   required to ->check() before trying to access config.w32 and that's a hack anyway. */
		$this->check();

		if (!$this->name) {
			/* This is the fallback if there's no package.xml  */
			if (!$this->name) {
				$config_w32_path = $this->tmp_extract_path . DIRECTORY_SEPARATOR . 'config.w32';
				foreach (array('ARG_ENABLE', 'ARG_WITH') as $str) {
					if (preg_match("/$str\s*\(\s*('|\")([a-z0-9_]+)('|\")\s*,/Sm", file_get_contents($config_w32_path), $m)) {
						$this->name = $m[2];
						break;
					}
				}
			}

			/* give up */
			if (!$this->name) {
				throw new \Exception("Couldn't reliably determine the package name, please fix or add package.xml");
			}
		}
		if (!$this->version) {
			$this->version = date('Ymd');
		}

		$config = $this->getPackageConfig();
		/* this ext is known*/
		if (is_array($config)) {
			/* Correct the case where the package.xml contains different name than the config option.
			   That's currently the case with zendopcache vs opcache and pecl_http vs. http. */
			if (isset($config['real_name']) && $this->name != $config['real_name']) {
				$new_path = dirname($this->tmp_extract_path) . '/' . $config['real_name'] . '-' . $this->version;

				rmdir_rf($new_path);
				if (!rename($this->tmp_extract_path, $new_path)) {
					throw new \Exception('Package name conflict, different names in package.xml and config.w32. Tried to solve but failed.');
				}

				$this->tmp_extract_path = $new_path;
				$this->name = $config['real_name'];
			}
		}

		$this->name = strtolower($this->name);
		$this->version = strtolower($this->version);
	}

	public function getPackageName()
	{
		// looks like php_http-2.0.0beta4-5.3-nts-vc9-x86
		return 'php_' . $this->name
			. '-' . $this->version 
			. '-' . $this->build->branch->config->getBranch()
			. '-' . ($this->build->thread_safe ? 'ts' : 'nts')
			. '-' . $this->build->compiler
			. '-' . $this->build->architecture;
	}

	protected function createTmpUnpackDir()
	{
		$tmp_path = tempnam(TMP_DIR, 'pecl');
		unlink($tmp_path);
		if (!file_exists($tmp_path) && !mkdir($tmp_path)) {
			throw new \Exception("Couldn't create temporary dir");
		}

		return $tmp_path;
	}

	public function uncompressTarball($format)
	{
		$tmp_path = $this->createTmpUnpackDir();

		$tmp_name =  $tmp_path . DIRECTORY_SEPARATOR . basename($this->pkg_path);
		if (!copy($this->pkg_path, $tmp_name)) {
			throw new \Exception("Couldn't copy the tarball to '$tmp_name'");
		}

		$tar_name = $this->pkg_basename . '.tar';

		/* The tar/gzip versions from the msys package won't work properly with
		the windows paths, but they will if running those just in the current dir.*/
		$old_cwd = getcwd();

		chdir($tmp_path);

		switch ($format) {
			case 'tgz':
				$uncmd = $this->gzip_cmd;
				$unopts = "-df";
				break;
				
			case 'tbz':
				$uncmd = $this->bzip2_cmd;
				$unopts = "-df";
				break;

			case 'txz':
				$uncmd = $this->xz_cmd;
				$unopts = "-df";
				break;

			case 'tar':
				// pass
				break;

			default:
				throw new \Exception("Unsupported compression format '$format'");
		}

		if ('tar' != $format) {
			$uncompress_cmd = $uncmd . ' ' . $unopts . ' ' . escapeshellarg(basename($this->pkg_path));
			system($uncompress_cmd, $ret);
			if ($ret) {
				$this->cleanup();
				throw new \Exception("Failed to gunzip the tarball");
			}
		}

		/* try with bsdtar first */
		$tar_cmd = $this->bsdtar_cmd . ' -xf ' . escapeshellarg($tar_name);
		system($tar_cmd, $ret);
		if ($ret) {
			/* no fail yet, retry with gnu tar */
			$tar_opts = ' --no-same-owner --no-same-permissions -xf ';
			$tar_cmd = $this->tar_cmd . $tar_opts . escapeshellarg($tar_name);
			system($tar_cmd, $ret);
			if ($ret) {
				/* definitely broken, give up */
				$this->cleanup();
				throw new \Exception("Failed to untar the tarball");
			}
		}
		unlink($tar_name);

		chdir($old_cwd);

		return $tmp_path;
	}

	public function uncompressZip()
	{
		$tmp_path = $this->createTmpUnpackDir();

		$unzip_cmd = $this->unzip_cmd . ' ' . escapeshellarg($this->pkg_path) . ' -d ' . $tmp_path;
		system($unzip_cmd, $ret);
		if ($ret) {
			throw new \Exception("Failed to unzip the package");
		}

		return $tmp_path;
	}

	public function unpack()
	{
		if ($this->tmp_extract_path) {
			/* already unpacked */
			return $this->tmp_extract_path;
		}

		switch ($this->pkg_fmt) {
			case 'tgz':
			case 'tbz':
			case 'txz':
			case 'tar':
				$tmp_path = $this->uncompressTarball($this->pkg_fmt);
			break;

			case 'zip':
				$tmp_path = $this->uncompressZip();
			break;

			default:
				throw new \Exception("Unsupported package format");
		}

		/* XXX what if we would look for subdirs containing config.w32? The subdir
		where the file is has to be the root of the source tree. */
		if (file_exists(realpath($tmp_path . '/' . $this->pkg_basename))) {
			/* This covers the case when the source is in a subdir within a package,
			thats native pecl, git.php.net export too. Github should work too, whereby
			they don't export version and dir names aren't always usable by us. In that
			case is important that the package.xml is inside the source. */
			$this->tmp_extract_path = realpath($tmp_path . '/' . $this->pkg_basename);
		} else {
			/* If one manually packed the source into the root of archive, so be.*/
			$this->tmp_extract_path = realpath($tmp_path);
		}

		$package_xml_path = NULL;
		if (file_exists($tmp_path . DIRECTORY_SEPARATOR . 'package2.xml')) {
			$package_xml_path = $tmp_path . DIRECTORY_SEPARATOR . 'package2.xml';
		} else if (file_exists($tmp_path . DIRECTORY_SEPARATOR . 'package.xml')) {
			$package_xml_path = $tmp_path . DIRECTORY_SEPARATOR . 'package.xml';
		} else if (file_exists($this->tmp_extract_path . DIRECTORY_SEPARATOR . 'package2.xml')) {
			$package_xml_path = $this->tmp_extract_path . DIRECTORY_SEPARATOR . 'package2.xml';
		} else if (file_exists($this->tmp_extract_path . DIRECTORY_SEPARATOR . 'package.xml')) {
			$package_xml_path = $this->tmp_extract_path . DIRECTORY_SEPARATOR . 'package.xml';
		}

		if ($package_xml_path) {
			$this->package_xml = new \SimpleXMLElement($package_xml_path, 0, true);
		}

		$this->pkg_path = NULL;

		return $this->tmp_extract_path;
	}

	public function putSourcesIntoBranch()
	{
		$ext_dir = $this->build->getSourceDir() . DIRECTORY_SEPARATOR . 'ext';

		$this->ext_dir_in_src_path = $ext_dir . DIRECTORY_SEPARATOR . $this->name;
		$res = copy_r($this->tmp_extract_path, $this->ext_dir_in_src_path);
		if (!$res) {
			throw new \Exception("Failed to copy to '{$this->ext_dir_in_src_path}'");
		}

		return $this->ext_dir_in_src_path;
	}

	protected function buildConfigureLine(array $data)
	{
		$ret = '';
		$ignore_main_opt = false;

		if (!isset($data['type'])
			|| !in_array($data['type'], array('with', 'enable'))) {
			throw new \Exception("Unknown extention configure type, expected enable/with");
		}

		$main_opt = '--' . $data['type'] . '-' . str_replace('_', '-', $this->name);

		if (isset($data['opts']) && $data['opts']) {
			$data['opts'] = !is_array($data['opts']) ? array($data['opts']) : $data['opts'];
			foreach($data['opts'] as $opt) {
				if ($opt) {
					/* XXX simple check for opt syntax */
					$ret .= ' "' . $opt . '" ';
				}
				/* the main enable/with option was overridden in the ini */
				if (strstr($opt, "$main_opt=") !== false) {
					$ignore_main_opt = true;
				}
			}
		} else {
			$data['opts'] = array();
		}

		$ignore_main_opt = $ignore_main_opt || isset($data['no_conf']);

		if (!$ignore_main_opt) {
			$ret .=  ' "' . $main_opt . '=shared" ';
		}

		if (isset($data['libs']) && $data['libs']) {
			$data['libs'] = !is_array($data['libs']) ? array($data['libs']) : $data['libs'];
			$deps_path = $this->build->branch->config->getPeclDepsBase();
			$extra_lib = $extra_inc = array();

			foreach($data['libs'] as $lib) {
				if (!$lib) {
					continue;
				}

				$lib_conf = $this->getLibraryConfig($lib);

				$lib_path = $deps_path . DIRECTORY_SEPARATOR . $lib;

				$some_lib_path = $lib_path . DIRECTORY_SEPARATOR . 'lib';
				if (!file_exists($some_lib_path)) {
					throw new \Exception("Path '$some_lib_path' doesn't exist");
				}
				$extra_lib[] = $some_lib_path;	

				$some_lib_inc_path =  $lib_path . DIRECTORY_SEPARATOR . 'include';
				if (!file_exists($some_lib_inc_path)) {
					throw new \Exception("Path '$some_lib_inc_path' doesn't exist");
				}
				$extra_inc[] = $some_lib_inc_path;	

				/* If expand_include not set, consider true. */
				if (!isset($lib_conf['expand_include']) || $lib_conf['expand_include']) {
					$dirs = glob("$some_lib_inc_path/*", GLOB_ONLYDIR);
					foreach ($dirs as $dir) {
						$extra_inc[] = $dir;
					}
				}
			}

			if (!empty($extra_lib)) {
				$ret .= ' "--with-extra-libs=' . implode(';', $extra_lib) . '" '
					. ' "--with-extra-includes=' . implode(';', $extra_inc) . '" ';
			}
		} else {
			$data['libs'] = array();
		}

		if (isset($data['exts']) && $data['exts']) {
			if (empty($this->non_core_ext_deps)) {
				 $this->setupNonCoreExtDeps();
			}
			if (!empty($this->non_core_ext_deps)) {
				$ret .= ' ';
				$ret .= $this->getNonCoreExtDepsConfLines();
			}
		} else {
			$data['exts'] = array();
		}

		$this->configure_data = $data;

		return $ret;
	}

	protected function complexPkgNameMatch($cnf_name) {
		$full_set = array(
			$this->build->branch->config->getBranch(),
			($this->build->thread_safe ? 'ts' : 'nts'),
			$this->build->compiler,
			$this->build->architecture,
		);

if (!function_exists('rmtools\combinations')) {
		function combinations($arr, $level, &$result, $that, $curr=array()) {
			for($i = 0; $i < count($arr); $i++) {
				$new = array_merge($curr, array($arr[$i]));
				if($level == 1) {
					/* preserve order */
					sort($new);
					/* no repititions */
					$new = array_unique($new);
					$name = $that->getName() . '-' . implode('-', $new);
					if (!in_array($name, $result)) {
						$result[] = $name;
					}
				} else {
					combinations($arr, $level - 1, $result, $that, $new);
				}
			}
		}
}

		$names = array();
		for ($i = 0; $i<count($full_set); $i++) {
			combinations($full_set, $i+1, $names, $this);
		}

		foreach ($names as $name) {
			if ($name === $cnf_name) {
				return true;
			}
		}

		return false;
	}

	public function getPackageConfig()
	{
		$config = NULL;

		if ($this->pkg_config) {
			return $this->pkg_config;
		}

		$known_path = __DIR__ . '/../data/config/pecl/exts.ini';
		$exts = parse_ini_file($known_path, true, INI_SCANNER_RAW);

		/* Check for like myext-5.3-ts-vc9-x86 */
		foreach ($exts as $name => $conf) {
			if ($this->complexPkgNameMatch($name)) {
				$config = $conf;
				break;
			}
		}

		/* XXX this here might be redundant */
		if (!$config) {
			foreach ($exts as $name => $conf) {
				if ($name === $this->name) {
					$config = $conf;
					break;
				}
			}
		}

		$this->pkg_config = $config;

		return $config;
	}

	public function getLibraryConfig($name)
	{
		$ret = array();

		$known_path = __DIR__ . '/../data/config/pecl/libs.ini';
		$lib_conf = parse_ini_file($known_path, true, INI_SCANNER_RAW);

		if (isset($lib_conf[$name]) && is_array($lib_conf[$name])) {
			$ret = $lib_conf[$name];
		}

		return $ret;
	}

	public function getConfigureLine()
	{
		/* XXX check if it's enable or with,
			what deps it has
			what additional options it has
			what non core exts it deps on 
		*/

		/* look if it's on the known ext list */
		$config = $this->getPackageConfig();

		/* if it's not known yet, we have to gather the info */
		if (!$config) {
			$config = array();

			$config_w32_path = $this->tmp_extract_path . DIRECTORY_SEPARATOR . 'config.w32';
			foreach (array('enable' => 'ARG_ENABLE', 'with' => 'ARG_WITH') as $arg => $str) {
				if (preg_match(',' . $str .'.*' . str_replace('_', '-', $this->name) . ',Sm', file_get_contents($config_w32_path))) {
					$config['type'] = $arg;
					break;
				}
			}
			if (!isset($config['type'])) {
				throw new \Exception("Couldn't determine whether 'with' or 'enable' configure option to use");
			}

			/* XXX Extension deps have to be checked here using
			$this->package_xml->dependencies; */

			/* XXX Library deps have to be checked using 
			$this->tmp_extract_path . DIRECTORY_SEPARATOR . 'lib_versions.txt'; */
		}

		return $this->buildConfigureLine($config);
	}

	public function prepareLicenseSimple($source, $target, $suffix = NULL)
	{
		$ret = array();
		$base = array(
			"COPYING",
			"COPYRIGHT",
			"LICENSE",
			"README",
		);	

		$names = $base;
		foreach ($base as $nm) {
			$names[] = "$nm*";
			$names[] = strtolower($nm) . "*";
			$names[] = ucfirst(strtolower($nm)) . "*";
		}

		foreach ($names as $name) {
			$pat = $source . DIRECTORY_SEPARATOR . $name;

			$glob = glob($pat);

			if (is_array($glob)) {
				foreach ($glob as $fl) {
					$tgt_fl = $target . DIRECTORY_SEPARATOR
						. strtoupper(basename($fl)) . "." . strtoupper($suffix);
					if (!copy($fl, $tgt_fl)) {
						throw new \Exception("The license file '$fl' "
						. "was found but couldn't be copied into '$tgt_fl'");
					}

					$ret[] = $tgt_fl;
				}
			}
		}

		return $ret;
	}

	public function prepareExtLicense($source, $target, $suffix = NULL)
	{
		$ret = $this->prepareLicenseSimple($source, $target, $suffix);

		if (!$ret) {
			/* No license file, check package.xml*/
			if ($this->package_xml && isset($this->package_xml->license)) {
				if (isset($this->package_xml->license[0]["uri"])) {
					$txt = (string)$this->package_xml->license[0]["uri"];
				} else {
					$txt = (string)$this->package_xml->license[0];
				}

				if (isset($txt)) {
					$fl = $target . DIRECTORY_SEPARATOR . "LICENSE." . strtoupper($suffix);
					file_put_contents($fl, $txt);
					$ret[] = $fl;
				}
			}
		}

		return $ret;
	}

	public function prepareAllDepDlls($dll_file, $target)
	{
		$ret = array();

		/* Walk the deps if any, but look for them in the lib deps folders only.
			the deplister will for sure find something like kernel32.dll,
			but that's not what we need. */
		$depl_cmd = $this->deplister_cmd . ' ' . $dll_file;
		$deps_path = $this->build->branch->config->getPeclDepsBase();
		exec($depl_cmd, $out);
		foreach($out as $ln) {
			$dll_name = explode(',', $ln)[0];
			$dll_file = $target . DIRECTORY_SEPARATOR . $dll_name;
			$pdb_name = basename($dll_name, '.dll') . '.pdb';
			$pdb_file = $target . DIRECTORY_SEPARATOR . $pdb_name;

			foreach ($this->configure_data['libs'] as $lib) {
				$look_for = $deps_path
					. DIRECTORY_SEPARATOR . $lib
					. DIRECTORY_SEPARATOR . 'bin'
					. DIRECTORY_SEPARATOR . $dll_name;

				/* care about the dep libs licenses, the license file should be laying directly
				   in the corresponding lib dir*/
				$ret = array_merge(
					$this->prepareLicenseSimple(
						$deps_path . DIRECTORY_SEPARATOR . $lib,
						$target,
						$lib
					),
					$ret
				);

				if(file_exists($look_for)) {
					if (!copy($look_for, $dll_file)) {
						throw new \Exception("The dependency dll '$dll_name' "
						. "was found but couldn't be copied into '$target'");
					}
					$ret[] = $dll_file;
					/* some dep dll might have another dep :) */
					$ret = array_merge($this->prepareAllDepDlls($look_for, $target), $ret);
				}
				
				$look_for = $deps_path
					. DIRECTORY_SEPARATOR . $lib
					. DIRECTORY_SEPARATOR . 'bin'
					. DIRECTORY_SEPARATOR . $pdb_name;


				if(file_exists($look_for)) {
					if (!copy($look_for, $pdb_file)) {
						throw new \Exception("The dependency pdb '$dll_name' "
						. "was found but couldn't be copied into '$target'");
					}
					$ret[] = $pdb_file;
				}	
			}
		}

		return $ret;
	}

	public function getMultiExtensionNames()
	{
		$ext_names = array($this->name);

		/* config.w32 can contain multiple EXTENTION definitions, which would lead to 
		multiple DLLs be built. */
		$config_w32_path = $this->tmp_extract_path . DIRECTORY_SEPARATOR . 'config.w32';
		$config_w32 = file_get_contents($config_w32_path);
		if (preg_match_all("/EXTENSION\s*\(\s*('|\")([a-z0-9_]+)('|\")\s*,/Sm", $config_w32, $m, PREG_SET_ORDER)) {
			foreach ($m as $r) {
				if (!in_array($r[2], $ext_names)) {
					$ext_names[] = $r[2];
				}
			}
		}

		return $ext_names;
	}

	public function preparePackage()
	{
		$sub = $this->build->thread_safe ? 'Release_TS' : 'Release';
		$base = $this->build->getObjDir() . DIRECTORY_SEPARATOR . $sub;
		$target = TMP_DIR . DIRECTORY_SEPARATOR . $this->getPackageName();
		$files_to_zip = array();

		$ext_names = $this->getMultiExtensionNames();

		$ext_dll_found = false;
		foreach ($ext_names as $ext_name) {
			$dll_name = 'php_' . $ext_name . '.dll';
			$dll_file = $target . DIRECTORY_SEPARATOR . $dll_name;
			if (!file_exists($base . DIRECTORY_SEPARATOR . $dll_name)) {
				//throw new \Exception("'$dll_name' doesn't exist after build, build failed");
				continue;
			}
			$ext_dll_found = true;
			if (!copy($base . DIRECTORY_SEPARATOR . $dll_name, $dll_file)) {
				throw new \Exception("Couldn't copy '$dll_name' into '$target'");
			}
			$files_to_zip[] = $dll_file;
			
			$pdb_name = 'php_' . $ext_name . '.pdb';
			$pdb_file = $target . DIRECTORY_SEPARATOR . $pdb_name;
			if (!file_exists($base . DIRECTORY_SEPARATOR . $pdb_name)) {
				throw new \Exception("'$pdb_name' doesn't exist after build");
			}
			if (!copy($base . DIRECTORY_SEPARATOR . $pdb_name, $pdb_file)) {
				throw new \Exception("Couldn't copy '$pdb_name' into '$target'");
			}
			$files_to_zip[] = $pdb_file;

			/* get all the dep dlls recursive */
			$files_to_zip = array_merge($this->prepareAllDepDlls($dll_file, $target), $files_to_zip);
		}

		if (!$ext_dll_found) {
			$msg = "No DLL for " . implode(',', $ext_names) . " was found, build failed";
			throw new \Exception($msg);
		}

		/* care about extension license */
		 $files_to_zip = array_merge(
		 	$files_to_zip,
			$this->prepareExtLicense($this->tmp_extract_path, $target, "php." . $this->name)
		);

		/* pack */
		$zip_file = TMP_DIR . DIRECTORY_SEPARATOR . $this->getPackageName() . '.zip';
		$zip_cmd = $this->zip_cmd . ' -9 -D -j ' . $zip_file . ' ' . implode(' ', $files_to_zip);
		system($zip_cmd, $status);
		if ($status) {
			throw new \Exception("Couldn't zip files for $zip_file");
		}

		return $zip_file;
	}

	public function __call($name, $args)
	{
		if ('get' == substr($name, 0, 3)) {
			$prop_name = strtolower(substr($name, 3));
			if (isset($this->$prop_name)) {
				return $this->$prop_name;
			} else {
				return NULL;
			}
		}

		throw new \Exception("Unknown dynamic method called");
	}

	public function cleanup($flag0 = false)
	{
		if ($this->tmp_extract_path) {
			$path = dirname($this->tmp_extract_path);
			/* Do not delete TMP_DIR */
			if (strtolower(realpath(TMP_DIR)) == strtolower(realpath($path))) {
				$path = $this->tmp_extract_path;
			}
			rmdir_rf($path);
		}

		if ($this->ext_dir_in_src_path) {
			rmdir_rf($this->ext_dir_in_src_path);
		}


		$ext_pack = TMP_DIR . DIRECTORY_SEPARATOR . $this->getPackageName() . '.zip';
		if ($flag0 && file_exists($ext_pack)) {
			unlink($ext_pack);
		}

		$log_pack = TMP_DIR . DIRECTORY_SEPARATOR . $this->getPackageName() . '-logs' . '.zip';
		if ($flag0 && file_exists($log_pack)) {
			unlink($log_pack);
		}

		$this->cleanupNonCoreExtDeps();
	}

	public function checkSkipBuild()
	{
		$this->unpack();

		if ($this->package_xml) {
			$oses = $this->getPackageXmlProperty("dependencies", "required", "os");
			if ($oses) {
				foreach($oses as $os) {
					if ("windows" == (string)$os->name) {
						if (isset($os->conflicts)) {
							throw new \EXception("Per package.xml not compatible with Windows");
						}
					}
				}
			}

		}
	}

	public function check()
	{
		if (!$this->tmp_extract_path) {
			throw new \Exception("Tarball isn't yet extracted");
		}

		if (!file_exists($this->tmp_extract_path . DIRECTORY_SEPARATOR . 'config.w32')) {
			$this->cleanup();
			throw new \Exception("config.w32 doesn't exist in the tarball");
		}

		if ($this->package_xml) {
			$min_php_ver = (string)$this->getPackageXmlProperty("dependencies", "required", "php", "min");
			$max_php_ver = (string)$this->getPackageXmlProperty("dependencies", "required", "php", "max");
			$php_ver = '';

			$ver_hdr = $this->build->getSourceDir() . '/main/php_version.h';
			if(preg_match(',#define PHP_VERSION "(.*)",Sm', file_get_contents($ver_hdr), $m)) {
				$php_ver = $m[1];
			} else {
				$this->cleanup();
				throw new \Exception("Couldn't parse PHP sources for version");
			}

			if ($min_php_ver && version_compare($php_ver, $min_php_ver) < 0) {
				$this->cleanup();
				throw new \Exception("At least PHP '$min_php_ver' required, got '$php_ver'");
			}

			if ($max_php_ver && version_compare($php_ver, $max_php_ver) >= 0) {
				$this->cleanup();
				throw new \Exception("At most PHP '$max_php_ver' required, got '$php_ver'");
			}


		}

	}

	public function packLogs(array $logs)
	{
		foreach($logs as $k => $log) {
			if (!$log || !file_exists($log) || !is_file($log) || !is_readable($log)) {
				unset($logs[$k]);
			}
		}

		$zip_file = TMP_DIR . DIRECTORY_SEPARATOR . $this->getPackageName() . '-logs' . '.zip';
		$zip_cmd = $this->zip_cmd . ' -9 -D -j ' . $zip_file . ' ' . implode(' ', $logs);
		system($zip_cmd, $status);
		if ($status) {
			throw new \Exception("Couldn't zip logs for $zip_file, cmd was '$zip_cmd'");
		}

		return $zip_file;
	}

	public function getToEmail()
	{
		$to = NULL;
		$config = $this->getPackageConfig();

		/* override package.xml */
		if ($config) {
			if (isset($config['no_mail'])) {
				return NULL;
			}

			if (isset($config['mailto']) && $config['mailto']) {
				return $config['mailto'];
			}
		}

		$leads = $this->getPackageXmlProperty("lead");
		foreach ($leads as $lead) {
			if ((string)$lead->active == 'yes') {
				$to = (string)$lead->email;
				break;
			}
		}

		return $to;
	}

	public function mailMaintainers($success, $is_snap, array $logs, PeclMail $mailer, $force_email = NULL)
	{
		$seg = $is_snap ? 'snaps' : 'releases';
		$url = 'http://windows.php.net/downloads/pecl/' . $seg . '/' . $this->name . '/' . $this->version . '/';

		if ($mailer->isAggregated()) {
			/* NOTE we're not able to send all the build logs in an aggregated mail right now.
			   But that's fine, they are uploaded anyway. */
			if ($success) {
				$msg = $this->getPackageName() . " succeeded\n";
			} else {
				$msg = $this->getPackageName() . " failed\n";
			}
		} else {
			if ($success) {
				$msg = "PECL Windows build for " . $this->getPackageName() . " succeeded\n\n";
				/* $msg .= "The package was uploaded to $url/" . $this->getPackageName() . ".zip\n\n";*/
				$msg .= "The package was uploaded to $url/\n\n";
			} else {
				$msg = "PECL Windows build for " . $this->getPackageName() . " failed\n\n";
			}
			/* No need to link the logs as we attach them. */
			/* $msg .= "The logs was uploaded to $url/logs/" . $this->getPackageName() . "-logs.zip\n\n"; */
			if (!$success) {
				$msg .= "Please look into the logs for what's to be fixed. ";
				$msg .= "You can ask for help on pecl-dev@lists.php.net or internals-win@lists.php.net. \n\n";
			}
			$msg .= "Have a nice day :)\n";
		}

		$to = $force_email ? $force_email : $this->getToEmail();

		return $mailer->xmail(
			MAIL_FROM,
			$to,
			'[PECL-DEV] Windows build: ' . $this->getPackageName(),
			$msg,
			$logs
		);

	}

	protected function getPackageXmlProperty()
	{
		if (!$this->package_xml) {
			return NULL;
		}

		$list = func_get_args();
		$last = func_get_arg(func_num_args()-1);

		$current = $this->package_xml;
		foreach ($list as $prop) {
			if (!isset($current->$prop)) {
				return NULL;
			}

			if ($prop == $last) {
				return $current->$prop;
			}

			/* if the $prop isn't an object and isn't last,
			no way to iterate the remaining chain*/
			if (is_object($current->$prop)) {
				$current = $current->$prop;
			} else {
				return NULL;
			}
		}
	}

	public function setupNonCoreExtDeps()
	{
		$config = $this->getPackageConfig();
		if (!$config) {
			/* XXX read non core ext deps from the package.xml maybe? */
			return;
		}

		if (!isset($config['exts']) || !is_array($config['exts'])) {
			return;
		}

		$path = $this->build->branch->config->getPeclNonCoreExtDepsBase();

		foreach($config["exts"] as $name) {
			if (!$name) {
				continue;
			}

			$pkgs = glob("$path/*");

			if (!$pkgs) {
				continue;
			}

			foreach ($pkgs as $pkg) {
				$ext = new PeclExt($pkg, $this->build);
				$ext->init();

				if (strtolower($ext->getName()) == strtolower($name)
					&& !isset($this->non_core_ext_deps[$ext->getName()])
					/* Avoid an ext having itself as dep */
					&& strtolower($ext->getName()) != strtolower($this->name)) {

					$ext->setupNonCoreExtDeps();
					$ext->putSourcesIntoBranch();

					$this->non_core_ext_deps[$ext->getName()] = $ext;
				} else {
					$ext->cleanup();
					unset($ext);
				}
			}
		}


		return $this->non_core_ext_deps;
	}

	/* the simple variant, all the usual non core exts will be built on each run. */
	/*public function setupNonCoreExtDeps()
	{
		$path = $this->build->branch->config->getPeclNonCoreExtDepsBase();

		$pkgs = glob("$path/*");

		foreach($pkgs as $pkg) {
			$ext = new PeclExt($pkg, $this->build);

			if ($ext->getName() != $this->name) {
				$ext->putSourcesIntoBranch();

				$this->non_core_ext_deps[$ext->getName()] = $ext;
			} else {
				$ext->cleanup();
				unset($ext);
			}
		}
	}*/

	public function getNonCoreExtDepsConfLines()
	{
		$ret = array();

		foreach ($this->non_core_ext_deps as $ext) {
			$ret[] = $ext->getConfigureLine();
		}
	
		return implode(' ', $ret);
	}


	protected function cleanupNonCoreExtDeps()
	{
		foreach ($this->non_core_ext_deps as $name => &$ext) {
			$ext->cleanup();
			unset($ext);
		}
	}

	/* ignore me */
	public function sendToCoventry()
	{
		$config = $this->getPackageConfig();

		return $config && isset($config['ignore']);
	}
}

