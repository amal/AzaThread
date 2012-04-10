<?php

namespace Aza\Components\Autoloader;

/**
 * Implements a "universal" autoloader for PHP
 *
 * It is able to load classes that use either:
 *
 *  * PSR-0 Standart. The technical interoperability standards for PHP 5.3 namespaces and
 *    class names (https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-0.md);
 *
 *  * The PEAR naming convention for classes (http://pear.php.net/).
 *
 *  * Pre-registered classes map
 *
 * @project Anizoptera CMF
 * @package system.AzaAutoloader
 * @version $Id: UniversalClassLoader.php 3252 2012-04-09 21:53:00Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class UniversalClassLoader
{
	/**
	 * Class map.
	 * class => file path
	 */
	private $map = array();

	/**
	 * Registered namespaces.
	 * namespace => array(directories)
	 */
	private $namespaces = array();

	/**
	 * Registered fallback directories for namespaces classes.
	 */
	private $namespaceFallbacks = array();

	/**
	 * Registered prefixes for the PEAR naming convention.
	 * prefix => array(directories)
	 */
	private $prefixes = array();

	/**
	 * Registered fallback directories the PEAR naming convention.
	 */
	private $prefixFallbacks = array();

	/**
	 * Whether to use include path.
	 */
	private $useIncludePath = true;


	/**
	 * Turns on searching the include for class files. Allows easy loading
	 * of installed PEAR packages
	 *
	 * @param bool $useIncludePath
	 */
	public function setUseIncludePath($useIncludePath)
	{
		$this->useIncludePath = $useIncludePath;
	}

	/**
	 * Can be used to check if the autoloader uses the include path to check
	 * for classes.
	 *
	 * @return bool
	 */
	public function getUseIncludePath()
	{
		return $this->useIncludePath;
	}


	/**
	 * Sets the class map
	 *
	 * @param array $classes
	 */
	public function setMap($classes)
	{
		$this->map = $classes;
	}

	/**
	 * Adds classes to the the map
	 *
	 * @param array $classes
	 */
	public function addToMap($classes)
	{
		$this->map = $classes + $this->map;
	}

	/**
	 * Returns the class map
	 *
	 * @return array
	 */
	public function getMap()
	{
		return $this->map;
	}


	/**
	 * Gets the configured namespaces.
	 *
	 * @return array A hash with namespaces as keys and directories as values
	 */
	public function getNamespaces()
	{
		return $this->namespaces;
	}

	/**
	 * Gets the configured class prefixes.
	 *
	 * @return array A hash with class prefixes as keys and directories as values
	 */
	public function getPrefixes()
	{
		return $this->prefixes;
	}

	/**
	 * Gets the directory(ies) to use as a fallback for namespaces.
	 *
	 * @return array An array of directories
	 */
	public function getNamespaceFallbacks()
	{
		return $this->namespaceFallbacks;
	}

	/**
	 * Gets the directory(ies) to use as a fallback for class prefixes.
	 *
	 * @return array An array of directories
	 */
	public function getPrefixFallbacks()
	{
		return $this->prefixFallbacks;
	}


	/**
	 * Registers an array of namespaces
	 *
	 * @param array $namespaces An array of namespaces (namespaces as keys and locations as values)
	 */
	public function registerNamespaces($namespaces)
	{
		foreach ($namespaces as $namespace => $locations) {
			$this->namespaces[$namespace] = (array)$locations;
		}
	}

	/**
	 * Registers a namespace.
	 *
	 * @param string       $namespace The namespace
	 * @param array|string $paths     The location(s) of the namespace
	 */
	public function registerNamespace($namespace, $paths)
	{
		$this->namespaces[$namespace] = (array)$paths;
	}

	/**
	 * Registers the directory to use as a fallback for namespaces.
	 *
	 * @param array $dirs An array of directories
	 */
	public function registerNamespaceFallbacks($dirs)
	{
		$this->namespaceFallbacks = $dirs;
	}


	/**
	 * Registers an array of classes using the PEAR naming convention.
	 *
	 * @param array $classes An array of classes (prefixes as keys and locations as values)
	 */
	public function registerPrefixes($classes)
	{
		foreach ($classes as $prefix => $locations) {
			$this->prefixes[$prefix] = (array)$locations;
		}
	}

	/**
	 * Registers a set of classes using the PEAR naming convention.
	 *
	 * @param string       $prefix  The classes prefix
	 * @param array|string $paths   The location(s) of the classes
	 */
	public function registerPrefix($prefix, $paths)
	{
		$this->prefixes[$prefix] = (array)$paths;
	}

	/**
	 * Registers the directory to use as a fallback for class prefixes.
	 *
	 * @param array $dirs An array of directories
	 */
	public function registerPrefixFallbacks($dirs)
	{
		$this->prefixFallbacks = $dirs;
	}


	/**
	 * Registers this instance as an autoloader.
	 *
	 * @param bool $prepend Whether to prepend the autoloader or not
	 */
	public function register($prepend = false)
	{
		spl_autoload_register(array($this, 'loadClass'), true, $prepend);
	}


	/**
	 * Loads the given class or interface.
	 *
	 * @param string $class The name of the class
	 *
	 * @return bool
	 */
	public function loadClass($class)
	{
		/** @noinspection PhpIncludeInspection */
		return ($file = $this->findFile($class)) && require_once $file;
	}

	/**
	 * Finds the path to the file where the class is defined.
	 *
	 * @param string $class The name of the class
	 *
	 * @return string|bool The path, if found, FALSE otherwise.
	 */
	public function findFile($class)
	{
		// Trim first slash
		$class[0] === ($ns = '\\') && $class = substr($class, 1);

		// Class is registered in the map
		if (isset($this->map[$class])) {
			return $this->map[$class];
		}

		// Namespaced class name
		$ext = '.php';
		if ($pos = strrpos($class, $ns)) {
			$namespace       = substr($class, 0, $pos);
			$class           = substr($class, $pos + 1);
			$normalizedClass = strtr($class, '_', DIRECTORY_SEPARATOR) . $ext;

			// Iterate over NS parts to find registered paths
			$last = false;
			$path = '';
			$pos  = 0;
			do {
				if (false === $pos = strpos($namespace, $ns, $pos)) {
					$rootNS = $namespace;
					$last   = true;
				} else {
					$rootNS = substr($namespace, 0, $pos);
				}
				// Check in the registered namespaces
				if (isset($this->namespaces[$rootNS])) {
					$_path = $last
							? $normalizedClass
							: strtr(substr($namespace, $pos + 1), $ns, DIRECTORY_SEPARATOR)
							  . DIRECTORY_SEPARATOR . $normalizedClass;
					foreach ($this->namespaces[$rootNS] as $filePath) {
						if (file_exists($filePath .= $_path)) {
							return $filePath;
						}
					}
					if ($last) {
						break;
					}
				} elseif ($last) {
					$path = strtr($namespace, $ns, DIRECTORY_SEPARATOR)
							. DIRECTORY_SEPARATOR . $normalizedClass;
					break;
				}
				$pos++;
			} while (true);

			// Check fallbacks
			foreach ($this->namespaceFallbacks as $filePath) {
				if (file_exists($filePath .= DIRECTORY_SEPARATOR . $path)) {
					return $filePath;
				}
			}
		}

		// PEAR naming convention
		else {
			// Check in the registered prefixes
			$normalizedClass = strtr($class, '_', DIRECTORY_SEPARATOR) . $ext;
			foreach ($this->prefixes as $prefix => $dirs) {
				if (!strncmp($class, $prefix, strlen($prefix))) {
					continue;
				}
				foreach ($dirs as $filePath) {
					if (file_exists($filePath .= DIRECTORY_SEPARATOR . $normalizedClass)) {
						return $filePath;
					}
				}
			}

			// Check fallbacks
			$path = $normalizedClass;
			foreach ($this->prefixFallbacks as $filePath) {
				if (file_exists($filePath .= DIRECTORY_SEPARATOR . $normalizedClass)) {
					return $filePath;
				}
			}
		}

		// Last chance - include path
		return ($path && $this->useIncludePath && $path = stream_resolve_include_path($path))
				? $path
				: false;
	}
}
